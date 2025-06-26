<?php declare(strict_types=1);

namespace DataAccessKit\Converter;

use ReflectionClass;
use ReflectionProperty;

interface NameConverterInterface
{
	public function __construct();
	/**
	 * @param ReflectionClass<object> $reflection
	 */
	public function classToTable(ReflectionClass $reflection): string;
	public function propertyToColumn(ReflectionProperty $reflection): string;
}
