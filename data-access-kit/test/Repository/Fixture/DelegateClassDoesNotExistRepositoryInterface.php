<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Delegate;
use DataAccessKit\Repository\Attribute\Repository;

#[Repository(Foo::class)]
interface DelegateClassDoesNotExistRepositoryInterface
{
	#[Delegate('This\Class\Does\Not\Exist')]
	public function foo();
}
