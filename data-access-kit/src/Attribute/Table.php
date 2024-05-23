<?php declare(strict_types=1);

namespace DataAccessKit\Attribute;

use Attribute;
use ReflectionClass;

#[Attribute(Attribute::TARGET_CLASS)]
class Table
{
	public readonly ReflectionClass $reflection;

	/** @var Column[] */
	public readonly array $columns;

	public function __construct(
		public ?string $name = null,
	)
	{
	}

	public function setReflection(ReflectionClass $reflection): void
	{
		$this->reflection = $reflection;
	}

	public function setColumns(array $columns): void
	{
		$this->columns = $columns;
	}

}
