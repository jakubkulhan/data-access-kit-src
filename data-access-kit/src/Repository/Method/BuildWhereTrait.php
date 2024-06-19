<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Method;

use DataAccessKit\Attribute\Table;
use DataAccessKit\Repository\Attribute\Count;
use DataAccessKit\Repository\Attribute\Find;
use DataAccessKit\Repository\Exception\CompilerException;
use DataAccessKit\Repository\Result;
use DataAccessKit\Repository\ResultMethod;
use ReflectionNamedType;
use function implode;
use function in_array;
use function sprintf;
use function str_ends_with;

trait BuildWhereTrait
{
	private function buildWhere(ResultMethod $method, Table $table, Result $result, Find|Count $attribute): string
	{
		$conditions = [];
		foreach ($method->reflection->getParameters() as $parameter) {
			$column = null;
			$possibleNames = [$parameter->getName()];
			if (str_ends_with($parameter->getName(), "s")) {
				$possibleNames[] = substr($parameter->getName(), 0, -1);
			}
			foreach ($table->columns as $candidate) {
				if (in_array($candidate->reflection->getName(), $possibleNames, true)) {
					$column = $candidate;
					break;
				}
			}
			if ($column === null) {
				throw new CompilerException(sprintf(
					"Parameter [%s] of method [%s::%s] does not match any property of [%s], and therefore cannot be used as a query condition.",
					$parameter->getName(),
					$result->reflection->getName(),
					$method->reflection->getName(),
					$result->repository->class,
				));
			}

			if ($parameter->getType() instanceof ReflectionNamedType && $parameter->getType()->getName() === "array") {
				$conditions[] = "{$column->name} IN (@{$parameter->getName()})";
			} else {
				$conditions[] = "{$attribute->alias}.{$column->name} = @{$parameter->getName()}";
			}
		}

		return implode(" AND ", $conditions);
	}
}
