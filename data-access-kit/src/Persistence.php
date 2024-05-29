<?php declare(strict_types=1);

namespace DataAccessKit;

use DataAccessKit\Attribute\Column;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use LogicException;
use function array_map;
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

	public function select(string $className, string $sql, array $parameters = []): iterable
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

	public function selectScalar(string $sql, array $parameters = []): mixed
	{
		return $this->connection->executeQuery($sql, $parameters)->fetchOne();
	}

	public function execute(string $sql, array $parameters = []): int
	{
		return $this->connection->executeStatement($sql, $parameters);
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

		$columnNames = [];
		$rows = [];
		$values = [];
		$updateColumnNames = [];
		$generatedColumnNames = [];
		/** @var Column[] $generatedColumns */
		$generatedColumns = [];
		$primaryKeyColumnNames = [];
		/** @var Column|null $primaryKeyColumn */
		$primaryKeyColumn = null;
		$supportsReturning = match (true) {
			$platform instanceof MariaDBPlatform => true,
			$platform instanceof PostgreSQLPlatform => true,
			$platform instanceof SQLitePlatform => true,
			default => false,
		};
		$supportsDefault = match (true) {
			$platform instanceof AbstractMySQLPlatform => true,
			$platform instanceof PostgreSQLPlatform => true,
			default => false,
		};

		foreach ($table->columns as $column) {
			if ($column->primary) {
				if ($upsertColumns !== []) {
					$columnNames[] = $platform->quoteSingleIdentifier($column->name);
				}
				$primaryKeyColumnNames[] = $platform->quoteSingleIdentifier($column->name);

				if ($primaryKeyColumn !== null) {
					throw new LogicException("Multiple generated primary columns.");
				}
				$primaryKeyColumn = $column;

				if ($column->generated && $supportsReturning) {
					$generatedColumnNames[] = $platform->quoteSingleIdentifier($column->name);
					$generatedColumns[] = $column;
				}

			} else if ($column->generated) {
				if ($supportsReturning) {
					$generatedColumnNames[] = $platform->quoteSingleIdentifier($column->name);
					$generatedColumns[] = $column;
				}

			} else {
				$columnNames[] = $platform->quoteSingleIdentifier($column->name);

				if ($upsertColumns === null || in_array($column->name, $upsertColumns, true)) {
					$updateColumnNames[] = $platform->quoteSingleIdentifier($column->name);
				}
			}
		}

		foreach ($objects as $index => $object) {
			$row = [];

			foreach ($table->columns as $column) {
				if ($column->generated && !($column->primary && $upsertColumns !== [])) {
					continue;
				}

				if ($column->reflection->isInitialized($object)) {
					$value = $this->valueConverter->objectToDatabase($table, $column, $column->reflection->getValue($object));

					$row[] = "?";
					$values[] = $value;

				} else if ($supportsDefault && $upsertColumns === []) {
					$row[] = "DEFAULT";

				} else {
					throw new LogicException(sprintf(
						"Property [%s] of object at index [%d] not initialized.",
						$column->reflection->getName(),
						$index,
					));
				}
			}

			$rows[] = "(" . implode(", ", $row) . ")";
		}

		$sql = sprintf(
			"INSERT INTO %s (%s) VALUES %s%s%s",
			$platform->quoteSingleIdentifier($table->name),
			implode(", ", $columnNames),
			implode(", ", $rows),
			match (true) {
				count($updateColumnNames) > 0 && $platform instanceof AbstractMySQLPlatform => sprintf(" ON DUPLICATE KEY UPDATE %s", implode(", ", array_map(fn(string $it) => $it . " = VALUES(" . $it . ")", $updateColumnNames))),
				count($updateColumnNames) > 0 && ($platform instanceof PostgreSQLPlatform || $platform instanceof SQLitePlatform) => sprintf(" ON CONFLICT (%s) DO UPDATE SET %s", implode(", ", $primaryKeyColumnNames), implode(", ", array_map(fn(string $it) => $it . " = EXCLUDED." . $it, $updateColumnNames))),
				count($updateColumnNames) > 0 => throw new LogicException(sprintf(
					"Upsert not supported on platform [%s].",
					get_class($platform),
				)),
				default => "",
			},
			match ($supportsReturning) {
				true => sprintf(" RETURNING %s", implode(", ", $generatedColumnNames)),
				default => "",
			},
		);
		$result = $this->connection->executeQuery($sql, $values);

		if ($supportsReturning && count($generatedColumns) > 0) {
			foreach ($result->iterateAssociative() as $index => $row) {
				$object = $objects[$index];
				foreach ($generatedColumns as $column) {
					$column->reflection->setValue(
						$object,
						$this->valueConverter->databaseToObject($table, $column, $row[$column->name]),
					);
				}
			}
		} else if (count($objects) === 1 && $primaryKeyColumn !== null) {
			$primaryKeyColumn->reflection->setValue($objects[0], $this->connection->lastInsertId());
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
