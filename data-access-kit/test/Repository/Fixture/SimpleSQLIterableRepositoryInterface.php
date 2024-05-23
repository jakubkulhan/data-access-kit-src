<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;
use DataAccessKit\Repository\Attribute\SQL;

#[Repository(Foo::class)]
interface SimpleSQLIterableRepositoryInterface
{
	#[SQL("SELECT id, title FROM foos WHERE title = ''")]
	public function findEmptyTitle(): iterable;
}
