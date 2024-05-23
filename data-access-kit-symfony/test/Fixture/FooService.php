<?php declare(strict_types=1);

namespace DataAccessKit\Symfony\Fixture;

class FooService
{
	public function __construct(
		private readonly FooRepositoryInterface $fooRepository,
	)
	{
	}
}
