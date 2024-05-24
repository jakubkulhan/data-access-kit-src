<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Method;

use DataAccessKit\Repository\Attribute\SQL;
use DataAccessKit\Repository\Attribute\SQLFile;
use DataAccessKit\Repository\MethodCompilerInterface;
use DataAccessKit\Repository\Result;
use DataAccessKit\Repository\ResultMethod;
use LogicException;
use function dirname;
use function file_get_contents;
use function preg_match;
use function preg_quote;
use function rtrim;
use function sprintf;
use function var_dump;
use const DIRECTORY_SEPARATOR;

/**
 * @implements MethodCompilerInterface<SQLFile>
 */
class SQLFileMethodCompiler implements MethodCompilerInterface
{
	public function __construct(
		private readonly SQLMethodCompiler $sqlMethodCompiler,
	)
	{
	}

	public function compile(Result $result, ResultMethod $method, $attribute): void
	{
		$file = $attribute->file;
		if ($file === "") {
			throw new LogicException(sprintf(
				"SQL file for method [%s:%s] is blank, please specify a file path.",
				$result->reflection->getName(),
				$method->reflection->getName(),
			));

		}
		if (!preg_match('~^(\w+://)?(\w:)?' . preg_quote(DIRECTORY_SEPARATOR, '~') . '~', $file)) {
			$file = dirname($result->reflection->getFileName()) . DIRECTORY_SEPARATOR . $file;
		}

		$contents = file_get_contents($file);
		if ($contents === false) {
			throw new LogicException(sprintf(
				"SQL file for method [%s:%s] does not exist or is not readable.",
				$result->reflection->getName(),
				$method->reflection->getName(),
			));
		}

		$this->sqlMethodCompiler->compile($result, $method, new SQL(rtrim($contents), $attribute->itemType));
	}
}
