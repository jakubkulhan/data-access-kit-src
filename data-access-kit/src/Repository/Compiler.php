<?php declare(strict_types=1);

namespace DataAccessKit\Repository;

use Composer\Pcre\Preg;
use DataAccessKit\Registry;
use DataAccessKit\Repository\Attribute\Count;
use DataAccessKit\Repository\Attribute\Delegate;
use DataAccessKit\Repository\Attribute\Delete;
use DataAccessKit\Repository\Attribute\Find;
use DataAccessKit\Repository\Attribute\Insert;
use DataAccessKit\Repository\Attribute\Repository;
use DataAccessKit\Repository\Attribute\SQL;
use DataAccessKit\Repository\Attribute\SQLFile;
use DataAccessKit\Repository\Attribute\Update;
use DataAccessKit\Repository\Attribute\Upsert;
use DataAccessKit\Repository\Exception\CompilerException;
use DataAccessKit\Repository\Method\CountMethodCompiler;
use DataAccessKit\Repository\Method\DelegateMethodCompiler;
use DataAccessKit\Repository\Method\DeleteMethodCompiler;
use DataAccessKit\Repository\Method\FindMethodCompiler;
use DataAccessKit\Repository\Method\ManipulationMethodCompiler;
use DataAccessKit\Repository\Method\SQLFileMethodCompiler;
use DataAccessKit\Repository\Method\SQLMethodCompiler;
use DataAccessKit\Repository\Method\UpdateMethodCompiler;
use LogicException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionUnionType;
use function array_is_list;
use function array_map;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_string;

use function sprintf;
use function strlen;
use function strtolower;
use function substr;

class Compiler
{
	public const string PERSISTENCE_PROPERTY = "persistence";

	/** @var array<string, MethodCompilerInterface<mixed>> */
	private array $methodCompilers = [];

	public function __construct(
		Registry $registry,
	)
	{
		$this->registerMethodCompiler(SQL::class, $sqlMethodCompiler = new SQLMethodCompiler($registry));
		$this->registerMethodCompiler(Find::class, new FindMethodCompiler($registry, $sqlMethodCompiler));
		$this->registerMethodCompiler(Count::class, new CountMethodCompiler($registry, $sqlMethodCompiler));
		$this->registerMethodCompiler(SQLFile::class, new SQLFileMethodCompiler($sqlMethodCompiler));
		$this->registerMethodCompiler(Delegate::class, new DelegateMethodCompiler());
		$this->registerMethodCompiler(Insert::class, $manipulationMethodCompiler = new ManipulationMethodCompiler());
		$this->registerMethodCompiler(Upsert::class, $manipulationMethodCompiler);
		$this->registerMethodCompiler(Update::class, $manipulationMethodCompiler);
		$this->registerMethodCompiler(Delete::class, $manipulationMethodCompiler);
	}

	/**
	 * @param class-string $attributeClassName
	 * @param MethodCompilerInterface<mixed> $methodCompiler
	 */
	public function registerMethodCompiler(string $attributeClassName, MethodCompilerInterface $methodCompiler): void
	{
		$this->methodCompilers[$attributeClassName] = $methodCompiler;
	}

	/**
	 * @param class-string|\ReflectionClass<object> $repositoryInterface
	 */
	public function prepare(\ReflectionClass|string $repositoryInterface): Result
	{
		if (is_string($repositoryInterface)) {
			$repositoryInterface = new ReflectionClass($repositoryInterface);
		}

		if (!$repositoryInterface->isInterface()) {
			throw new CompilerException(sprintf(
				"The provided class name must be an interface, [%s] is not an interface.",
				$repositoryInterface->getName(),
			));
		}

		$repository = null;
		/** @var \ReflectionAttribute<object>[] $classAttributes */
		$classAttributes = [];
		foreach ($repositoryInterface->getAttributes() as $ra) {
			if ($ra->getName() === Repository::class) {
				$repository = $ra->newInstance();
			} else {
				$classAttributes[] = $ra;
			}
		}
		if ($repository === null) {
			throw new CompilerException(sprintf(
				"The provided interface must have a #[\\%s] attribute.",
				Repository::class,
			));
		}
		/** @var Repository $repository */

		if (Preg::isMatch('/Interface$/', $repositoryInterface->getShortName())) {
			$classShortName = substr($repositoryInterface->getShortName(), 0, -strlen("Interface"));
		} else if (Preg::isMatch('/^I[A-Z]/', $repositoryInterface->getShortName())) {
			$classShortName = substr($repositoryInterface->getShortName(), 1);
		} else {
			$classShortName = $repositoryInterface->getShortName() . "Impl";
		}

		$result = new Result($repository, $repositoryInterface, $repositoryInterface->getNamespaceName(), $classShortName);
		foreach ($classAttributes as $classAttribute) {
			/** @var class-string $attributeName */
			$attributeName = $classAttribute->getName();
			$result->attribute($result->use($attributeName))->setArguments($classAttribute->getArguments());
		}

		return $result;
	}

	public function compile(Result $result): Result
	{
		$methodIndices = [];

		foreach ($result->reflection->getMethods() as $index => $rm) {
			$result->dependsOn($rm->getDeclaringClass());

			$methodIndices[$rm->getName()] = $index;

			/** @var MethodCompilerInterface<mixed>|null $methodCompiler */
			$methodCompiler = null;
			$methodCompilerAttribute = null;
			/** @var \ReflectionAttribute<object>[] $methodAttributes */
			$methodAttributes = [];

			foreach ($rm->getAttributes() as $ra) {
				if (isset($this->methodCompilers[$ra->getName()])) {
					if ($methodCompiler !== null) {
						throw new CompilerException(sprintf(
							"Method [%s::%s] has multiple method compiler attributes. Only one method compiler is allowed per method.",
							$result->reflection->getName(),
							$rm->getName(),
						));
					}
					$methodCompiler = $this->methodCompilers[$ra->getName()];
					$methodCompilerAttribute = $ra->newInstance();
				} else {
					$methodAttributes[] = $ra;
				}
			}

			if ($methodCompiler === null) {
				$words = explode(" ", strtolower(Preg::replace('/(?<!^|[A-Z])[A-Z]/', ' $0', $rm->getName())));
				if (in_array($words[0], ["get", "find"], true)) {
					$methodCompiler = $this->methodCompilers[Find::class];
					$methodCompilerAttribute = new Find();

				} else if ($words[0] === "count") {
					$methodCompiler = $this->methodCompilers[Count::class];
					$methodCompilerAttribute = new Count();

				} else if ($words[0] === "insert") {
					$methodCompiler = $this->methodCompilers[Insert::class];
					$methodCompilerAttribute = new Insert();

				} else if ($words[0] === "upsert") {
					$methodCompiler = $this->methodCompilers[Upsert::class];
					$methodCompilerAttribute = new Upsert();

				} else if ($words[0] === "update") {
					$methodCompiler = $this->methodCompilers[Update::class];
					$methodCompilerAttribute = new Update();

				} else if ($words[0] === "delete") {
					$methodCompiler = $this->methodCompilers[Delete::class];
					$methodCompilerAttribute = new Delete();

				} else {
					throw new CompilerException(sprintf(
						"Doesn't know how to generate method [%s::%s]. Either change the method, add an attribute, or remove the method.",
						$result->reflection->getName(),
						$rm->getName(),
					));
				}
			}

			$method = $result->method($rm->getName());
			$method->setReflection($rm);
			foreach ($methodAttributes as $ra) {
				/** @var class-string $attributeName */
				$attributeName = $ra->getName();
				$method->attribute($result->use($attributeName))->setArguments($ra->getArguments());
			}
			foreach ($rm->getParameters() as $rp) {
				$parameter = $method->parameter($rp->getName());
				$parameter->setType(static::phpType($result, $rp->getType()));
				if ($rp->isDefaultValueAvailable()) {
					$parameter->setDefaultValue($rp->getDefaultValue());
				}
			}
			$method->setReturnType(static::phpType($result, $rm->getReturnType()));

			if ($methodCompilerAttribute === null) {
				throw new CompilerException("Method compiler attribute should not be null at this point");
			}
			$methodCompiler->compile($result, $method, $methodCompilerAttribute);
		}

		$result->sortMethod($methodIndices);

		return $result;
	}

	public static function phpType(Result $result, \ReflectionType|null $type): string
	{
		if ($type === null) {
			return "";
		} else if ($type instanceof ReflectionNamedType) {
			if ($type->isBuiltin()) {
				$s = $type->getName();
			} else {
				/** @var class-string $typeName */
				$typeName = $type->getName();
				$s = $result->use($typeName);
			}
			if ($type->getName() !== "null" && $type->allowsNull()) {
				$s = "?" . $s;
			}
			return $s;
		} else if ($type instanceof ReflectionUnionType) {
			$types = [];
			foreach ($type->getTypes() as $t) {
				$types[] = static::phpType($result, $t);
			}
			return implode("|", $types);
		} else {
			// Must be ReflectionIntersectionType (the only remaining possibility)
			if (!$type instanceof ReflectionIntersectionType) {
				throw new CompilerException(sprintf(
					"Unexpected ReflectionType subclass: %s. Expected ReflectionIntersectionType.",
					get_class($type)
				));
			}
			$types = [];
			foreach ($type->getTypes() as $t) {
				$types[] = static::phpType($result, $t);
			}
			return implode("&", $types);
		}
	}

	public static function varExport(mixed $value): string
	{
		if (is_array($value)) {
			if (array_is_list($value)) {
				return "[" . implode(", ", array_map(fn($v) => static::varExport($v), $value)) . "]";
			} else {
				$s = "[";
				foreach ($value as $k => $v) {
					$s .= static::varExport($k) . " => " . static::varExport($v) . ", ";
				}
				return substr($s, 0, -2) . "]";
			}
		}
		return var_export($value, true);
	}

	public static function indent(string $s, string $indent = "\t"): string
	{
		return implode("\n", array_map(fn(string $line) => ($line !== "" ? $indent : "") . $line, explode("\n", $s)));
	}

}
