<?php declare(strict_types=1);

namespace DataAccessKit;

use ReflectionClass;
use ReflectionProperty;
use function preg_replace;
use function strtolower;

class DefaultNameConverter implements NameConverterInterface
{

	public function __construct()
	{
	}

	public function classToTable(ReflectionClass $reflection): string
	{
		$tableName = preg_replace('/(?<!^|[A-Z])[A-Z]/', '_$0', $reflection->getShortName());
		$tableName = strtolower($tableName);
		$tableName .= 's';
		return $tableName;
	}

	public function propertyToColumn(ReflectionProperty $reflection): string
	{
		$columnName = preg_replace('/(?<!^|[A-Z])[A-Z]/', '_$0', $reflection->getName());
		$columnName = strtolower($columnName);
		return $columnName;
	}

}
