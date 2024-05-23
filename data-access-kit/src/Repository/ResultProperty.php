<?php declare(strict_types=1);

namespace DataAccessKit\Repository;

use Stringable;

class ResultProperty implements Stringable
{

	public string $visibility = "public";
	public string $type = "";

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

	public function __toString()
	{
		return (
			"{$this->visibility} " .
			($this->type !== "" ? "{$this->type} " : "") .
			"\${$this->name};\n"
		);
	}

}
