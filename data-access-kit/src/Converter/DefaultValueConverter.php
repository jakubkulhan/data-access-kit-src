<?php declare(strict_types=1);

namespace DataAccessKit\Converter;

use DataAccessKit\Attribute\Column;
use DataAccessKit\Attribute\Table;
use DataAccessKit\Exception\ConverterException;
use DataAccessKit\Registry;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use ReflectionNamedType;
use stdClass;
use function in_array;
use function is_object;
use function json_decode;
use function json_encode;
use function spl_object_id;
use function sprintf;

class DefaultValueConverter implements ValueConverterInterface
{
	/** @var array<int, bool> */
	private array $recursionGuard = [];

	public function __construct(
		private readonly Registry $registry,
		private readonly DateTimeZone $dateTimeZone = new DateTimeZone("UTC"),
		private readonly string $dateTimeFormat = "Y-m-d H:i:s",
	)
	{
	}

	public function objectToDatabase(Table $table, Column $column, mixed $value): mixed
	{
		if ($value === null) {
			return null;
		}

		$recursionGuardKey = null;
		if (is_object($value)) {
			$recursionGuardKey = spl_object_id($value);
			if (isset($this->recursionGuard[$recursionGuardKey])) {
				throw new ConverterException("Recursion detected.");
			}
			$this->recursionGuard[$recursionGuardKey] = true;
		}
		try {
			$valueType = $column->reflection->getType();
			if ($valueType instanceof ReflectionNamedType) {
				if (in_array($valueType->getName(), ["int", "float", "string", "bool"], true)) {
					// passthrough
				} else if ($valueType->getName() === "object") {
					$value = json_encode($value);
				} else if ($valueType->getName() === "array") {
					if ($column->itemType === null) {
						$value = json_encode($value);
					} else {
						$nestedTable = $this->registry->get($column->itemType);
						$jsonArray = [];
						foreach ($value as $item) {
							$jsonArray[] = $jsonObject = new stdClass();
							foreach ($nestedTable->columns as $nestedColumn) {
								$jsonObject->{$nestedColumn->name} = $this->objectToDatabase(
									$nestedTable,
									$nestedColumn,
									$nestedColumn->reflection->getValue($item),
								);
							}
						}
						$value = json_encode($jsonArray);
					}
				} else if (in_array($valueType->getName(), [DateTime::class, DateTimeImmutable::class], true)) {
					/** @var DateTime|DateTimeImmutable $value */
					$value = (clone $value)->setTimezone($this->dateTimeZone)->format($this->dateTimeFormat);
				} else if (null !== ($nestedTable = $this->registry->maybeGet($valueType->getName()))) {
					$jsonObject = new stdClass();
					foreach ($nestedTable->columns as $nestedColumn) {
						$jsonObject->{$nestedColumn->name} = $this->objectToDatabase(
							$nestedTable,
							$nestedColumn,
							$nestedColumn->reflection->getValue($value),
						);
					}
					$value = json_encode($jsonObject);
				} else {
					throw new ConverterException(sprintf(
						"Unsupported type [%s] of property [%s::\$%s].",
						$valueType->getName(),
						$table->reflection->getName(),
						$column->reflection->getName()
					));
				}
			} else {
				throw new ConverterException(sprintf(
					"Property [%s::\$%s] must have a named type declaration (union and intersect declarations are not supported).",
					$table->reflection->getName(),
					$column->reflection->getName()
				));
			}

			return $value;

		} finally {
			if ($recursionGuardKey !== null) {
				unset($this->recursionGuard[$recursionGuardKey]);
			}
		}
	}

	public function databaseToObject(Table $table, Column $column, mixed $value): mixed
	{
		if ($value === null) {
			return null;
		}

		$valueType = $column->reflection->getType();
		if ($valueType instanceof ReflectionNamedType) {
			if (in_array($valueType->getName(), ["int", "float", "string", "bool"], true)) {
				// passthrough
			} else if ($valueType->getName() === "object") {
				$value = json_decode($value);
			} else if ($valueType->getName() === "array") {
				if ($column->itemType === null) {
					$value = json_decode($value);
				} else {
					$nestedTable = $this->registry->get($column->itemType);
					$array = [];
					foreach (json_decode($value) as $jsonObject) {
						$nestedObject = $nestedTable->reflection->newInstanceWithoutConstructor();
						foreach ($nestedTable->columns as $nestedColumn) {
							$nestedColumn->reflection->setValue(
								$nestedObject,
								$this->databaseToObject($nestedTable, $nestedColumn, $jsonObject->{$nestedColumn->name}),
							);
						}
						$array[] = $nestedObject;
					}
					$value = $array;
				}
			} else if ($valueType->getName() === DateTime::class) {
				$value = DateTime::createFromFormat($this->dateTimeFormat, $value, $this->dateTimeZone);
			} else if ($valueType->getName() === DateTimeImmutable::class) {
				$value = DateTimeImmutable::createFromFormat($this->dateTimeFormat, $value, $this->dateTimeZone);
			} else if (null !== ($nestedTable = $this->registry->maybeGet($valueType->getName()))) {
				$jsonObject = json_decode($value);
				$nestedObject = $nestedTable->reflection->newInstanceWithoutConstructor();
				foreach ($nestedTable->columns as $nestedColumn) {
					$nestedColumn->reflection->setValue(
						$nestedObject,
						$this->databaseToObject($nestedTable, $nestedColumn, $jsonObject->{$nestedColumn->name}),
					);
				}
				$value = $nestedObject;
			} else {
				throw new ConverterException(sprintf(
					"Unsupported type [%s] of property [%s::\$%s].",
					$valueType->getName(),
					$table->reflection->getName(),
					$column->reflection->getName()
				));
			}
		} else {
			throw new ConverterException(sprintf(
				"Property [%s::\$%s] must have a named type declaration (union and intersect declarations are not supported).",
				$table->reflection->getName(),
				$column->reflection->getName()
			));
		}

		return $value;
	}
}
