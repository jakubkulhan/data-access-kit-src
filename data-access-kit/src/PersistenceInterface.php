<?php declare(strict_types=1);

namespace DataAccessKit;

interface PersistenceInterface
{
	/**
	 * Run SELECT $sql with $parameters and return an iterable of objects of type $className.
	 *
	 * @template T
	 * @param class-string<T> $className
	 * @param string $sql
	 * @param array<int, mixed>|array<string, mixed> $parameters
	 * @return iterable<T>
	 */
	public function select(string $className, string $sql, array $parameters = []): iterable;

	/**
	 * Run SELECT $sql with $parameters and return a scalar value.
	 *
	 * @param string $sql
	 * @param array<int|string, mixed> $parameters
	 * @return scalar
	 */
	public function selectScalar(string $sql, array $parameters = []): mixed;

	/**
	 * Run INSERT, UPDATE, or DELETE $sql with $parameters and return the number of affected rows.
	 *
	 * @param string $sql
	 * @param array<int|string, mixed> $parameters
	 * @return int
	 */
	public function execute(string $sql, array $parameters = []): int;

	/**
	 * Insert data into the database.
	 *
	 * @param object|array<string, mixed> $data
	 */
	public function insert(object|array $data): void;

	/**
	 * Insert or update data in the database.
	 *
	 * @param object|array<string, mixed> $data
	 * @param array<string>|null $columns
	 */
	public function upsert(object|array $data, ?array $columns = null): void;

	/**
	 * Update data in the database based on its primary key.
	 *
	 * @param object $data
	 * @param array<string>|null $columns
	 */
	public function update(object $data, ?array $columns = null): void;

	/**
	 * Delete data from the database based on its primary key.
	 *
	 * @param object|array<string, mixed> $data
	 */
	public function delete(object|array $data): void;

	/**
	 * Convert $object to an associative array.
	 *
	 * @param object $object
	 * @return array<string, mixed>
	 */
	public function toRow(object $object): array;
}
