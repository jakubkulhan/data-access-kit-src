<?php declare(strict_types=1);

namespace DataAccessKit\Converter\Fixture;

use DataAccessKit\Attribute\Column;

class NestedObject
{
	public function __construct(
		#[Column] public string $key,
	)
	{
	}
}
