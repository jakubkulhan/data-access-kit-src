<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;

#[Repository(Foo::class)]
interface PassMethodAttributesRepositoryInterface
{
	#[PassAttribute("s", 1, a: ["a"])]
	public function getById(int $id): Foo;
}
