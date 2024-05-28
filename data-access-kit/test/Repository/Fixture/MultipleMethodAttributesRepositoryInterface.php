<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Find;
use DataAccessKit\Repository\Attribute\Repository;
use DataAccessKit\Repository\Attribute\SQL;

#[Repository(Foo::class)]
interface MultipleMethodAttributesRepositoryInterface
{
	#[SQL("SELECT * FROM foos WHERE title = @title")]
	#[Find(where: "title = @title")]
	public function findByTitle(string $title): iterable;
}
