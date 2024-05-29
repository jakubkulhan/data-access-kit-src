<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;
use DataAccessKit\Repository\Attribute\Upsert;

#[Repository(Foo::class)]
interface UpsertRepositoryInterface
{
	public function upsert(Foo $foo): void;
	public function upsertAll(array $foos): void;
	#[Upsert(["title"])]
	public function upsertTitleOnly(array $foos): void;
}
