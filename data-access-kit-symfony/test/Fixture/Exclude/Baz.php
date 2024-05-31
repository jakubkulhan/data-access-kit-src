<?php declare(strict_types=1);

namespace DataAccessKit\Symfony\Fixture\Exclude;

use DataAccessKit\Attribute\Column;
use DataAccessKit\Attribute\Table;

#[Table]
class Baz
{
	#[Column(primary: true, generated: true)]
	public int $id;
	#[Column]
	public string $title;
}
