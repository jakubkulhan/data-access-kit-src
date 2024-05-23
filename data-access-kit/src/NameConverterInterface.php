<?php declare(strict_types=1);

namespace DataAccessKit;

use ReflectionClass;
use ReflectionProperty;

interface NameConverterInterface
{
	public function __construct();
	public function classToTable(ReflectionClass $reflection): string;
	public function propertyToColumn(ReflectionProperty $reflection): string;
}
