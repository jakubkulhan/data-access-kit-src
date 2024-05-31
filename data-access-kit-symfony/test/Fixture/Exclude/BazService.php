<?php declare(strict_types=1);

namespace DataAccessKit\Symfony\Fixture\Exclude;

class BazService
{
	public function __construct(
		public readonly BazRepositoryInterface $bazRepository,
	)
	{
	}
}
