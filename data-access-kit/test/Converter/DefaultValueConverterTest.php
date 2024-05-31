<?php declare(strict_types=1);

namespace DataAccessKit\Converter;

use DataAccessKit\Attribute\Column;
use DataAccessKit\Attribute\Table;
use DataAccessKit\Converter\Fixture\NestedObject;
use DataAccessKit\Registry;
use DateTime;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

#[Group("unit")]
class DefaultValueConverterTest extends TestCase
{
	private static ?Registry $registry = null;
	private DefaultValueConverter $converter;

	public static function getRegistry(): Registry
	{
		if (static::$registry !== null) {
			return static::$registry;
		}

		return static::$registry = new Registry(new class implements NameConverterInterface {
			public function __construct()
			{
			}

			public function classToTable(ReflectionClass $reflection): string
			{
				return $reflection->getShortName();
			}

			public function propertyToColumn(ReflectionProperty $reflection): string
			{
				return $reflection->getName();
			}
		});
	}

	protected function setUp(): void
	{
		$this->converter = new DefaultValueConverter(static::getRegistry());
	}

	#[DataProvider("data")]
	public function testObjectToDatabase(Table $table, Column $column, mixed $objectValue, mixed $databaseValue): void
	{
		$this->assertEquals($databaseValue, $this->converter->objectToDatabase($table, $column, $objectValue));
	}

	#[DataProvider("data")]
	public function testDatabaseToObject(Table $table, Column $column, mixed $objectValue, mixed $databaseValue): void
	{
		$this->assertEquals($objectValue, $this->converter->databaseToObject($table, $column, $databaseValue));
	}

	public static function data()
	{
		$table = static::getRegistry()->get(new class {
			#[Column] public int $int;
			#[Column] public ?int $nullableIntNull;
			#[Column] public ?int $nullableIntNotNull;
			#[Column] public float $float;
			#[Column] public ?float $nullableFloatNull;
			#[Column] public ?float $nullableFloatNotNull;
			#[Column] public string $string;
			#[Column] public ?string $nullableStringNull;
			#[Column] public ?string $nullableStringNotNull;
			#[Column] public bool $bool;
			#[Column] public ?bool $nullableBoolNull;
			#[Column] public ?bool $nullableBoolNotNull;
			#[Column] public DateTime $dateTime;
			#[Column] public ?DateTime $nullableDateTimeNull;
			#[Column] public ?DateTime $nullableDateTimeNotNull;
			#[Column] public DateTimeImmutable $dateTimeImmutable;
			#[Column] public ?DateTimeImmutable $nullableDateTimeImmutableNull;
			#[Column] public ?DateTimeImmutable $nullableDateTimeImmutableNotNull;
			#[Column] public array $jsonArray;
			#[Column] public ?array $nullableJsonArrayNull;
			#[Column] public ?array $nullableJsonArrayNotNull;
			#[Column] public object $jsonObject;
			#[Column] public ?object $nullableJsonObjectNull;
			#[Column] public ?object $nullableJsonObjectNotNull;
			#[Column] public NestedObject $nestedObject;
			#[Column] public ?NestedObject $nullableNestedObjectNull;
			#[Column] public ?NestedObject $nullableNestedObjectNotNull;
			#[Column(itemType: NestedObject::class)] public array $nestedArray;
			#[Column(itemType: NestedObject::class)] public ?array $nullableNestedArrayNull;
			#[Column(itemType: NestedObject::class)] public ?array $nullableNestedArrayNotNull;
		});

		$data = [
			"int" => [1, 1],
			"nullableIntNull" => [null, null],
			"nullableIntNotNull" => [1, 1],
			"float" => [3.14, 3.14],
			"nullableFloatNull" => [null, null],
			"nullableFloatNotNull" => [3.14, 3.14],
			"string" => ["test", "test"],
			"nullableStringNull" => [null, null],
			"nullableStringNotNull" => ["test", "test"],
			"bool" => [true, true],
			"nullableBoolNull" => [null, null],
			"nullableBoolNotNull" => [true, true],
			"dateTime" => [new DateTime("2024-05-31 00:00:00"), "2024-05-31 00:00:00"],
			"nullableDateTimeNull" => [null, null],
			"nullableDateTimeNotNull" => [new DateTime("2024-05-31 00:00:00"), "2024-05-31 00:00:00"],
			"dateTimeImmutable" => [new DateTimeImmutable("2024-05-31 00:00:00"), "2024-05-31 00:00:00"],
			"nullableDateTimeImmutableNull" => [null, null],
			"nullableDateTimeImmutableNotNull" => [new DateTimeImmutable("2024-05-31 00:00:00"), "2024-05-31 00:00:00"],
			"jsonArray" => [[1, 2, 3], "[1,2,3]"],
			"nullableJsonArrayNull" => [null, null],
			"nullableJsonArrayNotNull" => [[1, 2, 3], "[1,2,3]"],
			"jsonObject" => [(object) ["key" => "value"], '{"key":"value"}'],
			"nullableJsonObjectNull" => [null, null],
			"nullableJsonObjectNotNull" => [(object) ["key" => "value"], '{"key":"value"}'],
			"nestedObject" => [new NestedObject("value"), '{"key":"value"}'],
			"nullableNestedObjectNull" => [null, null],
			"nullableNestedObjectNotNull" => [new NestedObject("value"), '{"key":"value"}'],
			"nestedArray" => [[new NestedObject("value1"), new NestedObject("value2")], '[{"key":"value1"},{"key":"value2"}]'],
			"nullableNestedArrayNull" => [null, null],
			"nullableNestedArrayNotNull" => [[new NestedObject("value1"), new NestedObject("value2")], '[{"key":"value1"},{"key":"value2"}]'],
		];
		foreach ($data as $columnName => [$objectValue, $databaseValue]) {
			yield $columnName => [$table, $table->columns[$columnName], $objectValue, $databaseValue];
		}
	}
}
