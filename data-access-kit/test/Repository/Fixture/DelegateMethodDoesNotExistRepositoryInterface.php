<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Delegate;
use DataAccessKit\Repository\Attribute\Repository;

#[Repository(Foo::class)]
interface DelegateMethodDoesNotExistRepositoryInterface
{
	#[Delegate(DeepThought::class, method: "thisMethodDoesNotExist")]
	public function computeTheAnswer(): int;
}
