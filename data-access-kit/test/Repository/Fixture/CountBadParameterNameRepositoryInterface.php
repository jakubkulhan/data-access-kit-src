<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;

#[Repository(Foo::class)]
interface CountBadParameterNameRepositoryInterface
{
	public function countByTitle(string $tytle): int;
}
