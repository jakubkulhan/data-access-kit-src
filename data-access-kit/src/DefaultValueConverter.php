<?php declare(strict_types=1);

namespace DataAccessKit;

use DateTimeImmutable;
use DateTimeZone;
use DataAccessKit\Attribute\Column;
use DataAccessKit\Attribute\Table;
use ReflectionNamedType;
use function in_array;
use function json_decode;
use function json_encode;

class DefaultValueConverter implements ValueConverterInterface
{

	public function objectToDatabase(Table $table, Column $column, mixed $value): mixed
	{
		if ($value === null) {
			return null;
		}

		$valueType = $column->reflection->getType();
		if ($valueType instanceof ReflectionNamedType && in_array($valueType->getName(), ["array", "object"], true)) {
			$value = json_encode($value);
		} else if ($valueType instanceof ReflectionNamedType && in_array($valueType->getName(), ["DateTime", "DateTimeImmutable"], true)) {
			$value = $value->format("Y-m-d H:i:s");
		}

		return $value;
	}

	public function databaseToObject(Table $table, Column $column, mixed $value): mixed
	{
		if ($value === null) {
			return null;
		}

		$valueType = $column->reflection->getType();
		if ($valueType instanceof ReflectionNamedType && in_array($valueType->getName(), ["array", "object"])) {
			$value = json_decode($value, true);
		} else if ($valueType instanceof ReflectionNamedType && in_array($valueType->getName(), ["DateTime", "DateTimeImmutable"], true)) {
			$value = new DateTimeImmutable($value, new DateTimeZone("UTC"));
		}

		return $value;
	}

}
