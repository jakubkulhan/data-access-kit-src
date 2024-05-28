<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Exception;

use LogicException;
use function sprintf;

class NotFoundException extends LogicException
{
	public function __construct(string $className)
	{
		parent::__construct(sprintf(
			"Object [%s] not found.",
			$className,
		));
	}
}
