<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Method;

use DataAccessKit\Attribute\Table;
use DataAccessKit\Repository\Attribute\Count;
use DataAccessKit\Repository\Attribute\Find;
use DataAccessKit\Repository\Exception\CompilerException;
use DataAccessKit\Repository\Result;
use DataAccessKit\Repository\ResultMethod;
use function implode;
use function sprintf;

trait BuildWhereTrait
{
	private function buildWhere(ResultMethod $method, Table $table, Result $result, Find|Count $attribute): string
	{
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
				throw new CompilerException(sprintf(
					"Parameter [%s] of method [%s::%s] does not match any property of [%s], and therefore cannot be used as a query condition.",
					$parameter->getName(),
					$result->reflection->getName(),
					$method->reflection->getName(),
					$result->repository->class,
				));
			}

			$conditions[] = "{$attribute->alias}.{$column->name} = @{$parameter->getName()}";
		}

		return implode(" AND ", $conditions);
	}
}