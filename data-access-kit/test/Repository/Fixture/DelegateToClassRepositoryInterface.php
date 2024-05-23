<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Delegate;
use DataAccessKit\Repository\Attribute\Repository;

#[Repository(Foo::class)]
interface DelegateToClassRepositoryInterface
{
	#[Delegate(DeepThought::class)]
	public function computeTheAnswer(): int;

	#[Delegate(DeepThought::class, "computeTheAnswer")]
	public function alias(): int;

	#[Delegate(DeepThought::class, "multiply")]
	public function withArguments(int $a, int $b): int;
}
