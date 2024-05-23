<?php declare(strict_types=1);

namespace DataAccessKit\Symfony\Fixture;

use DataAccessKit\Attribute\Column;
use DataAccessKit\Attribute\Table;

#[Table]
class Foo
{

	#[Column(primary: true, generated: true)]
	public int $id;

	#[Column]
	public int $title;

}
