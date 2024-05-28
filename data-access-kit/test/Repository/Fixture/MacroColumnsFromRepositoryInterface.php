<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;
use DataAccessKit\Repository\Attribute\SQL;

#[Repository(Foo::class)]
interface MacroColumnsFromRepositoryInterface
{
	#[SQL("SELECT %columns(from f) FROM foos f")]
	public function allColumnsFrom(): iterable;
}
