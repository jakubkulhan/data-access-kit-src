<?php declare(strict_types=1);

namespace DataAccessKit\Converter;

use DataAccessKit\Attribute\Column;
use DataAccessKit\Attribute\Table;

interface ValueConverterInterface
{
	public function objectToDatabase(Table $table, Column $column, mixed $value): mixed;
	public function databaseToObject(Table $table, Column $column, mixed $value): mixed;
}
