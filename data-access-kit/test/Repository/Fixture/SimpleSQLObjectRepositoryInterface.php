<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;
use DataAccessKit\Repository\Attribute\SQL;

#[Repository(Foo::class)]
interface SimpleSQLObjectRepositoryInterface
{
	#[SQL("SELECT id, title FROM foos WHERE id = (SELECT MIN(id) FROM foos)")]
	public function getFirst(): Foo;
}
