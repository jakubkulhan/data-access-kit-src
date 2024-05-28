<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;
use DataAccessKit\Repository\Attribute\SQLFile;

#[Repository(Foo::class)]
interface FileDoesNotExistSQLFileRepositoryInterface
{
	#[SQLFile(file: __DIR__ . "/file-does-not-exist.sql")]
	public function findByTitle(string $title): iterable;
}
