<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;

#[Repository(Foo::class)]
interface DeleteRepositoryInterface
{
	public function delete(Foo $foo): void;
	public function deleteAll(array $foos): void;
}
