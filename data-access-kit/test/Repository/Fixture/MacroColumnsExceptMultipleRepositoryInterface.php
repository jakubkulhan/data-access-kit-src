<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;
use DataAccessKit\Repository\Attribute\SQL;

#[Repository(Foo::class)]
interface MacroColumnsExceptMultipleRepositoryInterface
{
	#[SQL("SELECT %columns(except title, description) FROM foos")]
	public function allColumnsExceptMultiple(): iterable;
}
