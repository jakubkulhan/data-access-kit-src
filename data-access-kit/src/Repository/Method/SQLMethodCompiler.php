<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Method;

use DataAccessKit\Attribute\Column;
use DataAccessKit\PersistenceInterface;
use DataAccessKit\Repository\Attribute\SQL;
use DataAccessKit\Repository\Compiler;
use DataAccessKit\Repository\Exception\CompilerException;
use DataAccessKit\Repository\MethodCompilerInterface;
use DataAccessKit\Repository\Result;
use DataAccessKit\Repository\ResultMethod;
use ReflectionNamedType;
use function implode;
use function in_array;
use function preg_replace_callback;
use function sprintf;

/**
 * @implements MethodCompilerInterface<SQL>
 */
class SQLMethodCompiler implements MethodCompilerInterface
{
	public function compile(Result $result, ResultMethod $method, $attribute): void
	{
		$constructor = $result->method("__construct");
		$constructor->parameter("persistence")
			->setVisibility("private readonly")
			->setType($result->use(PersistenceInterface::class));

		if ($method->reflection->getNumberOfParameters() > 0) {
			$argumentsProperty = $method->name . "Arguments";
			$result->property($argumentsProperty)
				->setVisibility("private")
				->setType("object");
			$columnAlias = $result->use(Column::class);
			$constructor->line("\$this->{$argumentsProperty} = new class {")->indent();
			foreach ($method->reflection->getParameters() as $rp) {
				$phpType = Compiler::phpType($result, $rp->getType());
				$constructor->line("#[{$columnAlias}(name: \"{$rp->getName()}\")]");
				$constructor->line("public {$phpType} \$" . $rp->getName() . ";");
			}
			$constructor->dedent()->line("};");
		}

		$returnType = $method->reflection->getReturnType();
		if (!$returnType instanceof ReflectionNamedType) {
			throw new CompilerException(sprintf(
				"Method [%s::%s] has unsupported return type. Please provide named return type (scalar, array, iterable, or class name).",
				$result->reflection->getName(),
				$method->reflection->getName(),
			));
		}

		if (in_array($returnType->getName(), ["iterable", "array"], true)) {
			if ($attribute->itemType === null) {
				$itemAlias = $result->use($result->repository->class);
			} else {
				$itemAlias = $result->use($attribute->itemType);
			}
		} else if ($returnType->isBuiltin()) {
			$itemAlias = $returnType->getName();
		} else {
			$itemAlias = $result->use($returnType->getName());
		}

		$reflectionParametersByName = [];
		foreach ($method->reflection->getParameters() as $rp) {
			$reflectionParametersByName[$rp->getName()] = $rp;
		}

		$sqlParameters = [];
		$sql = preg_replace_callback('/@([a-zA-Z0-9_]+)/', static function ($m) use ($result, $method, $reflectionParametersByName, &$sqlParameters) {
			$name = $m[1];
			if (!isset($reflectionParametersByName[$name])) {
				throw new CompilerException(sprintf(
					"SQL for method [%s::%s] contains variable @%s, but method does not have parameter with this name.",
					$result->reflection->getName(),
					$method->reflection->getName(),
					$name,
				));
			}
			$rp = $reflectionParametersByName[$name];

			$sqlParameters[] = '$arguments[' . Compiler::varExport($rp->getName()) . ']';

			return "?";
		}, $attribute->sql);

		if ($method->reflection->getNumberOfParameters() > 0) {
			$method->line("\$arguments = clone \$this->{$argumentsProperty};");
			foreach ($method->reflection->getParameters() as $rp) {
				$method->line("\$arguments->{$rp->getName()} = \$" . $rp->getName() . ";");
			}
			$method->line("\$arguments = \$this->persistence->toRow(\$arguments);");
			$method->line();
		}

		$method->line("\$result = \$this->persistence->query({$itemAlias}::class, " . Compiler::varExport($sql) . ", [" . implode(", ", $sqlParameters) . "]);");
		$method->line();
		if ($returnType->getName() === "iterable") {
			$method->line("return \$result;");
		} else if ($returnType->getName() === "array") {
			$method->line("return iterator_to_array(\$result);");
		} else {
			$method
				->line("\$objects = iterator_to_array(\$result);")
				->line("if (count(\$objects) === 0) {");
			if ($returnType->allowsNull()) {
				$method->indent()->line("return null;")->dedent();
			} else {
				$method->indent()->line("throw new LogicException(\"Not found\");")->dedent();
			}
			$method
				->line("} else if (count(\$objects) > 1) {")
				->indent()->line("throw new LogicException(\"Multiple objects found\");")->dedent()
				->line("}")
				->line("return \$objects[0];");
		}
	}
}
