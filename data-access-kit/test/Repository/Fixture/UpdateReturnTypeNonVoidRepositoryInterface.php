<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;

#[Repository(Foo::class)]
interface UpdateReturnTypeNonVoidRepositoryInterface
{
	public function update(Foo $foo): int;
}
