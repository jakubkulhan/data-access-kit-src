<?php declare(strict_types=1);

namespace DataAccessKit\Repository\Method;

use DataAccessKit\Repository\Attribute\SQL;
use DataAccessKit\Repository\Attribute\SQLFile;
use DataAccessKit\Repository\Exception\CompilerException;
use DataAccessKit\Repository\MethodCompilerInterface;
use DataAccessKit\Repository\Result;
use DataAccessKit\Repository\ResultMethod;
use function dirname;
use function file_get_contents;
use function preg_match;
use function preg_quote;
use function rtrim;
use function sprintf;
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
			throw new CompilerException(sprintf(
				"SQL file for method [%s::%s] is blank, please specify a file path.",
				$result->reflection->getName(),
				$method->reflection->getName(),
			));

		}
		if (!preg_match('~^(\w+://)?(\w:)?' . preg_quote(DIRECTORY_SEPARATOR, '~') . '~', $file)) {
			$fileName = $result->reflection->getFileName();
			if ($fileName === false) {
				throw new CompilerException(sprintf(
					"Cannot determine file path for SQL file [%s] in method [%s::%s] - reflection has no filename.",
					$file,
					$result->reflection->getName(),
					$method->reflection->getName(),
				));
			}
			$file = dirname($fileName) . DIRECTORY_SEPARATOR . $file;
		}

		$contents = @file_get_contents($file);
		if ($contents === false) {
			throw new CompilerException(sprintf(
				"SQL file for method [%s::%s] does not exist or is not readable.",
				$result->reflection->getName(),
				$method->reflection->getName(),
			));
		}

		$this->sqlMethodCompiler->compile($result, $method, new SQL(rtrim($contents), $attribute->itemType));
	}
}
