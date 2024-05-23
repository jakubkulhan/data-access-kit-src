<?php declare(strict_types=1);

namespace DataAccessKit\Repository;

use Stringable;
use function array_map;
use function implode;

class ResultParameter implements Stringable
{

	public string $visibility = "";
	public string $type = "";
	public mixed $defaultValue = null;
	private bool $hasDefaultValue = false;
	/** @var array<string, ResultAttribute> */
	public array $attributes = [];

	public function __construct(
		public readonly string $name,
	)
	{
	}

	public function setVisibility(string $visibility): static
	{
		$this->visibility = $visibility;
		return $this;
	}

	public function setType(string $type): static
	{
		$this->type = $type;
		return $this;
	}

	public function setDefaultValue(mixed $defaultValue): static
	{
		$this->defaultValue = $defaultValue;
		$this->hasDefaultValue = true;
		return $this;
	}

	public function attribute(string $name): ResultAttribute
	{
		return $this->attributes[$name] ??= new ResultAttribute($name);
	}

	public function __toString()
	{
		return (
			implode("", array_map(fn(ResultAttribute $attribute) => (string) $attribute . " ", $this->attributes)) .
			($this->visibility !== "" ? $this->visibility . " " : "") .
			($this->type !== "" ? $this->type . " " : "") .
			"\$" . $this->name .
			($this->hasDefaultValue ? " = " . Compiler::varExport($this->defaultValue) : "")
		);
	}

}
