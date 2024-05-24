<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Method;

use DataAccessKit\PersistenceInterface;
use DataAccessKit\Registry;
use DataAccessKit\Repository\Attribute\Find;
use DataAccessKit\Repository\Attribute\SQL;
use DataAccessKit\Repository\Compiler;
use DataAccessKit\Repository\MethodCompilerInterface;
use DataAccessKit\Repository\Result;
use DataAccessKit\Repository\ResultMethod;
use DataAccessKit\ValueConverterInterface;
use LogicException;
use ReflectionNamedType;
use function array_map;
use function assert;
use function implode;
use function sprintf;

/**
 * @implements MethodCompilerInterface<Find>
 */
class FindMethodCompiler implements MethodCompilerInterface
{

	public function __construct(
		private readonly Registry $registry,
		private readonly SQLMethodCompiler $sqlMethodCompiler,
	)
	{
	}

	public function compile(Result $result, ResultMethod $method, $attribute): void
	{
		$table = $this->registry->get($result->repository->class, true);
		$select = $attribute->select;
		if ($select === null) {
			$select = implode(", ", array_map(fn($column) => $attribute->alias . "." . $column->name, $table->columns));
		}
		$from = $attribute->from ?? $table->name;
		$where = $attribute->where;
		if ($where === null) {
			$conditions = [];
			foreach ($method->reflection->getParameters() as $parameter) {
				$column = null;
				foreach ($table->columns as $candidate) {
					if ($candidate->reflection->getName() === $parameter->getName()) {
						$column = $candidate;
						break;
					}
				}
				if ($column === null) {
					throw new LogicException(sprintf(
						"Method [%s::%s] parameter [%s] does not match any property of [%s], and therefore cannot be used as a query condition.",
						$result->reflection->getName(),
						$method->reflection->getName(),
						$parameter->getName(),
						$result->repository->class,
					));
				}

				$conditions[] = "{$attribute->alias}.{$column->name} = @{$parameter->getName()}";
			}
			$where = implode(" AND ", $conditions);
		}

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
