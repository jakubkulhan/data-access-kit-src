<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Method;

use DataAccessKit\Registry;
use DataAccessKit\Repository\Attribute\Count;
use DataAccessKit\Repository\Attribute\SQL;
use DataAccessKit\Repository\Exception\CompilerException;
use DataAccessKit\Repository\MethodCompilerInterface;
use DataAccessKit\Repository\Result;
use DataAccessKit\Repository\ResultMethod;
use ReflectionNamedType;
use function sprintf;

/**
 * @implements MethodCompilerInterface<Count>
 */
class CountMethodCompiler implements MethodCompilerInterface
{
	use BuildWhereTrait;

	public function __construct(
		private readonly Registry $registry,
		private readonly SQLMethodCompiler $sqlMethodCompiler,
	)
	{
	}

	public function compile(Result $result, ResultMethod $method, $attribute): void
	{
		$returnType = $method->reflection->getReturnType();
		if (!$returnType instanceof ReflectionNamedType || $returnType->getName() !== "int") {
			throw new CompilerException(sprintf(
				"Count method [%s::%s] must return int to able to be generated. Either change the return type, add an attribute, or remove the method.",
				$result->reflection->getName(),
				$method->reflection->getName(),
			));
		}

		$table = $this->registry->get($result->repository->class, true);
		$from = $attribute->from ?? $table->name;
		$where = $attribute->where ?? $this->buildWhere($method, $table, $result, $attribute);
		$this->sqlMethodCompiler->compile(
			$result,
			$method,
			new SQL("SELECT COUNT(*) FROM {$from} {$attribute->alias} WHERE {$where}"),
		);
	}
}
