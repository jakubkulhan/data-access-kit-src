<?php declare(strict_types=1);

namespace DataAccessKit\Attribute;

use Attribute;
use ReflectionProperty;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Column
{
	public ReflectionProperty $reflection;

	public function __construct(
		public ?string $name = null,
		public bool $primary = false,
		public bool $generated = false,
		public ?string $itemType = null,
	)
	{
	}

	public function setReflection(ReflectionProperty $reflection): void
	{
		$this->reflection = $reflection;
	}

}
