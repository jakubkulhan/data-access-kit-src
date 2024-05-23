<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

interface DeepThoughtInterface
{
	public function computeTheAnswer(): int;

	public function multiply(int $a, int $b): int;
}
