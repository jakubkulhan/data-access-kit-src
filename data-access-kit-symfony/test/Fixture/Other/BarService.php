<?php declare(strict_types=1);

namespace DataAccessKit\Symfony\Fixture\Other;

class BarService
{
	public function __construct(
		public readonly BarEmptyRepositoryInterface $barEmptyRepository,
		public readonly BarRepositoryInterface $barRepository,
	)
	{
	}
}
