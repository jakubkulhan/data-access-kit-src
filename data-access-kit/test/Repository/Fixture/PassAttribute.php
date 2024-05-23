<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY)]
class PassAttribute
{
	public function __construct(
		public readonly string $s,
		public readonly int $i,
		public readonly array $a,
	)
	{
	}
}
