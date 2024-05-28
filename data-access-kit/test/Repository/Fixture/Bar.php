<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Attribute\Column;
use DataAccessKit\Attribute\Table;

#[Table]
class Bar
{
	#[Column(primary: true, generated: true)]
	public int $id;

	#[Column]
	public string $title;
}
