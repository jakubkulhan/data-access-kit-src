<?php declare(strict_types=1);

namespace DataAccessKit\Symfony\Fixture\Default;

class FooService
{
	public function __construct(
		public readonly FooRepositoryInterface $fooRepository,
	)
	{
	}
}
