<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Fixture;

use DataAccessKit\Repository\Attribute\Repository;

#[Repository(Foo::class)]
#[PassAttribute("s", 1, a: ["a"])]
interface PassClassAttributesRepositoryInterface
{
}
