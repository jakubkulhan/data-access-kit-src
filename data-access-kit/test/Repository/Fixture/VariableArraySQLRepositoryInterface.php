<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Find;
use DataAccessKit\Repository\Attribute\Repository;
use DataAccessKit\Repository\Attribute\SQL;
use DateTimeImmutable;

#[Repository(Foo::class)]
interface VariableArraySQLRepositoryInterface
{
	#[SQL("SELECT * FROM foo WHERE id IN (@ids)")]
	/**
	 * @param int[] $ids
	 */
	public function findByIdsPostfix(array $ids): array;

	#[SQL("SELECT * FROM foo WHERE id IN (@ids)")]
	/**
	 * @param array<int> $ids
	 */
	public function findByIdsArray(array $ids): array;

	#[SQL("SELECT * FROM foo WHERE id IN (@ids)")]
	/**
	 * @param array<int, int> $ids
	 */
	public function findByIdsArrayWithKey(array $ids): array;

	#[SQL("SELECT * FROM foo WHERE id IN (@ids)")]
	/**
	 * @param list<int> $ids
	 */
	public function findByIdsList(array $ids): array;

	#[Find(where: "createdAt IN (@creationTimes)")]
	/**
	 * @param array<DateTimeImmutable> $creationTimes
	 */
	public function findByCreationTime(array $creationTimes): array;

	#[Find(where: "title = @title AND COALESCE(createdAt NOT IN (@excludeCreatedAt), TRUE)")]
	/**
	 * @param array<DateTimeImmutable> $excludeCreatedAt
	 */
	public function findByTitleExcludeCreationTime(string $title, array $excludeCreatedAt): array;

	/**
	 * @param int[] $ids
	 */
	#[SQL("SELECT * FROM foo WHERE id IN (@ids) OR id IN (@ids)")]
	public function findByIdsUsedMultipleTimes(array $ids): array;
}
