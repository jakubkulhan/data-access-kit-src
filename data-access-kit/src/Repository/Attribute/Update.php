<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Update
{
	/**
	 * @param array<string>|null $columns
	 */
	public function __construct(
		public readonly ?array $columns = null,
	)
	{
	}
}
