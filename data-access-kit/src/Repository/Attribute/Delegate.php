<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Delegate
{
	public function __construct(
		public readonly string $class,
		public readonly ?string $method = null,
	)
	{
	}
}
