<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Find;
use DataAccessKit\Repository\Attribute\Repository;

#[Repository(Foo::class)]
interface GetForRepositoryInterface
{
	#[Find(for: "UPDATE")]
	public function getByIdForUpdate(int $id): Foo;

	#[Find(for: "UPDATE SKIP LOCKED")]
	public function getByIdForUpdateSkipLocked(int $id): ?Foo;
}
