<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;
use DataAccessKit\Repository\Attribute\SQL;

#[Repository(Foo::class)]
interface UnknownVariableSQLRepositoryInterface
{
	#[SQL("SELECT * FROM foos WHERE title = @tytle")]
	public function findByTitle(string $title): iterable;
}
