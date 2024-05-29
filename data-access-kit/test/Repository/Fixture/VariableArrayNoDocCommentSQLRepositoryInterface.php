<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;
use DataAccessKit\Repository\Attribute\SQL;

#[Repository(Foo::class)]
interface VariableArrayNoDocCommentSQLRepositoryInterface
{
	#[SQL("SELECT * FROM foo WHERE id IN (@ids)")]
	public function findByIds(array $ids): array;
}
