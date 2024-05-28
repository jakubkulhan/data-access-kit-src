<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;

#[Repository(Foo::class)]
interface CountBadReturnTypeRepositoryInterface
{
	public function countByTitle(string $title): Foo;
}