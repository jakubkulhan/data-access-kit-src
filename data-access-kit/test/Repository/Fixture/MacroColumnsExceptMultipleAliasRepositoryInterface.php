<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;
use DataAccessKit\Repository\Attribute\SQL;

#[Repository(Foo::class)]
interface MacroColumnsExceptMultipleAliasRepositoryInterface
{
	#[SQL("SELECT %columns(except title, description alias f) FROM foos f")]
	public function allColumnsExceptMultipleAlias(): iterable;
}
