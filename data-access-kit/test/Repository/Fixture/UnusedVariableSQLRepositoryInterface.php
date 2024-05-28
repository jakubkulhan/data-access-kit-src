<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;
use DataAccessKit\Repository\Attribute\SQL;

#[Repository(Foo::class)]
interface UnusedVariableSQLRepositoryInterface
{
	#[SQL("SELECT * FROM foos")]
	public function unusedVariable(string $foo): iterable;
}
