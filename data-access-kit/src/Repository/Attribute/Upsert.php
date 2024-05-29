<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Upsert
{
	public function __construct(
		public readonly ?array $columns = null,
	)
	{
	}
}
