<?php declare(strict_types=1);

namespace DataAccessKit\Symfony\Fixture;

use DataAccessKit\PersistenceInterface;
use LogicException;

class DummyPersistence implements PersistenceInterface
{
	public function query(string $className, string $sql, array $parameters = [], array $parameterTypes = []): iterable
	{
		throw new LogicException("Not implemented");
	}

	public function select(string $className, string $alias = "t", ?callable $callback = null): iterable
	{
		throw new LogicException("Not implemented");
	}

	public function insert(object $object): void
	{
		throw new LogicException("Not implemented");
	}

	public function insertAll(array $objects): void
	{
		throw new LogicException("Not implemented");
	}

	public function upsert(object $object, ?array $columns = null): void
	{
		throw new LogicException("Not implemented");
	}

	public function upsertAll(array $objects, ?array $columns = null): void
	{
		throw new LogicException("Not implemented");
	}

	public function update(object $object, ?array $columns = null): void
	{
		throw new LogicException("Not implemented");
	}

	public function delete(object $object): void
	{
		throw new LogicException("Not implemented");
	}

	public function deleteAll(array $objects): void
	{
		throw new LogicException("Not implemented");
	}

	public function transactional(callable $callback): mixed
	{
		throw new LogicException("Not implemented");
	}

	public function toRow(object $object): array
	{
		throw new LogicException("Not implemented");
	}
}
