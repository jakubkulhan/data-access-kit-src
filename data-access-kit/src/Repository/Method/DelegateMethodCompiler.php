<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Method;

use DataAccessKit\Repository\Attribute\Delegate;
use DataAccessKit\Repository\Compiler;
use DataAccessKit\Repository\MethodCompilerInterface;
use DataAccessKit\Repository\Result;
use DataAccessKit\Repository\ResultMethod;
use ReflectionClass;
use ReflectionNamedType;
use function lcfirst;
use function ucfirst;

/**
 * @implements MethodCompilerInterface<Delegate>
 */
class DelegateMethodCompiler implements MethodCompilerInterface
{
	public function compile(Result $result, ResultMethod $method, $attribute): void
	{
		$delegateRC = new ReflectionClass($attribute->class);
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
		$returnType = $method->reflection->getReturnType();
		$void = $returnType instanceof ReflectionNamedType && $returnType->getName() === "void";
		$method->line((!$void ? "return " : "") . "\$this->{$propertyName}->{$delegateMethodName}(...func_get_args());");
	}
}
