<?php declare(strict_types=1);

namespace DataAccessKit;

interface PersistenceInterface
{
	/**
	 * Run $sql with $parameters and return an iterable of objects of type $className.
	 *
	 * @template T
	 * @param class-string<T> $className
	 * @param string $sql
	 * @param array<int, mixed>|array<string, mixed> $parameters
	 * @param array<int, mixed>|array<string, mixed> $parameterTypes
	 * @return iterable<T>
	 */
	public function query(string $className, string $sql, array $parameters = [], array $parameterTypes = []): iterable;

	/**
	 * Create a SELECT query for all columns of given class and return an iterable of objects of type $className.
	 *
	 * @template T
	 * @param class-string<T> $className
	 * @param string $alias
	 * @param callable|null $callback
	 * @return iterable<T>
	 */
	public function select(string $className, string $alias = "t", ?callable $callback = null): iterable;

	/**
	 * Insert $object into the database.
	 */
	public function insert(object $object): void;

	/**
	 * Insert all $objects into the database.
	 *
	 * @template T
	 * @param T[] $objects
	 */
	public function insertAll(array $objects): void;

	/**
	 * Insert or update $object in the database.
	 */
	public function upsert(object $object, ?array $columns = null): void;

	/**
	 * Insert or update all $objects in the database.
	 *
	 * @template T
	 * @param T[] $objects
	 */
	public function upsertAll(array $objects, ?array $columns = null): void;

	/**
	 * Update $object in the database based on its primary key.
	 */
	public function update(object $object, ?array $columns = null): void;

	/**
	 * Delete $object from the database based on its primary key.
	 */
	public function delete(object $object): void;

	/**
	 * Delete all $objects from the database based on their primary keys.
	 *
	 * @template T
	 * @param T[] $objects
	 */
	public function deleteAll(array $objects): void;

	/**
	 * Run $callback in a transaction.
	 *
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	public function transactional(callable $callback): mixed;
}
