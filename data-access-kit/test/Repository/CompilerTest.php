<?php declare(strict_types=1);

namespace DataAccessKit\Repository;

use DataAccessKit\Converter\DefaultNameConverter;
use DataAccessKit\Registry;
use DataAccessKit\Repository\Exception\CompilerException;
use DataAccessKit\Repository\Fixture\AbsoluteSQLFileRepositoryInterface;
use DataAccessKit\Repository\Fixture\CountBadParameterNameRepositoryInterface;
use DataAccessKit\Repository\Fixture\CountBadReturnTypeRepositoryInterface;
use DataAccessKit\Repository\Fixture\CountRepositoryInterface;
use DataAccessKit\Repository\Fixture\DelegateClassDoesNotExistRepositoryInterface;
use DataAccessKit\Repository\Fixture\DelegateMethodDoesNotExistRepositoryInterface;
use DataAccessKit\Repository\Fixture\DelegateToClassRepositoryInterface;
use DataAccessKit\Repository\Fixture\DelegateToInterfaceRepositoryInterface;
use DataAccessKit\Repository\Fixture\DelegateToTraitRepositoryInterface;
use DataAccessKit\Repository\Fixture\DeleteBadParameterTypeRepositoryInterface;
use DataAccessKit\Repository\Fixture\DeleteRepositoryInterface;
use DataAccessKit\Repository\Fixture\DeleteReturnTypeNonVoidRepositoryInterface;
use DataAccessKit\Repository\Fixture\DeleteTooManyParametersRepositoryInterface;
use DataAccessKit\Repository\Fixture\EmptyFileNameSQLFileRepositoryInterface;
use DataAccessKit\Repository\Fixture\EmptyRepositoryInterface;
use DataAccessKit\Repository\Fixture\FileDoesNotExistSQLFileRepositoryInterface;
use DataAccessKit\Repository\Fixture\FindArrayRepositoryInterface;
use DataAccessKit\Repository\Fixture\FindBadParameterNameRepositoryInterface;
use DataAccessKit\Repository\Fixture\FindBadReturnTypeRepositoryInterface;
use DataAccessKit\Repository\Fixture\FindIterableRepositoryInterface;
use DataAccessKit\Repository\Fixture\GetRepositoryInterface;
use DataAccessKit\Repository\Fixture\InsertBadParameterTypeRepositoryInterface;
use DataAccessKit\Repository\Fixture\InsertRepositoryInterface;
use DataAccessKit\Repository\Fixture\InsertReturnTypeNonVoidRepositoryInterface;
use DataAccessKit\Repository\Fixture\InsertTooManyParametersRepositoryInterface;
use DataAccessKit\Repository\Fixture\MacroColumnsAliasRepositoryInterface;
use DataAccessKit\Repository\Fixture\MacroColumnsExceptAliasRepositoryInterface;
use DataAccessKit\Repository\Fixture\MacroColumnsExceptAllColumnRepositoryInterface;
use DataAccessKit\Repository\Fixture\MacroColumnsExceptMultipleAliasRepositoryInterface;
use DataAccessKit\Repository\Fixture\MacroColumnsExceptMultipleRepositoryInterface;
use DataAccessKit\Repository\Fixture\MacroColumnsExceptRepositoryInterface;
use DataAccessKit\Repository\Fixture\MacroColumnsExceptUnknownColumnRepositoryInterface;
use DataAccessKit\Repository\Fixture\MacroColumnsRepositoryInterface;
use DataAccessKit\Repository\Fixture\MacroTableSQLRepositoryInterface;
use DataAccessKit\Repository\Fixture\MacroUnknownRepositoryInterface;
use DataAccessKit\Repository\Fixture\NoAttributeInterface;
use DataAccessKit\Repository\Fixture\NullableGetRepositoryInterface;
use DataAccessKit\Repository\Fixture\NullableScalarSQLRepositoryInterface;
use DataAccessKit\Repository\Fixture\PassClassAttributesRepositoryInterface;
use DataAccessKit\Repository\Fixture\PassMethodAttributesRepositoryInterface;
use DataAccessKit\Repository\Fixture\RelativeSQLFileRepositoryInterface;
use DataAccessKit\Repository\Fixture\SimpleSQLArrayRepositoryInterface;
use DataAccessKit\Repository\Fixture\SimpleSQLIterableRepositoryInterface;
use DataAccessKit\Repository\Fixture\SimpleSQLNullableObjectRepositoryInterface;
use DataAccessKit\Repository\Fixture\SimpleSQLObjectRepositoryInterface;
use DataAccessKit\Repository\Fixture\UnhandledMethodRepositoryInterface;
use DataAccessKit\Repository\Fixture\UnknownVariableSQLRepositoryInterface;
use DataAccessKit\Repository\Fixture\UnsupportedReturnTypeIntersectRepositoryInterface;
use DataAccessKit\Repository\Fixture\UnsupportedReturnTypeMixedRepositoryInterface;
use DataAccessKit\Repository\Fixture\UnsupportedReturnTypeObjectRepositoryInterface;
use DataAccessKit\Repository\Fixture\UnsupportedReturnTypeUnionRepositoryInterface;
use DataAccessKit\Repository\Fixture\UnusedVariableSQLRepositoryInterface;
use DataAccessKit\Repository\Fixture\UpdateArrayParameterTypeRepositoryInterface;
use DataAccessKit\Repository\Fixture\UpdateBadParameterTypeRepositoryInterface;
use DataAccessKit\Repository\Fixture\UpdateRepositoryInterface;
use DataAccessKit\Repository\Fixture\UpdateReturnTypeNonVoidRepositoryInterface;
use DataAccessKit\Repository\Fixture\UpdateTooManyParametersRepositoryInterface;
use DataAccessKit\Repository\Fixture\UpsertBadParameterTypeRepositoryInterface;
use DataAccessKit\Repository\Fixture\UpsertRepositoryInterface;
use DataAccessKit\Repository\Fixture\UpsertReturnTypeNonVoidRepositoryInterface;
use DataAccessKit\Repository\Fixture\UpsertTooManyParametersRepositoryInterface;
use DataAccessKit\Repository\Fixture\VariableArrayDocCommentMissingParamSQLRepositoryInterface;
use DataAccessKit\Repository\Fixture\VariableArrayNoDocCommentSQLRepositoryInterface;
use DataAccessKit\Repository\Fixture\VariableArraySQLRepositoryInterface;
use DataAccessKit\Repository\Fixture\VariableSQLRepositoryInterface;
use DataAccessKit\Repository\Fixture\VoidSQLRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Spatie\Snapshots\MatchesSnapshots;
use function dirname;
use function sprintf;
use function str_replace;
use function strrpos;
use function substr;

#[Group("unit")]
class CompilerTest extends TestCase
{

	use MatchesSnapshots;

	private Compiler $compiler;

	protected function setUp(): void
	{
		$this->compiler = new Compiler(new Registry(new DefaultNameConverter()));
	}

	#[DataProvider("provideCompile")]
	public function testCompileSuccess(string $interfaceName)
	{
		$this->assertMatchesSnapshot((string) $this->compiler->compile($this->compiler->prepare($interfaceName)));
	}

	public static function provideCompile()
	{
		return static::provideRepositoryClasses([
			EmptyRepositoryInterface::class,
			FindIterableRepositoryInterface::class,
			FindArrayRepositoryInterface::class,
			GetRepositoryInterface::class,
			NullableGetRepositoryInterface::class,
			CountRepositoryInterface::class,
			PassClassAttributesRepositoryInterface::class,
			PassMethodAttributesRepositoryInterface::class,
			SimpleSQLIterableRepositoryInterface::class,
			SimpleSQLArrayRepositoryInterface::class,
			SimpleSQLObjectRepositoryInterface::class,
			SimpleSQLNullableObjectRepositoryInterface::class,
			VariableSQLRepositoryInterface::class,
			VariableArraySQLRepositoryInterface::class,
			NullableScalarSQLRepositoryInterface::class,
			VoidSQLRepositoryInterface::class,
			MacroTableSQLRepositoryInterface::class,
			MacroColumnsRepositoryInterface::class,
			MacroColumnsExceptRepositoryInterface::class,
			MacroColumnsExceptMultipleRepositoryInterface::class,
			MacroColumnsAliasRepositoryInterface::class,
			MacroColumnsExceptAliasRepositoryInterface::class,
			MacroColumnsExceptMultipleAliasRepositoryInterface::class,
			AbsoluteSQLFileRepositoryInterface::class,
			RelativeSQLFileRepositoryInterface::class,
			DelegateToClassRepositoryInterface::class,
			DelegateToInterfaceRepositoryInterface::class,
			DelegateToTraitRepositoryInterface::class,
			InsertRepositoryInterface::class,
			UpsertRepositoryInterface::class,
			UpdateRepositoryInterface::class,
			DeleteRepositoryInterface::class,
		]);
	}

	#[DataProvider("provideCompileError")]
	public function testCompileError(string $interfaceName): void
	{
		try {
			$this->compiler->compile($this->compiler->prepare($interfaceName));
			$this->fail(sprintf("Expected [%s] to be thrown.", CompilerException::class));
		} catch (CompilerException $e) {
			$this->assertMatchesSnapshot($e->getMessage());
		}
	}

	public static function provideCompileError(): iterable
	{
		return static::provideRepositoryClasses([
			CompilerTest::class,
			NoAttributeInterface::class,
			UnusedVariableSQLRepositoryInterface::class,
			FindBadReturnTypeRepositoryInterface::class,
			CountBadReturnTypeRepositoryInterface::class,
			UnhandledMethodRepositoryInterface::class,
			FindBadParameterNameRepositoryInterface::class,
			CountBadParameterNameRepositoryInterface::class,
			DelegateClassDoesNotExistRepositoryInterface::class,
			DelegateMethodDoesNotExistRepositoryInterface::class,
			FileDoesNotExistSQLFileRepositoryInterface::class,
			EmptyFileNameSQLFileRepositoryInterface::class,
			UnsupportedReturnTypeUnionRepositoryInterface::class,
			UnsupportedReturnTypeIntersectRepositoryInterface::class,
			UnsupportedReturnTypeMixedRepositoryInterface::class,
			UnsupportedReturnTypeObjectRepositoryInterface::class,
			UnknownVariableSQLRepositoryInterface::class,
			VariableArrayNoDocCommentSQLRepositoryInterface::class,
			VariableArrayDocCommentMissingParamSQLRepositoryInterface::class,
			MacroColumnsExceptUnknownColumnRepositoryInterface::class,
			MacroColumnsExceptAllColumnRepositoryInterface::class,
			MacroUnknownRepositoryInterface::class,
			InsertReturnTypeNonVoidRepositoryInterface::class,
			InsertTooManyParametersRepositoryInterface::class,
			InsertBadParameterTypeRepositoryInterface::class,
			UpsertReturnTypeNonVoidRepositoryInterface::class,
			UpsertTooManyParametersRepositoryInterface::class,
			UpsertBadParameterTypeRepositoryInterface::class,
			UpdateReturnTypeNonVoidRepositoryInterface::class,
			UpdateTooManyParametersRepositoryInterface::class,
			UpdateBadParameterTypeRepositoryInterface::class,
			UpdateArrayParameterTypeRepositoryInterface::class,
			DeleteReturnTypeNonVoidRepositoryInterface::class,
			DeleteTooManyParametersRepositoryInterface::class,
			DeleteBadParameterTypeRepositoryInterface::class,
		]);
	}

	private static function provideRepositoryClasses(array $classes): iterable
	{
		foreach ($classes as $class) {
			$p = strrpos($class, "\\");
			yield str_replace("Interface", "", substr($class, $p + 1)) => [$class];
		}
	}

	protected function getSnapshotDirectory(): string
	{
		return dirname((new ReflectionClass($this))->getFileName()) . "/Fixture";
	}

	protected function getSnapshotId(): string
	{
		return $this->dataName() . '.snapshot';
	}

}
