<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Method;

use DataAccessKit\PersistenceInterface;
use DataAccessKit\Registry;
use DataAccessKit\Repository\Attribute\Find;
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
	)
	{
	}

	public function compile(Result $result, ResultMethod $method, $attribute): void
	{
		$constructor = $result->method("__construct");
		$constructor->parameter("persistence")
			->setVisibility("private readonly")
			->setType($result->use(PersistenceInterface::class));
		$constructor->parameter("registry")
			->setVisibility("private readonly")
			->setType($result->use(Registry::class));
		$constructor->parameter("valueConverter")
			->setVisibility("private readonly")
			->setType($result->use(ValueConverterInterface::class));

		$alias = $result->use($result->repository->class);

		$table = $this->registry->get($result->repository->class, true);
		$where = [];
		$parameters = [];
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

			$where[] = "t.{$column->name} = ?";
			$parameters[] = "\$this->valueConverter->objectToDatabase(\$_table, \$_table->columns[" . Compiler::varExport($column->name) . "], \${$parameter->getName()})";
		}
		$query = "SELECT " .
			implode(", ", array_map(fn($column) => "t.{$column->name}", $table->columns)) .
			" FROM {$table->name} t" .
			" WHERE " . implode(" AND ", $where);

		$method
			->line("\$_table = \$this->registry->get({$alias}::class);")
			->line("\$result = \$this->persistence->query({$alias}::class, " . Compiler::varExport($query) . ", [" . implode(", ", $parameters) . "]);");

		$returnType = $method->reflection->getReturnType();
		assert($returnType instanceof ReflectionNamedType);

		if ($returnType->getName() === "iterable") {
			$method->line("return \$result;");
		} else if ($returnType->getName() === "array") {
			$method->line("return iterator_to_array(\$result);");
		} else if ($returnType->getName() === $result->repository->class) {
			$method
				->line("\$objects = iterator_to_array(\$result);")
				->line("if (count(\$objects) === 0) {");
			if ($returnType->allowsNull()) {
				$method->indent()->line("return null;")->dedent();
			} else {
				$method->indent()->line("throw new \\RuntimeException(\"Entity not found.\");")->dedent();
			}
			$method
				->line("} else if (count(\$objects) > 1) {")
				->indent()->line("throw new \\RuntimeException(\"Multiple entities found.\");")->dedent()
				->line("}")
				->line("return \$objects[0];");
		} else {
			throw new LogicException("Unexpected return type: {$returnType->getName()}.");
		}
	}

}
