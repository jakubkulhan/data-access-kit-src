<?php declare(strict_types=1);

namespace DataAccessKit\Symfony\Fixture\Exclude;

use DataAccessKit\Repository\Attribute\Repository;

#[Repository(Baz::class)]
interface BazExcludedRepositoryInterface
{
	public function getById(int $id): Baz;
}
