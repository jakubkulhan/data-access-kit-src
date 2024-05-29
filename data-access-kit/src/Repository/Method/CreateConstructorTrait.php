<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Method;

use DataAccessKit\PersistenceInterface;
use DataAccessKit\Repository\Result;
use DataAccessKit\Repository\ResultMethod;

trait CreateConstructorTrait
{
	public function createConstructorWithPersistenceProperty(Result $result): ResultMethod
	{
		$constructor = $result->method("__construct");
		$constructor->parameter("persistence")
			->setVisibility("private readonly")
			->setType($result->use(PersistenceInterface::class));

		return $constructor;
	}
}
