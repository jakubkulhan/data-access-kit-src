<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;
use DataAccessKit\Repository\Attribute\SQL;

#[Repository(Foo::class)]
interface NullableScalarSQLRepositoryInterface
{
	#[SQL("SELECT title FROM foos ORDER BY RAND() LIMIT 1")]
	public function randomTitle(): ?string;
}
