<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;
use DataAccessKit\Repository\Attribute\SQL;

#[Repository(Foo::class)]
interface VariableSQLRepositoryInterface
{
	#[SQL("
		SELECT
			id,
			title
		FROM foos
		WHERE
		(title LIKE CONCAT('%', @titleAffix) 
		    OR title LIKE CONCAT(@titleAffix, '%'))
		AND id != @id")]
	public function findByTitleAffixAndNotId(string $titleAffix, int $id): array;
}
