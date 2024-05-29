<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;

#[Repository(Foo::class)]
interface UpsertBadParameterTypeRepositoryInterface
{
	public function upsert(Bar $bar): void;
}
