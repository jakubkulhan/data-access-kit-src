<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;

#[Repository(Foo::class)]
interface DeleteTooManyParametersRepositoryInterface
{
	public function deleteTwo(Foo $a, Foo $b): void;
}
