<?php declare(strict_types=1);

namespace DataAccessKit\Converter;

use ReflectionClass;
use ReflectionProperty;
use function preg_replace;
use function strtolower;

class DefaultNameConverter implements NameConverterInterface
{

	public function __construct()
	{
	}

	/**
	 * @param ReflectionClass<object>|string $reflection
	 */
	public function classToTable(ReflectionClass|string $reflection): string
	{
		if ($reflection instanceof ReflectionClass) {
			$name = $reflection->getShortName();
		} else {
			$name = $reflection;
		}

		return static::underscore($name) . 's';
	}

	public function propertyToColumn(ReflectionProperty|string $reflection): string
	{
		if ($reflection instanceof ReflectionProperty) {
			$name = $reflection->getName();
		} else {
			$name = $reflection;
		}

		return static::underscore($name);
	}

	public static function underscore(string $s): string
	{
		$s = preg_replace('/(?<!^|[A-Z])[A-Z]/', '_$0', $s);
		$s = preg_replace('/^([A-Z]+)([A-Z])([a-z])/', '$1_$2$3', $s);
		return strtolower($s);
	}

}
