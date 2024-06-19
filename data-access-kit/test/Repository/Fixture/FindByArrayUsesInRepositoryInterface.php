<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;

#[Repository(Foo::class)]
interface FindByArrayUsesInRepositoryInterface
{
	/**
	 * @param int[] $ids
	 */
	public function findByIds(array $ids): array;
}
