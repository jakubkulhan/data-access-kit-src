<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;
use DataAccessKit\Repository\Attribute\SQLFile;

#[Repository(Foo::class)]
interface RelativeSQLFileRepositoryInterface
{
	#[SQLFile("SQLFileRepository.findByTitle.sql")]
	public function findByTitle(string $title): array;
}
