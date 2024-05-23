<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;

#[Repository(Foo::class)]
interface FindIterableRepositoryInterface
{
	public function findByTitle(string $title): iterable;
}
