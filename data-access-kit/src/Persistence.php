<?php declare(strict_types=1);

namespace DataAccessKit;

use DataAccessKit\Attribute\Column;
use DataAccessKit\Exception\PersistenceException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use function array_fill;
use function array_map;
use function array_merge;
use function count;
use function implode;
use function in_array;
use function is_array;
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
			foreach ($row as $columnName => $value) {
				$column = $table->columns[$columnName];
				$column->reflection->setValue(
					$object,
					$this->valueConverter->databaseToObject($table, $column, $value),
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

	public function insert(object|array $data): void
	{
		if (!is_array($data)) {
			$data = [$data];
		}
		$this->doInsert($data, []);
	}

	public function upsert(object|array $data, ?array $columns = null): void
	{
		if ($columns === []) {
			throw new InvalidArgumentException("Columns cannot be empty array. Either pass null to update all columns, or pass array of column names to update specific columns.");
		}

		if (!is_array($data)) {
			$data = [$data];
		}
		$this->doInsert($data, $columns);
	}

	private function doInsert(array $objects, ?array $upsertColumns = null): void
	{
		if (count($objects) === 0) {
			return;
		}

		$table = $this->registry->get($objects[0], true);
		$platform = $this->connection->getDatabasePlatform();

		/** @var Column[] $primaryColumns */
		$primaryColumns = [];
		$primaryGeneratedCount = 0;
		/** @var Column[] $insertColumns */
		$insertColumns = [];
		/** @var Column[] $updateColumns */
		$updateColumns = [];
		/** @var Column[] $returningColumns */
		$returningColumns = [];

		foreach ($table->columns as $column) {
			if ($column->primary) {
				$primaryColumns[] = $column;

				if (!$column->generated || $upsertColumns !== []) {
					$insertColumns[] = $column;
				}

				if ($column->generated) {
					$primaryGeneratedCount++;
					$returningColumns[] = $column;
				}

			} else if ($column->generated) {
				$returningColumns[] = $column;

			} else {
				$insertColumns[] = $column;

				if ($upsertColumns === null || in_array($column->name, $upsertColumns, true)) {
					$updateColumns[] = $column;
				}
			}
		}

		if (count($primaryColumns) > 1 && $primaryGeneratedCount > 0) {
			throw new PersistenceException(sprintf(
				"Multi-column primary key with generated column is not supported. Check column definitions of class [%s].",
				$table->reflection->getName(),
			));
		}

		$supportsReturning = match (true) {
			$platform instanceof MariaDBPlatform => true,
			$platform instanceof PostgreSQLPlatform => true,
			$platform instanceof SQLitePlatform => true,
			default => false,
		};
		if (count($returningColumns) > 0 && count($objects) > 1 && $upsertColumns === [] && !$supportsReturning) {
			throw new PersistenceException(sprintf(
				"Database platform [%s] does not support INSERT ... RETURNING statement, cannot insert multiple rows with generated columns. Either insert one row at a time, or generate primary key in application code and use upsert() method.",
				(new ReflectionClass($platform))->getShortName(),
			));
		}

		$supportsUpsert = match (true) {
			$platform instanceof AbstractMySQLPlatform => true,
			$platform instanceof PostgreSQLPlatform => true,
			$platform instanceof SQLitePlatform => true,
			default => false,
		};
		if (count($updateColumns) > 0 && !$supportsUpsert) {
			throw new PersistenceException(sprintf(
				"Database platform [%s] does not support upsert, cannot update columns.",
				(new ReflectionClass($platform))->getShortName(),
			));
		}

		$rows = "";
		$values = [];
		foreach ($objects as $index => $object) {
			$row = "";

			foreach ($insertColumns as $column) {
				if ($column->reflection->isInitialized($object)) {
					if ($row !== "") {
						$row .= ", ";
					}
					$row .= "?";
					$values[] = $this->valueConverter->objectToDatabase($table, $column, $column->reflection->getValue($object));

				} else {
					throw new PersistenceException(sprintf(
						"Property [%s] of object at index [%d] not initialized.",
						$column->reflection->getName(),
						$index,
					));
				}
			}

			if ($rows !== "") {
				$rows .= ", ";
			}
			$rows .= "(" . $row . ")";
		}

		$sql = sprintf(
			"INSERT INTO %s (%s) VALUES %s%s%s",
			$platform->quoteSingleIdentifier($table->name),
			implode(", ", array_map(fn(Column $it) => $platform->quoteSingleIdentifier($it->name), $insertColumns)),
			$rows,
			match (true) {
				count($updateColumns) > 0 && $platform instanceof AbstractMySQLPlatform => sprintf(
					" ON DUPLICATE KEY UPDATE %s",
					implode(", ", array_map(fn(Column $it) => $platform->quoteSingleIdentifier($it->name) . " = VALUES(" . $platform->quoteSingleIdentifier($it->name) . ")", $updateColumns)),
				),
				count($updateColumns) > 0 && ($platform instanceof PostgreSQLPlatform || $platform instanceof SQLitePlatform) => sprintf(
					" ON CONFLICT (%s) DO UPDATE SET %s",
					implode(", ", array_map(fn(Column $it) => $platform->quoteSingleIdentifier($it->name), $primaryColumns)),
					implode(", ", array_map(fn(Column $it) => $platform->quoteSingleIdentifier($it->name) . " = EXCLUDED." . $platform->quoteSingleIdentifier($it->name), $updateColumns)),
				),
				count($updateColumns) > 0 => throw new LogicException("Unreachable statement."),
				default => "",
			},
			match (count($returningColumns) > 0 && $supportsReturning) {
				true => sprintf(" RETURNING %s", implode(", ", array_map(fn(Column $it) => $platform->quoteSingleIdentifier($it->name), $returningColumns))),
				default => "",
			},
		);
		$result = $this->connection->executeQuery($sql, $values);

		if (count($returningColumns) > 0 && $supportsReturning) {
			foreach ($result->iterateAssociative() as $index => $row) {
				$object = $objects[$index];
				foreach ($returningColumns as $column) {
					$column->reflection->setValue(
						$object,
						$this->valueConverter->databaseToObject($table, $column, $row[$column->name]),
					);
				}
			}
		} else if ($primaryGeneratedCount > 0 && $upsertColumns === []) {
			$primaryColumns[0]->reflection->setValue($objects[0], $this->connection->lastInsertId());
		}

		if (count($returningColumns) > 0 && count($returningColumns) > $primaryGeneratedCount && !$supportsReturning) {
			$values = [];
			foreach ($objects as $object) {
				foreach ($primaryColumns as $column) {
					$values[] = $this->valueConverter->objectToDatabase($table, $column, $column->reflection->getValue($object));
				}
			}
			$result = $this->connection->executeQuery(
				sprintf(
					"SELECT %s FROM %s WHERE (%s) IN (%s)",
					implode(", ", array_map(fn(Column $it) => $platform->quoteSingleIdentifier($it->name), $returningColumns)),
					$platform->quoteSingleIdentifier($table->name),
					implode(", ", array_map(fn(Column $it) => $platform->quoteSingleIdentifier($it->name), $primaryColumns)),
					implode(", ", array_fill(0, count($objects), "(" . implode(", ", array_fill(0, count($primaryColumns), "?")) . ")")),
				),
				$values,
			);
			foreach ($result->iterateAssociative() as $index => $row) {
				$object = $objects[$index];
				foreach ($returningColumns as $column) {
					$column->reflection->setValue(
						$object,
						$this->valueConverter->databaseToObject($table, $column, $row[$column->name]),
					);
				}
			}
		}
	}

	public function update(object $data, ?array $columns = null): void
	{
		$table = $this->registry->get($data, true);
		$platform = $this->connection->getDatabasePlatform();

		$set = [];
		$setValues = [];
		$where = [];
		$whereValues = [];

		foreach ($table->columns as $column) {
			if ($column->reflection->isInitialized($data)) {
				$value = $this->valueConverter->objectToDatabase($table, $column, $column->reflection->getValue($data));

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

	public function delete(object|array $data): void
	{
		if (!is_array($data)) {
			$data = [$data];
		}

		if (count($data) === 0) {
			return;
		}

		$table = $this->registry->get($data[0], true);
		$platform = $this->connection->getDatabasePlatform();

		$where = [];
		$values = [];

		$primaryColumns = [];
		foreach ($table->columns as $column) {
			if ($column->primary) {
				$primaryColumns[] = $column;
			}
		}

		foreach ($data as $object) {
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
