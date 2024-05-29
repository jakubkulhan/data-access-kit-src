<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Method;

use DataAccessKit\Repository\Attribute\Delete;
use DataAccessKit\Repository\Exception\CompilerException;
use DataAccessKit\Repository\MethodCompilerInterface;
use DataAccessKit\Repository\Result;
use DataAccessKit\Repository\ResultMethod;
use ReflectionNamedType;
use function in_array;

/**
 * @implements MethodCompilerInterface<Delete>
 */
class DeleteMethodCompiler implements MethodCompilerInterface
{
	use CreateConstructorTrait;

	public function compile(Result $result, ResultMethod $method, $attribute): void
	{
		if (!$method->reflection->getReturnType() instanceof ReflectionNamedType || $method->reflection->getReturnType()->getName() !== "void") {
			throw new CompilerException(sprintf(
				"Delete method [%s::%s] must have void return type.",
				$result->reflection->getName(),
				$method->reflection->getName(),
			));
		}

		if ($method->reflection->getNumberOfParameters() !== 1) {
			throw new CompilerException(sprintf(
				"Delete method [%s::%s] must have exactly one parameter.",
				$result->reflection->getName(),
				$method->reflection->getName(),
			));
		}

		$rp = $method->reflection->getParameters()[0];
		if (!$rp->getType() instanceof ReflectionNamedType || !in_array($rp->getType()->getName(), ["array", $result->repository->class], true)) {
			throw new CompilerException(sprintf(
				"Delete method [%s::%s] must have exactly one parameter with type [%s] or array.",
				$result->reflection->getName(),
				$method->reflection->getName(),
				$result->repository->class,
			));
		}

		$this->createConstructorWithPersistenceProperty($result);

		if ($rp->getType()->getName() === "array") {
			$method->line("\$this->persistence->deleteAll(\${$rp->getName()});");
		} else {
			$method->line("\$this->persistence->delete(\${$rp->getName()});");
		}
	}
}
