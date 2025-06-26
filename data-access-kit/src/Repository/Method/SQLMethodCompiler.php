<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Method;

use DataAccessKit\Attribute\Column;
use DataAccessKit\Registry;
use DataAccessKit\Repository\Attribute\SQL;
use DataAccessKit\Repository\Compiler;
use DataAccessKit\Repository\Exception\CompilerException;
use DataAccessKit\Repository\Exception\MultipleObjectsFoundException;
use DataAccessKit\Repository\Exception\NotFoundException;
use DataAccessKit\Repository\MethodCompilerInterface;
use DataAccessKit\Repository\Result;
use DataAccessKit\Repository\ResultMethod;
use Doctrine\Common\Annotations\PhpParser;
use LogicException;
use ReflectionNamedType;
use ReflectionParameter;
use function array_keys;
use function array_map;
use function array_search;
use function chr;
use function count;
use function ctype_lower;
use function explode;
use function implode;
use function in_array;
use function preg_match;
use function preg_quote;
use function preg_replace_callback;
use function preg_split;
use function sprintf;
use function strtolower;
use function ucfirst;

/**
 * @implements MethodCompilerInterface<SQL>
 */
class SQLMethodCompiler implements MethodCompilerInterface
{
	use CreateConstructorTrait;

	private const DELIMITER = "\0";

	private PhpParser $phpParser;

	public function __construct(
		private readonly Registry $registry,
	)
	{
		$this->phpParser = new PhpParser();
	}

	public function compile(Result $result, ResultMethod $method, $attribute): void
	{
		$returnType = $method->reflection->getReturnType();
		if (!$returnType instanceof ReflectionNamedType) {
			throw new CompilerException(sprintf(
				"Method [%s::%s] has unsupported return type. Please provide named return type (scalar, array, iterable, or class name).",
				$result->reflection->getName(),
				$method->reflection->getName(),
			));
		}

		$constructor = $this->createConstructorWithPersistenceProperty($result);

		$nonArrayArgumentsPropertyName = $method->reflection->getName() . "Arguments";
		$argumentsProperties = [];
		foreach ($method->reflection->getParameters() as $rp) {
			if ($rp->getType() instanceof ReflectionNamedType && $rp->getType()->getName() === "array") {
				if (!preg_match(
					'/@param\s+(?:
							(?:array|list)<\s*(?:[^,>]+,\s*)?(?P<arrayValueType>[^,>]+)>
							|
							(?P<itemType>[^[]+)\[]
						)\s+\$' . preg_quote($rp->getName(), '/') . '/xi',
					$method->reflection->getDocComment() ?: "",
					$m,
				)) {
					throw new CompilerException(sprintf(
						"Method [%s::%s] has parameter \$%s of type array, but does not have valid @param annotation for it. Please provide type for the array items.",
						$result->reflection->getName(),
						$method->reflection->getName(),
						$rp->getName(),
					));
				}
				if (!empty($m["arrayValueType"])) {
					$phpType = $m["arrayValueType"];
				} else if (!empty($m["itemType"])) {
					$phpType = $m["itemType"];
				} else {
					throw new LogicException("Unreachable statement.");
				}

				$useStatements = $this->phpParser->parseUseStatements($result->reflection);
				if (isset($useStatements[strtolower($phpType)])) {
					$phpType = $result->use($useStatements[strtolower($phpType)]);
				} else if (!ctype_lower($phpType[0])) {
					$phpType = $result->use($phpType);
				}

				$propertyName = $method->reflection->getName() . ucfirst($rp->getName()) . "ArgumentItem";
				$name = "value";

			} else {
				$phpType = Compiler::phpType($result, $rp->getType());
				$propertyName = $nonArrayArgumentsPropertyName;
				$name = $rp->getName();
			}

			if (!isset($argumentsProperties[$propertyName])) {
				$argumentsProperties[$propertyName] = [];
			}
			$argumentsProperties[$propertyName][] = "#[{$result->use(Column::class)}(name: \"{$name}\")]";
			$argumentsProperties[$propertyName][] = "public {$phpType} \${$name};";
		}

		foreach ($argumentsProperties as $name => $anonymousClassLines) {
			$result->property($name)
				->setVisibility("private")
				->setType("object");
			$constructor->line("\$this->{$name} = new class {")->indent();
			foreach ($anonymousClassLines as $line) {
				$constructor->line($line);
			}
			$constructor->dedent()->line("};");
		}

		if (isset($argumentsProperties[$nonArrayArgumentsPropertyName])) {
			$method->line("\$arguments = clone \$this->{$nonArrayArgumentsPropertyName};");
			foreach ($method->reflection->getParameters() as $rp) {
				if ($rp->getType() instanceof ReflectionNamedType && $rp->getType()->getName() === "array") {
					continue;
				}
				$method->line("\$arguments->{$rp->getName()} = \$" . $rp->getName() . ";");
			}
			$method->line("\$arguments = \$this->persistence->toRow(\$arguments);");
			$method->line();
		}

		[$sqlPartExpressions, $sqlParameterExpression] = $this->expandSQLMacrosAndVariables($method, $result, $attribute);

		if ($returnType->getName() === "void") {
			$method->line("\$this->persistence->execute(" . implode(" . ", $sqlPartExpressions) . ", [" . implode(", ", $sqlParameterExpression) . "]);");

		} else if ($returnType->isBuiltin() && !in_array($returnType->getName(), ["array", "iterable"], true)) {
			if (!in_array($returnType->getName(), ["int", "float", "string", "bool"], true)) {
				throw new CompilerException(sprintf(
					"Method [%s::%s] has unsupported scalar return type [%s]. Please use int, float, string, or bool.",
					$result->reflection->getName(),
					$method->reflection->getName(),
					$returnType->getName(),
				));
			}
			$method->line("\$result = \$this->persistence->selectScalar(" . implode(" . ", $sqlPartExpressions) . ", [" . implode(", ", $sqlParameterExpression) . "]);");
			if ($returnType->allowsNull()) {
				$method->line("return \$result === null ? null : ({$returnType->getName()})\$result;");
			} else {
				$method->line("return ({$returnType->getName()})\$result;");
			}

		} else {
			$aliasUse = true;
			if (in_array($returnType->getName(), ["iterable", "array"], true)) {
				if ($attribute->itemType === null) {
					$itemType = $result->repository->class;
				} else {
					$itemType = $attribute->itemType;
				}
			} else if ($returnType->isBuiltin()) {
				$itemType = $returnType->getName();
				$aliasUse = false;
			} else {
				$itemType = $returnType->getName();
			}
			if ($aliasUse) {
				$itemAlias = $result->use($itemType);
			} else {
				$itemAlias = $itemType;
			}

			$method->line("\$result = \$this->persistence->select({$itemAlias}::class, " . implode(" . ", $sqlPartExpressions) . ", [" . implode(", ", $sqlParameterExpression) . "]);");
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
					$notFoundExceptionAlias = $result->use(NotFoundException::class);
					$method->indent()->line("throw new {$notFoundExceptionAlias}(" . Compiler::varExport($itemType) . ");")->dedent();
				}
				$multipleObjectsFoundExceptionAlias = $result->use(MultipleObjectsFoundException::class);
				$method
					->line("} else if (count(\$objects) > 1) {")
					->indent()->line("throw new {$multipleObjectsFoundExceptionAlias}(" . Compiler::varExport($itemType) . ");")->dedent()
					->line("}")
					->line("return \$objects[0];");
			}
		}
	}

	/**
	 * @return array{0: array<string>, 1: array<string>}
	 */
	private function expandSQLMacrosAndVariables(ResultMethod $method, Result $result, SQL $attribute): array
	{
		$table = $this->registry->get($result->repository->class, true);

		$reflectionParametersByName = [];
		foreach ($method->reflection->getParameters() as $rp) {
			$reflectionParametersByName[$rp->getName()] = $rp;
		}

		$sqlParameterExpressions = [];
		$usedVariables = [];
		$processedArgumentVariables = [];
		$sql = preg_replace_callback(
			'/
				@(?P<variable>[a-zA-Z0-9_]+)
				|
				%(?P<table>table\b)
				|
				%(?P<columns>columns\b(?:\(\s*
					(?:except\s+(?P<columnsExcept>[a-zA-Z0-9_]+(?:\s*,\s*[a-zA-Z0-9_]+)*)\s*)?
					(?:alias\s+(?P<columnsAlias>[a-zA-Z0-9_]+)\s*)?
				\))?)
				|
				%(?P<macro>[a-zA-Z0-9_]+\b(?:\([^)]*\))?)
			/xi',
			static function ($m) use ($result, $method, $table, $reflectionParametersByName, &$sqlParameterExpressions, &$usedVariables, &$processedArgumentVariables) {
				if (!empty($m["variable"])) {
					$name = $m["variable"];
					if (!isset($reflectionParametersByName[$name])) {
						throw new CompilerException(sprintf(
							"SQL for method [%s::%s] contains variable @%s, but method does not have parameter with this name. Please check for typos.",
							$result->reflection->getName(),
							$method->reflection->getName(),
							$name,
						));
					}
					/** @var ReflectionParameter $rp */
					$rp = $reflectionParametersByName[$name];
					$usedVariables[$name] = true;

					if ($rp->getType() instanceof ReflectionNamedType && $rp->getType()->getName() === "array") {
						$argumentVariableName = "argument" . ucfirst($rp->getName());
						if (!isset($processedArgumentVariables[$argumentVariableName])) {
							$method
								->line("\${$argumentVariableName} = [];")
								->line("foreach (\${$rp->getName()} as \$item) {")
								->indent()
								->line("\$itemObject = clone \$this->" . $method->reflection->getName() . ucfirst($rp->getName()) . "ArgumentItem;")
								->line("\$itemObject->value = \$item;")
								->line("\${$argumentVariableName}[] = \$this->persistence->toRow(\$itemObject)['value'];")
								->dedent()
								->line("}");
							$processedArgumentVariables[$argumentVariableName] = true;
						}

						$sqlParameterExpressions[] = "...\${$argumentVariableName}";
						return self::DELIMITER . $argumentVariableName . self::DELIMITER;

					} else {
						$sqlParameterExpressions[] = '$arguments[' . Compiler::varExport($rp->getName()) . ']';
						return "?";
					}

				} else if (!empty($m["table"])) {
					return $table->name;

				} else if (!empty($m["columns"])) {
					$columnNames = array_keys($table->columns);

					if (!empty($m["columnsExcept"])) {
						foreach (preg_split('/\s*,\s*/', $m["columnsExcept"]) as $exceptColumnName) {
							$key = array_search($exceptColumnName, $columnNames, true);
							if ($key === false) {
								throw new CompilerException(sprintf(
									"SQL for method [%s::%s] contains %%columns(except ...) where it excepts unknown column [%s]. Please check for typos.",
									$result->reflection->getName(),
									$method->reflection->getName(),
									$exceptColumnName,
								));
							}

							unset($columnNames[$key]);
						}

						if (count($columnNames) === 0) {
							throw new CompilerException(sprintf(
								"SQL for method [%s::%s] contains %%columns(except ...) where it excepts all columns. Remove columns from except clause, or remove the clause entirely.",
								$result->reflection->getName(),
								$method->reflection->getName(),
							));
						}
					}

					if (!empty($m["columnsAlias"])) {
						$columnNames = array_map(fn(string $it) => $m["columnsAlias"] . "." . $it, $columnNames);
					}

					return implode(", ", $columnNames);

				} else if (!empty($m["macro"])) {
					throw new CompilerException(sprintf(
						"SQL for method [%s::%s] contains unknown macro [%s]. Please check for typos.",
						$result->reflection->getName(),
						$method->reflection->getName(),
						$m["macro"],
					));

				} else {
					throw new LogicException("Unreachable statement.");
				}
			},
			$attribute->sql,
		);

		foreach ($reflectionParametersByName as $rp) {
			if (!isset($usedVariables[$rp->getName()])) {
				throw new CompilerException(sprintf(
					"Method [%s::%s] has parameter \$%s, but SQL does not contain variable @%s. Please check for typos, or remove it from the method signature.",
					$result->reflection->getName(),
					$method->reflection->getName(),
					$rp->getName(),
					$rp->getName(),
				));
			}
		}

		$sqlPartExpressions = [];
		foreach (explode(self::DELIMITER, $sql) as $index => $part) {
			if ($index % 2 === 0) {
				$sqlPartExpressions[] = Compiler::varExport($part);
			} else {
				$sqlPartExpressions[] = "(count(\${$part}) === 0 ? 'NULL' : '?' . str_repeat(', ?', count(\${$part}) - 1))";
			}
		}

		return [
			$sqlPartExpressions,
			$sqlParameterExpressions,
		];
	}
}
