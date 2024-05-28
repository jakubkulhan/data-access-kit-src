<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;
use DataAccessKit\Repository\Attribute\SQL;

#[Repository(Foo::class)]
interface MacroUnknownRepositoryInterface
{
	#[SQL("SELECT %clonums FROM foos")]
	public function allColumns(): iterable;
}
