<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;
use DataAccessKit\Repository\Attribute\SQL;

#[Repository(Foo::class)]
interface MacroColumnsExceptUnknownColumnRepositoryInterface
{
	#[SQL("SELECT %columns(except non_existent_column) FROM foos")]
	public function allColumnsExcept(): iterable;
}
