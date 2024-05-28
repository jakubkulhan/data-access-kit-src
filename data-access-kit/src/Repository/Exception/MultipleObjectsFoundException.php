<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Exception;

use LogicException;

class MultipleObjectsFoundException extends LogicException
{
	public function __construct(string $className)
	{
		parent::__construct(sprintf(
			"Multiple [%s] objects found.",
			$className,
		));
	}
}
