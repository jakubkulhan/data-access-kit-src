<?php declare(strict_types=1);

namespace DataAccessKit\Symfony\Fixture\Default;

use DataAccessKit\PersistenceInterface;

trait FooRepositoryTrait
{
	public function __construct(
		private readonly PersistenceInterface $persistence,
	)
	{
	}

	public function insert(Foo $foo): void
	{
		$this->persistence->insert($foo);
	}
}
