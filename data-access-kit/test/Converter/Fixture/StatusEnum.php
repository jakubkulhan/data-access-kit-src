<?php declare(strict_types=1);

namespace DataAccessKit\Converter\Fixture;

enum StatusEnum: string
{
	case Active = 'active';
	case Inactive = 'inactive';
	case Pending = 'pending';
} 