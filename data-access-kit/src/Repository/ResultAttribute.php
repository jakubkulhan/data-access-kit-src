<?php declare(strict_types=1);

namespace DataAccessKit\Repository;

use Stringable;
use function is_numeric;

class ResultAttribute implements Stringable
{

	/** @var array<int|string, mixed> */
	private array $arguments = [];

	public function __construct(
		public readonly string $name,
	)
	{
	}

	public function setArguments(array $arguments): static
	{
		$this->arguments = $arguments;
		return $this;
	}

	public function __toString()
	{
		return (
			"#[" .
			$this->name .
			(count($this->arguments) > 0
				? "(" . $this->stringifyArguments() . ")"
				: "") .
			"]"
		);
	}

	private function stringifyArguments(): string
	{
		$arguments = [];
		foreach ($this->arguments as $key => $value) {
			if (is_numeric($key)) {
				$arguments[] = Compiler::varExport($value, true);
			} else {
				$arguments[] = $key . ": " . Compiler::varExport($value);
			}
		}
		return implode(", ", $arguments);
	}

}
