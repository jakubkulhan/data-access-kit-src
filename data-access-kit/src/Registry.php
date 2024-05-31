<?php declare(strict_types=1);

namespace DataAccessKit;

use DataAccessKit\Attribute\Column;
use DataAccessKit\Attribute\Table;
use DataAccessKit\Converter\NameConverterInterface;
use Doctrine\Common\Annotations\PhpParser;
use LogicException;
use ReflectionClass;
use ReflectionNamedType;
use function in_array;
use function preg_match;
use function sprintf;
use function str_contains;
use function strtolower;

class Registry
{

	/** @var array<string, Table> */
	private array $tablesByClassName = [];

	private PhpParser $phpParser;

	public function __construct(
		private readonly NameConverterInterface $nameConverter,
	)
	{
		$this->phpParser = new PhpParser();
	}

	public function get(object|string $objectOrClass, bool $requireTable = false): Table
	{
		$className = is_object($objectOrClass) ? get_class($objectOrClass) : $objectOrClass;

		if (isset($this->tablesByClassName[$className])) {
			if ($requireTable && $this->tablesByClassName[$className]->name === null) {
				throw static::missingTableException($className);
			}
			return $this->tablesByClassName[$className];
		}

		$rc = new ReflectionClass($className);
		if ($rc->isTrait() || $rc->isInterface() || $rc->isAbstract()) {
			throw new LogicException(sprintf(
				"Class [%s] must be concrete.",
				$className,
			));
		}
		$tableRA = $rc->getAttributes(Table::class)[0] ?? null;
		if ($tableRA === null) {
			if ($requireTable) {
				throw static::missingTableException($className);
			}

			$table = new Table();

		} else {
			/** @var Table $table */
			$table = $tableRA->newInstance();
			if ($table->name === null) {
				$table->name = $this->nameConverter->classToTable($rc);
			}
		}
		$table->setReflection($rc);

		$columns = [];
		foreach ($rc->getProperties() as $rp) {
			$columnRA = $rp->getAttributes(Column::class)[0] ?? null;
			if ($columnRA === null) {
				continue;
			}
			/** @var Column $column */
			$column = $columnRA->newInstance();
			if ($column->name === null) {
				$column->name = $this->nameConverter->propertyToColumn($rp);
			}
			if ($rp->getType() instanceof ReflectionNamedType &&
				$rp->getType()->getName() === "array" &&
				str_contains($rp->getDocComment() ?: "", "@var")
			) {
				if (preg_match(
					'/@var\s+\??(?:
						(?:array|list)<\s*(?:[^,>]+,\s*)?(?P<arrayValueType>[^,>]+)>
						|
						(?P<itemType>[^[]+)\[]
					)(?:\|null)?\s+/xi',
					$rp->getDocComment() ?: "",
					$m,
				)) {
					if (!empty($m["arrayValueType"])) {
						$itemType = $m["arrayValueType"];
					} else if (!empty($m["itemType"])) {
						$itemType = $m["itemType"];
					} else {
						throw new LogicException("Unreachable statement.");
					}
					if (!in_array($itemType, ["int", "float", "string", "bool"], true)) {
						$useStatements = $this->phpParser->parseUseStatements($rc);
						if (isset($useStatements[strtolower($itemType)])) {
							$itemType = $useStatements[strtolower($itemType)];
						} else {
							$itemType = $rc->getNamespaceName() . "\\" . $itemType;
						}
						$column->itemType = $itemType;
					}

				} else {
					throw new LogicException(sprintf(
						"Property [%s::%s] is of type array, doc comment contains @var annotation, but the item type could not be parsed.",
						$className,
						$rp->getName(),
					));
				}
			}

			$column->setReflection($rp);
			$columns[$column->name] = $column;
		}

		if (count($columns) === 0) {
			throw new LogicException(sprintf(
				"Class %s has no #[\\%s] attribute on any property.",
				$className,
				Column::class,
			));
		}

		$table->setColumns($columns);

		return $this->tablesByClassName[$className] = $table;
	}

	public function maybeGet(object|string $objectOrClass, bool $requireTable = false): ?Table
	{
		try {
			return $this->get(...func_get_args());
		} catch (LogicException) {
			return null;
		}
	}

	private static function missingTableException(object|string $className): LogicException
	{
		return new LogicException(sprintf(
			"Class %s is missing #[\\%s] attribute.",
			$className,
			Table::class,
		));
	}

}
