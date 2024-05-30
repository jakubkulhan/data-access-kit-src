<?php declare(strict_types=1);

namespace DataAccessKit\Repository;

use ReflectionMethod;
use Stringable;
use function array_map;
use function implode;
use function str_repeat;

class ResultMethod implements Stringable
{

	public readonly ReflectionMethod $reflection;

	private string $visibility = "public";
	/** @var array<string, ResultParameter> */
	private array $parameters = [];
	private string $returnType = "";
	private string $body = "";
	/** @var array<string, ResultAttribute> */
	private array $attributes = [];

	private int $level = 0;

	public function __construct(
		public readonly string $name,
	)
	{
	}

	public function setReflection(ReflectionMethod $reflection): static
	{
		$this->reflection = $reflection;
		return $this;
	}

	public function setVisibility(string $visibility): static
	{
		$this->visibility = $visibility;
		return $this;
	}

	public function parameter(string $name): ResultParameter
	{
		return $this->parameters[$name] ??= new ResultParameter($name);
	}

	public function hasParameter(string $name): bool
	{
		return isset($this->parameters[$name]);
	}

	public function setReturnType(string $returnType): static
	{
		$this->returnType = $returnType;
		return $this;
	}

	public function line(string $line = ""): static
	{
		$this->body .= str_repeat("\t", $this->level) . $line . "\n";
		return $this;
	}

	public function indent(): static
	{
		$this->level++;
		return $this;
	}

	public function dedent(): static
	{
		$this->level--;
		return $this;
	}

	public function attribute(string $name): ResultAttribute
	{
		return $this->attributes[$name] ??= new ResultAttribute($name);
	}

	public function __toString()
	{
		return (
			implode("", array_map(fn(ResultAttribute $attribute) => (string) $attribute . "\n", $this->attributes)) .
			($this->visibility !== "" ? $this->visibility . " " : "")
			. "function " . $this->name . "(" .
			(count($this->parameters) > 0 ? "\n" : "") .
			implode("", array_map(fn(ResultParameter $parameter) => "\t" . (string) $parameter . ",\n", $this->parameters)) .
			")" .
			($this->returnType !== "" ? ": " . $this->returnType : "") .
			"\n" .
			"{\n" .
			Compiler::indent($this->body) .
			"}\n"
		);
	}

}
