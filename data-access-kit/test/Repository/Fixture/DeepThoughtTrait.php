<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

trait DeepThoughtTrait
{

	public function __construct(
		#[PassAttribute(s: "s", i: 1, a: ["a"])]
		private readonly int $answerToTheUltimateQuestionOfLifeTheUniverseAndEverything
	)
	{
	}

	public function computeTheAnswer(): int
	{
		sleep(7_500_000 * 365 * 24 * 60 * 60);
		return $this->answerToTheUltimateQuestionOfLifeTheUniverseAndEverything;
	}

	public function multiply(int $a, int $b): int
	{
		return $a * $b;
	}

}
