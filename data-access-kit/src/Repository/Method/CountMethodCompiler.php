<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Method;

use DataAccessKit\Repository\Attribute\Count;
use DataAccessKit\Repository\MethodCompilerInterface;
use DataAccessKit\Repository\Result;
use DataAccessKit\Repository\ResultMethod;
use LogicException;

/**
 * @implements MethodCompilerInterface<Count>
 */
class CountMethodCompiler implements MethodCompilerInterface
{
	public function compile(Result $result, ResultMethod $method, $attribute): void
	{
		throw new LogicException("TODO: count");
	}
}
