<?php declare(strict_types=1);

namespace DataAccessKit\Symfony\Fixture\Default;

use DataAccessKit\Repository\Attribute\Delegate;
use DataAccessKit\Repository\Attribute\Repository;
use DataAccessKit\Repository\Attribute\SQL;

#[Repository(Foo::class)]
interface FooRepositoryInterface
{
	public function getById(int $id): Foo;

	public function findByTitle(string $title): array;

	#[SQL("SELECT id, title FROM foos WHERE title = @title AND id != @id")]
	public function findByTitleExcludingId(string $title, int $id): array;

	#[Delegate(FooRepositoryTrait::class)]
	public function insert(Foo $foo): void;
}
