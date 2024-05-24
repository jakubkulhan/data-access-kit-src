<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Method;

use DataAccessKit\Attribute\Table;
use DataAccessKit\Repository\Attribute\Count;
use DataAccessKit\Repository\Attribute\Find;
use DataAccessKit\Repository\Result;
use DataAccessKit\Repository\ResultMethod;
use LogicException;
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

		return implode(" AND ", $conditions);
	}
}