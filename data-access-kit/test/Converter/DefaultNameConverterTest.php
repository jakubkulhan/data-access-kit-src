<?php declare(strict_types=1);

namespace DataAccessKit\Converter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group("unit")]
class DefaultNameConverterTest extends TestCase
{

	private DefaultNameConverter $converter;

	protected function setUp(): void
	{
		$this->converter = new DefaultNameConverter();
	}

	#[DataProvider("classToTableData")]
	public function testClassToTable(string $classShortName, string $tableName): void
	{
		$this->assertEquals($tableName, $this->converter->classToTable($classShortName));
	}

	public static function classToTableData()
	{
		$data = [
			"Feedback" => "feedbacks",
			"Person" => "persons",
			"URL" => "urls",
			"URLShortener" => "url_shorteners",
			"User" => "users",
			"UserGroup" => "user_groups",
			"User_Group" => "user__groups",
			"pattern" => "patterns",
		];
		foreach ($data as $classShortName => $tableName) {
			yield $classShortName => [$classShortName, $tableName];
		}
	}

	#[DataProvider("propertyToColumnData")]
	public function testPropertyToColumn(string $propertyName, string $columnName): void
	{
		$this->assertEquals($columnName, $this->converter->propertyToColumn($propertyName));
	}

	public static function propertyToColumnData()
	{
		$data = [
			"id" => "id",
			"ID" => "id",
			"userId" => "user_id",
			"name" => "name",
			"fullName" => "full_name",
			"URL" => "url",
			"URLPattern" => "url_pattern",
		];
		foreach ($data as $propertyName => $columnName) {
			yield $propertyName => [$propertyName, $columnName];
		}
	}

}
