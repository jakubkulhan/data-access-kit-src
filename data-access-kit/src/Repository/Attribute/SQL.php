<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class SQL
{
	public function __construct(
		public readonly string $sql,
		public readonly ?string $itemType = null,
	)
	{
	}
}
