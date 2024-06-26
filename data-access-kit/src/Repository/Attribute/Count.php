<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Attribute;


use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Count
{
	public function __construct(
		public readonly ?string $from = null,
		public readonly string $alias = "t",
		public readonly ?string $where = null,
	)
	{
	}
}
