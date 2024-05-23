<?php declare(strict_types=1);

namespace DataAccessKit\Repository;

/**
 * @template T
 */
interface MethodCompilerInterface
{
	/**
	 * @param T $attribute
	 */
	public function compile(Result $result, ResultMethod $method, $attribute): void;
}
