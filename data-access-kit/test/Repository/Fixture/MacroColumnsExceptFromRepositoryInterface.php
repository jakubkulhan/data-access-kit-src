<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;
use DataAccessKit\Repository\Attribute\SQL;

#[Repository(Foo::class)]
interface MacroColumnsExceptFromRepositoryInterface
{
	#[SQL("SELECT %columns(except description from f) FROM foos f")]
	public function allColumnsExceptFrom(): iterable;
}
