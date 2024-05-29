<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Method;

use DataAccessKit\Repository\Attribute\Delete;
use DataAccessKit\Repository\Attribute\Insert;
use DataAccessKit\Repository\Attribute\Update;
use DataAccessKit\Repository\Attribute\Upsert;
use DataAccessKit\Repository\Compiler;
use DataAccessKit\Repository\Exception\CompilerException;
use DataAccessKit\Repository\MethodCompilerInterface;
use DataAccessKit\Repository\Result;
use DataAccessKit\Repository\ResultMethod;
use ReflectionNamedType;
use function get_class;
use function property_exists;
use function sprintf;
use function ucfirst;

/**
 * @implements MethodCompilerInterface<Insert|Upsert|Update|Delete>
 */
class ManipulationMethodCompiler implements MethodCompilerInterface
{
	use CreateConstructorTrait;

	public function compile(Result $result, ResultMethod $method, $attribute): void
	{
		$persistenceMethod = match (true) {
			$attribute instanceof Insert => "insert",
			$attribute instanceof Upsert => "upsert",
			$attribute instanceof Update => "update",
			$attribute instanceof Delete => "delete",
			default => throw new CompilerException(sprintf(
				"Unexpected attribute of type [%s].",
				get_class($attribute),
			)),
		};

		if (!$method->reflection->getReturnType() instanceof ReflectionNamedType || $method->reflection->getReturnType()->getName() !== "void") {
			throw new CompilerException(sprintf(
				"%s method [%s::%s] must have void return type.",
				ucfirst($persistenceMethod),
				$result->reflection->getName(),
				$method->reflection->getName(),
			));
		}

		if ($method->reflection->getNumberOfParameters() !== 1) {
			throw new CompilerException(sprintf(
				"%s method [%s::%s] must have exactly one parameter.",
				ucfirst($persistenceMethod),
				$result->reflection->getName(),
				$method->reflection->getName(),
			));
		}

		$rp = $method->reflection->getParameters()[0];
		if (!$rp->getType() instanceof ReflectionNamedType ||
			!(
				$rp->getType()->getName() === $result->repository->class ||
				($rp->getType()->getName() === "array" && !$attribute instanceof Update)
			)
		) {
			throw new CompilerException(sprintf(
				"%s method [%s::%s] must have exactly one parameter with type [%s]%s.",
				ucfirst($persistenceMethod),
				$result->reflection->getName(),
				$method->reflection->getName(),
				$result->repository->class,
				$attribute instanceof Update ? "" : " or array",
			));
		}

		$this->createConstructorWithPersistenceProperty($result);

		$method->line(
			"\$this->persistence->{$persistenceMethod}" .
			($rp->getType()->getName() === "array" ? "All" : "") .
			"(\${$rp->getName()}" .
			(property_exists($attribute, "columns") && $attribute->columns !== null ? ", " . Compiler::varExport($attribute->columns) : "") .
			");",
		);
	}
}
