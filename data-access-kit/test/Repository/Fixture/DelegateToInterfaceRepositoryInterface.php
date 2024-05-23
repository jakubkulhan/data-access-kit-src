<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Delegate;
use DataAccessKit\Repository\Attribute\Repository;

#[Repository(Foo::class)]
interface DelegateToInterfaceRepositoryInterface
{
	#[Delegate(DeepThoughtInterface::class)]
	public function computeTheAnswer(): int;

	#[Delegate(DeepThoughtInterface::class, "computeTheAnswer")]
	public function alias(): int;

	#[Delegate(DeepThoughtInterface::class, "multiply")]
	public function withArguments(int $a, int $b): int;
}
