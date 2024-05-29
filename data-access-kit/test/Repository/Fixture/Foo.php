<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Attribute\Column;
use DataAccessKit\Attribute\Table;
use DateTimeImmutable;

#[Table]
class Foo
{
	#[Column(primary: true, generated: true)]
	public int $id;

	#[Column]
	public string $title;

	#[Column]
	public string $description;

	#[Column]
	public DateTimeImmutable $createdAt;

	public int $notColumn;
}
