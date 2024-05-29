<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Method;

use DataAccessKit\Repository\Attribute\Update;
use DataAccessKit\Repository\Exception\CompilerException;
use DataAccessKit\Repository\MethodCompilerInterface;
use DataAccessKit\Repository\Result;
use DataAccessKit\Repository\ResultMethod;
use ReflectionNamedType;

/**
 * @implements MethodCompilerInterface<Update>
 */
class UpdateMethodCompiler implements MethodCompilerInterface
{
	use CreateConstructorTrait;

	public function compile(Result $result, ResultMethod $method, $attribute): void
	{
		if (!$method->reflection->getReturnType() instanceof ReflectionNamedType || $method->reflection->getReturnType()->getName() !== "void") {
			throw new CompilerException(sprintf(
				"Update method [%s::%s] must have void return type.",
				$result->reflection->getName(),
				$method->reflection->getName(),
			));
		}

		if ($method->reflection->getNumberOfParameters() !== 1) {
			throw new CompilerException(sprintf(
				"Update method [%s::%s] must have exactly one parameter.",
				$result->reflection->getName(),
				$method->reflection->getName(),
			));
		}

		$rp = $method->reflection->getParameters()[0];
		if (!$rp->getType() instanceof ReflectionNamedType || $rp->getType()->getName() !== $result->repository->class) {
			throw new CompilerException(sprintf(
				"Update method [%s::%s] must have exactly one parameter with type [%s].",
				$result->reflection->getName(),
				$method->reflection->getName(),
				$result->repository->class,
			));
		}

		$this->createConstructorWithPersistenceProperty($result);

		$method->line("\$this->persistence->update(\${$rp->getName()});");
	}

}
