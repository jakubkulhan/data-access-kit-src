<?php declare(strict_types=1);

namespace DataAccessKit\Symfony\Fixture\Other;

use DataAccessKit\Repository\Attribute\Repository;

#[Repository(
	class: Bar::class,
	database: "other",
)]
interface BarRepositoryInterface
{
	public function insert(Bar $bar): void;
}
