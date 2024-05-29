<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;

#[Repository(Foo::class)]
interface UpdateRepositoryInterface
{
	public function update(Foo $foo): void;
}
