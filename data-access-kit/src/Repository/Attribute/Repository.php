<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Repository
{
	public const string DEFAULT_DATABASE = "default";

	public function __construct(
		public readonly string $class,
		public readonly string $database = self::DEFAULT_DATABASE,
	)
	{
	}
}
