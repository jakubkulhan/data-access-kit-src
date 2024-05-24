<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class SQLFile
{
	public function __construct(
		public readonly string $file,
		public readonly ?string $itemType = null,
	)
	{
	}
}
