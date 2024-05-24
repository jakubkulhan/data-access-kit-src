<?php declare(strict_types=1);

namespace DataAccessKit;

use LogicException;
use DataAccessKit\Attribute\Column;
use DataAccessKit\Attribute\Table;
use ReflectionClass;
use function sprintf;

class Registry
{

	/** @var array<string, Table> */
	private array $tablesByClassName = [];

	public function __construct(
		private readonly NameConverterInterface $nameConverter,
	)
	{
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

	private static function missingTableException(object|string $className): LogicException
	{
		return new LogicException(sprintf(
			"Class %s is missing #[\\%s] attribute.",
			$className,
			Table::class,
		));
	}

}
