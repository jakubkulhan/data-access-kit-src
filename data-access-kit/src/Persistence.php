<?php declare(strict_types=1);

namespace DataAccessKit;

use Doctrine\DBAL\Connection;
use LogicException;
use function array_merge;
use function count;
use function implode;
use function in_array;
use function sprintf;

class Persistence implements PersistenceInterface
{

	public function __construct(
		private readonly Connection $connection,
		private readonly Registry $registry,
		private readonly ValueConverterInterface $valueConverter,
	)
	{
	}

	public function query(string $className, string $sql, array $parameters = [], array $parameterTypes = []): iterable
	{
		$table = $this->registry->get($className);

		$result = $this->connection->executeQuery($sql, $parameters);
		foreach ($result->iterateAssociative() as $row) {
			$object = $table->reflection->newInstanceWithoutConstructor();
			foreach ($table->columns as $column) {
				$column->reflection->setValue(
					$object,
					$this->valueConverter->databaseToObject($table, $column, $row[$column->name]),
				);
			}
			yield $object;
		}
	}

	public function select(string $className, string $alias = "t", ?callable $callback = null): iterable
	{
		$table = $this->registry->get($className, true);
		$platform = $this->connection->getDatabasePlatform();

		$qb = $this->connection->createQueryBuilder();
		$qb->from($table->name, $alias);
		foreach ($table->columns as $column) {
			$qb->addSelect($platform->quoteSingleIdentifier($alias) . "." . $platform->quoteSingleIdentifier($column->name));
		}

		if ($callback !== null) {
			$callback($qb);
		}

		return $this->query($className, $qb->getSQL(), $qb->getParameters(), $qb->getParameterTypes());
	}

	public function insert(object $object): void
	{
		$this->insertUpsertAll([$object], []);
	}

	public function insertAll(array $objects): void
	{
		$this->insertUpsertAll($objects, []);
	}

	public function upsert(object $object, ?array $columns = null): void
	{
		$this->insertUpsertAll([$object], $columns);
	}

	public function upsertAll(array $objects, ?array $columns = null): void
	{
		$this->insertUpsertAll($objects, $columns);
	}

	private function insertUpsertAll(array $objects, ?array $upsertColumns = null): void
	{
		if (count($objects) === 0) {
			return;
		}

		$table = $this->registry->get($objects[0], true);
		$platform = $this->connection->getDatabasePlatform();

		$columns = [];
		$rows = [];
		$values = [];
		$update = [];
		$primaryKey = null;

		foreach ($table->columns as $column) {
			if ($column->generated) {
				if ($column->primary) {
					if ($primaryKey !== null) {
						throw new LogicException("Multiple generated primary columns.");
					}
					$primaryKey = $column;
				}
				continue;
			}

			$columns[] = $platform->quoteSingleIdentifier($column->name);

			if ($upsertColumns === null || in_array($column->name, $upsertColumns, true)) {
				$update[] = $platform->quoteSingleIdentifier($column->name) . " = VALUES(" . $platform->quoteSingleIdentifier($column->name) . ")";
			}
		}

		foreach ($objects as $object) {
			$row = [];

			foreach ($table->columns as $column) {
				if ($column->generated) {
					continue;
				}

				if ($column->reflection->isInitialized($object)) {
					$value = $this->valueConverter->objectToDatabase($table, $column, $column->reflection->getValue($object));

					$row[] = "?";
					$values[] = $value;

				} else {
					$row[] = "DEFAULT";
				}
			}

			$rows[] = "(" . implode(", ", $row) . ")";
		}

		$this->connection->executeStatement(
			sprintf(
				"INSERT INTO %s (%s) VALUES (%s)%s",
				$platform->quoteSingleIdentifier($table->name),
				implode(", ", $columns),
				implode(", ", $rows),
				match (count($update)) {
					0 => "",
					default => sprintf(" ON DUPLICATE KEY UPDATE %s", implode(", ", $update)),
				},
			),
			$values,
		);

		if (count($objects) === 1 && $primaryKey !== null) {
			$primaryKey->reflection->setValue($objects[0], $this->connection->lastInsertId());
		}
	}

	public function update(object $object, ?array $columns = null): void
	{
		$table = $this->registry->get($object, true);
		$platform = $this->connection->getDatabasePlatform();

		$set = [];
		$setValues = [];
		$where = [];
		$whereValues = [];

		foreach ($table->columns as $column) {
			if ($column->reflection->isInitialized($object)) {
				$value = $this->valueConverter->objectToDatabase($table, $column, $column->reflection->getValue($object));

				if ($column->primary) {
					$where[] = $platform->quoteSingleIdentifier($column->name) . " = ?";
					$whereValues[] = $value;
				} else if (!$column->generated && ($columns === null || in_array($column->name, $columns, true))) {
					$set[] = $platform->quoteSingleIdentifier($column->name) . " = ?";
					$setValues[] = $value;
				}


			} else if ($column->primary) {
				throw new LogicException(sprintf("Primary column [%s] not initialized.", $column->name));
			}
		}

		if (count($where) === 0) {
			throw new LogicException("No primary columns, cannot update.");
		}

		if (count($set) === 0) {
			throw new LogicException("Only primary columns, nothing to update.");
		}

		$this->connection->executeStatement(
			sprintf(
				"UPDATE %s SET %s WHERE %s",
				$platform->quoteSingleIdentifier($table->name),
				implode(", ", $set),
				implode(" AND ", $where),
			),
			array_merge($setValues, $whereValues),
		);
	}

	public function delete(object $object): void
	{
		$this->deleteAll([$object]);
	}

	public function deleteAll(array $objects): void
	{
		if (count($objects) === 0) {
			return;
		}

		$table = $this->registry->get($objects[0], true);
		$platform = $this->connection->getDatabasePlatform();

		$where = [];
		$values = [];

		$primaryColumns = [];
		foreach ($table->columns as $column) {
			if ($column->primary) {
				$primaryColumns[] = $column;
			}
		}

		foreach ($objects as $object) {
			$rowWhere = [];
			foreach ($primaryColumns as $column) {
				if (!$column->reflection->isInitialized($object)) {
					throw new LogicException(sprintf("Primary column [%s] not initialized.", $column->name));
				}

				$rowWhere[] = $platform->quoteSingleIdentifier($column->name) . " = ?";
				$values[] = $this->valueConverter->objectToDatabase($table, $column, $column->reflection->getValue($object));
			}
			$where[] = "(" . implode(" AND ", $rowWhere) . ")";
		}

		if (count($where) === 0) {
			throw new LogicException("No primary columns, cannot delete.");
		}

		$this->connection->executeStatement(
			sprintf(
				"DELETE FROM %s WHERE %s",
				$platform->quoteSingleIdentifier($table->name),
				implode(" OR ", $where),
			),
			$values,
		);
	}

	public function transactional(callable $callback): mixed
	{
		try {
			$this->connection->beginTransaction();
			$result = $callback();
			$this->connection->commit();
			return $result;
		} catch (\Throwable $e) {
			$this->connection->rollBack();
			throw $e;
		}
	}

	public function toRow(object $object): array
	{
		$table = $this->registry->get($object);
		$row = [];
		foreach ($table->columns as $column) {
			$row[$column->name] = $this->valueConverter->objectToDatabase($table, $column, $column->reflection->getValue($object));
		}
		return $row;
	}

}
