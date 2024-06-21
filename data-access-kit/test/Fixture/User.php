<?php declare(strict_types=1);

namespace DataAccessKit\Fixture;

use DataAccessKit\Attribute\Column;
use DataAccessKit\Attribute\Table;

#[Table]
class User
{
	#[Column(name: "user_id", primary: true, generated: true)]
	public int $id;
	#[Column]
	public string $firstName;
	#[Column]
	public string $lastName;
	#[Column(generated: true)]
	public string $fullName;
	#[Column]
	public bool $active;
}
