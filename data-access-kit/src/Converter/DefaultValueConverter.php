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
use ReflectionEnum;
use BackedEnum;
use UnitEnum;
use stdClass;
use function enum_exists;
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

	public function objectToDatabase(Table $table, Column $column, mixed $value, bool $encode = true): mixed
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
					$returnValue = $value;
				} else if ($valueType->getName() === "object") {
					$returnValue = $encode ? json_encode($value) : $value;
				} else if ($valueType->getName() === "array") {
					if ($column->itemType === null) {
						$returnValue = $encode ? json_encode($value) : $value;
					} else {
						$nestedTable = $this->registry->get($column->itemType);
						$jsonArray = [];
						if (!is_iterable($value)) {
							throw new ConverterException("Expected iterable value for array type");
						}
						foreach ($value as $item) {
							if (!is_object($item)) {
								throw new ConverterException("Expected object in array value");
							}
							$jsonArray[] = $jsonObject = new stdClass();
							foreach ($nestedTable->columns as $nestedColumn) {
								$jsonObject->{$nestedColumn->name} = $this->objectToDatabase(
									$nestedTable,
									$nestedColumn,
									$nestedColumn->reflection->getValue($item),
									false,
								);
							}
						}
						$returnValue = $encode ? json_encode($jsonArray) : $jsonArray;
					}
				} else if (in_array($valueType->getName(), [DateTime::class, DateTimeImmutable::class], true)) {
					/** @var DateTime|DateTimeImmutable $value */
					$returnValue = (clone $value)->setTimezone($this->dateTimeZone)->format($this->dateTimeFormat);
				} else if (enum_exists($valueType->getName())) {
					/** @var BackedEnum|UnitEnum $value */
					if ($value instanceof BackedEnum) {
						$returnValue = $value->value;
					} else {
						// Unit enum - store the name
						$returnValue = $value->name;
					}
				} else if (null !== ($nestedTable = $this->registry->maybeGet($valueType->getName()))) {
					if (!is_object($value)) {
						throw new ConverterException("Expected object value for nested type");
					}
					$jsonObject = new stdClass();
					foreach ($nestedTable->columns as $nestedColumn) {
						$jsonObject->{$nestedColumn->name} = $this->objectToDatabase(
							$nestedTable,
							$nestedColumn,
							$nestedColumn->reflection->getValue($value),
							false,
						);
					}
					$returnValue = $encode ? json_encode($jsonObject) : $jsonObject;
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

			return $returnValue;

		} finally {
			if ($recursionGuardKey !== null) {
				unset($this->recursionGuard[$recursionGuardKey]);
			}
		}
	}

	public function databaseToObject(Table $table, Column $column, mixed $value, bool $decode = true): mixed
	{
		if ($value === null) {
			return null;
		}

		$valueType = $column->reflection->getType();
		if ($valueType instanceof ReflectionNamedType) {
			if (in_array($valueType->getName(), ["int", "float", "string", "bool"], true)) {
				$returnValue = $value;
			} else if ($valueType->getName() === "object") {
				if (!is_string($value)) {
					throw new ConverterException("Expected string value for JSON decoding");
				}
				/** @var string $value */
				$returnValue = $decode ? json_decode($value) : $value;
			} else if ($valueType->getName() === "array") {
				if ($column->itemType === null) {
					if (!is_string($value)) {
						throw new ConverterException("Expected string value for JSON decoding");
					}
					/** @var string $value */
					$returnValue = $decode ? json_decode($value) : $value;
				} else {
					$nestedTable = $this->registry->get($column->itemType);
					$array = [];
					if ($decode && !is_string($value)) {
						throw new ConverterException("Expected string value for JSON decoding");
					}
					/** @var string $value */
					$decodedValue = $decode ? json_decode($value) : $value;
					if (!is_iterable($decodedValue)) {
						throw new ConverterException("Expected iterable value for array type");
					}
					foreach ($decodedValue as $jsonObject) {
						$nestedObject = $nestedTable->reflection->newInstanceWithoutConstructor();
						foreach ($nestedTable->columns as $nestedColumn) {
							$nestedColumn->reflection->setValue(
								$nestedObject,
								$this->databaseToObject(
									$nestedTable,
									$nestedColumn,
									$jsonObject->{$nestedColumn->name},
									false,
								),
							);
						}
						$array[] = $nestedObject;
					}
					$returnValue = $array;
				}
			} else if (in_array($valueType->getName(), [DateTime::class, DateTimeImmutable::class], true)) {
				/** @var class-string<DateTime|DateTimeImmutable> $className */
				$className = $valueType->getName();
				if (!is_string($value)) {
					throw new ConverterException("Expected string value for DateTime conversion");
				}
				$returnValue = $className::createFromFormat($this->dateTimeFormat, $value, $this->dateTimeZone);
				if ($returnValue === false) {
					try {
						$returnValue = new $className($value, $this->dateTimeZone);
					} catch (\Exception $e) {
						throw new ConverterException(sprintf(
							"Could not parse datetime value [%s] for property [%s::\$%s].",
							(string) $value,
							$table->reflection->getName(),
							$column->reflection->getName()
						));
					}
				}
			} else if (enum_exists($valueType->getName())) {
				/** @var class-string<BackedEnum|UnitEnum> $className */
				$className = $valueType->getName();
				$enumReflection = new ReflectionEnum($className);
				
				if ($enumReflection->isBacked()) {
					// Backed enum - use from() method
					/** @var class-string<BackedEnum> $className */
					if (!is_int($value) && !is_string($value)) {
						throw new ConverterException("Expected int or string value for backed enum");
					}
					$returnValue = $className::from($value);
				} else {
					// Unit enum - find case by name
					$cases = $enumReflection->getCases();
					$returnValue = null;
					foreach ($cases as $case) {
						if ($case->getName() === $value) {
							$returnValue = $case->getValue();
							break;
						}
					}
					if ($returnValue === null) {
						$valueStr = is_scalar($value) ? (string) $value : get_debug_type($value);
						throw new ConverterException(sprintf(
							"Could not find enum case [%s] for enum [%s] in property [%s::\$%s].",
							$valueStr,
							$className,
							$table->reflection->getName(),
							$column->reflection->getName()
						));
					}
				}
			} else if (null !== ($nestedTable = $this->registry->maybeGet($valueType->getName()))) {
				if ($decode && !is_string($value)) {
					throw new ConverterException("Expected string value for JSON decoding");
				}
				/** @var string $value */
				$jsonObject = $decode ? json_decode($value) : $value;
				$nestedObject = $nestedTable->reflection->newInstanceWithoutConstructor();
				foreach ($nestedTable->columns as $nestedColumn) {
					$nestedColumn->reflection->setValue(
						$nestedObject,
						$this->databaseToObject(
							$nestedTable,
							$nestedColumn,
							$jsonObject->{$nestedColumn->name},
							false,
						),
					);
				}
				$returnValue = $nestedObject;
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

		return $returnValue;
	}
}
