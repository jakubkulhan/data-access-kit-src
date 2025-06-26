<?php declare(strict_types=1);

namespace DataAccessKit\Attribute;

use Attribute;
use ReflectionClass;

#[Attribute(Attribute::TARGET_CLASS)]
class Table
{
	/** @var ReflectionClass<object> */
	public ReflectionClass $reflection;

	/** @var Column[] */
	public array $columns;

	public function __construct(
		public ?string $name = null,
	)
	{
	}

	/**
	 * @param ReflectionClass<object> $reflection
	 */
	public function setReflection(ReflectionClass $reflection): void
	{
		$this->reflection = $reflection;
	}

	/**
	 * @param array<string, Column> $columns
	 */
	public function setColumns(array $columns): void
	{
		$this->columns = $columns;
	}

	public function getName(): string
	{
		if ($this->name === null) {
			throw new \LogicException(sprintf(
				"Table name is not set. Make sure the table is properly registered in the Registry."
			));
		}
		return $this->name;
	}

}
