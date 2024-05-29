<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Method;

use DataAccessKit\Registry;
use DataAccessKit\Repository\Attribute\Find;
use DataAccessKit\Repository\Attribute\SQL;
use DataAccessKit\Repository\Exception\CompilerException;
use DataAccessKit\Repository\MethodCompilerInterface;
use DataAccessKit\Repository\Result;
use DataAccessKit\Repository\ResultMethod;
use ReflectionNamedType;
use function array_map;
use function implode;
use function in_array;
use function sprintf;

/**
 * @implements MethodCompilerInterface<Find>
 */
class FindMethodCompiler implements MethodCompilerInterface
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
		if (!$returnType instanceof ReflectionNamedType || !in_array($returnType->getName(), ["array", "iterable", $result->repository->class], true)) {
			throw new CompilerException(sprintf(
				"Find method [%s::%s] must return array, iterable, or [%s] to able to be generated. Either change the return type, add an attribute, or remove the method.",
				$result->reflection->getName(),
				$method->reflection->getName(),
				$result->repository->class,
			));
		}

		$table = $this->registry->get($result->repository->class, true);
		$select = $attribute->select;
		if ($select === null) {
			$select = implode(", ", array_map(fn($column) => $attribute->alias . "." . $column->name, $table->columns));
		}
		$from = $attribute->from ?? $table->name;
		$where = $attribute->where ?? $this->buildWhere($method, $table, $result, $attribute);

		$sql = "SELECT {$select} FROM {$from} {$attribute->alias} WHERE {$where}";
		if ($attribute->orderBy !== null) {
			$sql .= " ORDER BY {$attribute->orderBy}";
		}
		if ($attribute->limit !== null) {
			$sql .= " LIMIT {$attribute->limit}";
		}
		if ($attribute->offset !== null) {
			$sql .= " OFFSET {$attribute->offset}";
		}

		$this->sqlMethodCompiler->compile($result, $method, new SQL($sql));
	}

}
