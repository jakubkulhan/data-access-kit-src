<?php declare(strict_types=1);

namespace DataAccessKit\Converter\Fixture;

use DataAccessKit\Attribute\Column;

class DeepNestedObject
{
	public function __construct(
		#[Column] public ?DeepNestedObject $nested = null,
	)
	{
	}
}
