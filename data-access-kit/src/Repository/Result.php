<?php declare(strict_types=1);

namespace DataAccessKit\Repository;

use DataAccessKit\Repository\Attribute\Repository;
use ReflectionClass;
use Stringable;
use function array_map;
use function array_merge;
use function array_search;
use function implode;
use function ksort;
use function str_ends_with;
use function str_starts_with;
use function uasort;
use const SORT_NATURAL;

class Result implements Stringable
{

	/** @var array<class-string, string> */
	private array $useStatements = [];
	/** @var array<string, ResultProperty> */
	private array $properties = [];
	/** @var array<string, ResultMethod> */
	private array $methods = [];
	/** @var array<string, ResultAttribute> */
	private array $attributes = [];
	/** @var array<string, ReflectionClass> */
	public array $dependencies = [];

	public function __construct(
		public readonly Repository $repository,
		public readonly ReflectionClass $reflection,
		public readonly string $namespaceName,
		public readonly string $shortName,
	)
	{
		$this->use($reflection->getName());
		$this->dependsOn(new ReflectionClass($this->repository->class));
	}

	public function getName(): string
	{
		if ($this->namespaceName === "") {
			return $this->shortName;
		}

		return $this->namespaceName . "\\" . $this->shortName;
	}

	public function use(string $class): string
	{
		if (isset($this->useStatements[$class])) {
			return $this->useStatements[$class];
		}

		$rc = new ReflectionClass($class);
		$this->dependsOn($rc);

		$alias = $rc->getShortName();

		$suffix = "";
		while (array_search($alias . $suffix, $this->useStatements) !== false) {
			$suffix = $suffix === "" ? 2 : $suffix + 1;
		}
		$alias .= $suffix;

		return $this->useStatements[$class] = $alias;
	}

	public function dependsOn(ReflectionClass $rc): void
	{
		$this->dependencies[$rc->getName()] = $rc;
	}

	public function attribute(string $name): ResultAttribute
	{
		return $this->attributes[$name] ??= new ResultAttribute($name);
	}

	public function property(string $name): ResultProperty
	{
		return $this->properties[$name] ??= new ResultProperty($name);
	}

	public function method(string $name): ResultMethod
	{
		return $this->methods[$name] ??= new ResultMethod($name);
	}

	public function hasMethod(string $name): bool
	{
		return isset($this->methods[$name]);
	}

	public function sortMethod(array $methodIndices): void
	{
		uasort($this->methods, function (ResultMethod $a, ResultMethod $b) use ($methodIndices) {
			if (isset($methodIndices[$a->name]) && isset($methodIndices[$b->name])) {
				return $methodIndices[$a->name] <=> $methodIndices[$b->name];
			} else if (isset($methodIndices[$a->name]) || $b->name === "__construct") {
				return 1;
			} else if (isset($methodIndices[$b->name]) || $a->name === "__construct") {
				return -1;
			} else {
				return $a->name <=> $b->name;
			}
		});
	}

	public function __toString()
	{
		ksort($this->useStatements, SORT_NATURAL);
		$use = "";
		foreach ($this->useStatements as $class => $alias) {
			if (str_starts_with($class, $this->namespaceName . "\\")) {
				continue;
			}

			if ($class === $alias || str_ends_with($class, "\\" . $alias)) {
				$use .= "use $class;\n";
			} else {
				$use .= "use $class as $alias;\n";
			}
		}

		return (
			"<?php declare(strict_types=1);\n" .
			"\n" .
			"namespace {$this->namespaceName};\n" .
			"\n" .
			($use !== "" ? $use . "\n" : "") .
			implode("", array_map(fn(ResultAttribute $attribute) => (string) $attribute . "\n", $this->attributes)) .
			"final class {$this->shortName} implements " . $this->useStatements[$this->reflection->getName()] . "\n" .
			"{\n" .
			implode("\n", array_merge(
				array_map(fn(ResultProperty $property) => Compiler::indent((string) $property), $this->properties),
				array_map(fn(ResultMethod $method) => Compiler::indent((string) $method), $this->methods),
			)) .
			"}\n"
		);
	}

}
