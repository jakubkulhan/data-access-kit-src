<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Find
{
	public function __construct(
		public readonly ?string $select = null,
		public readonly ?string $from = null,
		public readonly string $alias = "t",
		public readonly ?string $where = null,
		public readonly ?string $orderBy = null,
		public readonly ?string $limit = null,
		public readonly ?string $offset = null,
	)
	{
	}
}
