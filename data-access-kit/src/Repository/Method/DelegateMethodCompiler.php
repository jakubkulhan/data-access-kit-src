<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Method;

use DataAccessKit\Repository\Attribute\Delegate;
use DataAccessKit\Repository\Compiler;
use DataAccessKit\Repository\Exception\CompilerException;
use DataAccessKit\Repository\MethodCompilerInterface;
use DataAccessKit\Repository\Result;
use DataAccessKit\Repository\ResultMethod;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use function class_exists;
use function lcfirst;
use function sprintf;
use function ucfirst;

/**
 * @implements MethodCompilerInterface<Delegate>
 */
class DelegateMethodCompiler implements MethodCompilerInterface
{
	public function compile(Result $result, ResultMethod $method, $attribute): void
	{
		try {
			/** @var class-string $delegateClass */
			$delegateClass = $attribute->class;
			$delegateRC = new ReflectionClass($delegateClass);
		} catch (ReflectionException $e) {
			throw new CompilerException(
				sprintf(
					"Delegate class [%s] referenced by method [%s::%s] does not exist. Please fix the class, interface, or trait reference.",
					$attribute->class,
					$result->reflection->getName(),
					$method->reflection->getName(),
				),
				0,
				$e,
			);
		}

		$alias = $result->use($delegateRC->getName());
		$propertyName = lcfirst($alias);

		$constructor = $result->method("__construct");

		if ($delegateRC->isTrait()) {
			$result->property($propertyName)
				->setVisibility("private")
				->setType("object");
			$traitConstructorRM = $delegateRC->getConstructor();
			$arguments = [];
			if ($traitConstructorRM !== null) {
				foreach ($traitConstructorRM->getParameters() as $rp) {
					$parameterName = $propertyName . ucfirst($rp->getName());
					$parameter = $constructor->parameter($parameterName);
					if ($rp->hasType()) {
						$parameter->setType(Compiler::phpType($result, $rp->getType()));
					}
					foreach ($rp->getAttributes() as $ra) {
						$parameter->attribute($result->use($ra->getName()))->setArguments($ra->getArguments());
					}
					$arguments[] = "\${$parameterName}";
				}
			}

			$constructor->line("\$this->{$propertyName} = new class(" . implode(", ", $arguments) . ") { use {$alias}; };");

		} else {
			$constructor->parameter($propertyName)
				->setVisibility("private readonly")
				->setType($alias);
		}

		$delegateMethodName = $attribute->method ?? $method->name;
		if (!$delegateRC->hasMethod($delegateMethodName)) {
			throw new CompilerException(
				sprintf(
					"Delegate method [%s::%s] referenced by method [%s::%s] does not exist. Please fix the method name.",
					$delegateRC->getName(),
					$delegateMethodName,
					$result->reflection->getName(),
					$method->reflection->getName(),
				),
			);
		}
		$returnType = $method->reflection->getReturnType();
		$void = $returnType instanceof ReflectionNamedType && $returnType->getName() === "void";
		$method->line((!$void ? "return " : "") . "\$this->{$propertyName}->{$delegateMethodName}(...func_get_args());");
	}
}
