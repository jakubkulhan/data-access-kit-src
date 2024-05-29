<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;
use DataAccessKit\Repository\Attribute\Update;

#[Repository(Foo::class)]
interface UpdateRepositoryInterface
{
	public function update(Foo $foo): void;
	#[Update(["title"])]
	public function updateTitleOnly(Foo $foo): void;
}
