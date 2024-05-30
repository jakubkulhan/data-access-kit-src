<?php declare(strict_types=1);

namespace DataAccessKit\Symfony;

use DataAccessKit\PersistenceInterface;
use LogicException;

class DummyPersistence implements PersistenceInterface
{
	public function select(string $className, string $sql, array $parameters = []): iterable
	{
		throw new LogicException("Not implemented");
	}

	public function selectScalar(string $sql, array $parameters = []): mixed
	{
		throw new LogicException("Not implemented");
	}

	public function execute(string $sql, array $parameters = []): int
	{
		throw new LogicException("Not implemented");
	}

	public function insert(object|array $data): void
	{
		throw new LogicException("Not implemented");
	}

	public function upsert(object|array $data, ?array $columns = null): void
	{
		throw new LogicException("Not implemented");
	}

	public function update(object|array $data, ?array $columns = null): void
	{
		throw new LogicException("Not implemented");
	}

	public function delete(object|array $data): void
	{
		throw new LogicException("Not implemented");
	}

	public function toRow(object $object): array
	{
		throw new LogicException("Not implemented");
	}
}
