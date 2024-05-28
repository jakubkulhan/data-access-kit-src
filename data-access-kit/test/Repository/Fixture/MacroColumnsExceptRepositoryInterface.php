<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;
use DataAccessKit\Repository\Attribute\SQL;

#[Repository(Foo::class)]
interface MacroColumnsExceptRepositoryInterface
{
	#[SQL("SELECT %columns(except description) FROM foos")]
	public function allColumnsExcept(): iterable;
}
