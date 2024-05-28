<?php declare(strict_types=1);

namespace DataAccessKit\Repository;

use DataAccessKit\DefaultNameConverter;
use DataAccessKit\Registry;
use DataAccessKit\Repository\Exception\CompilerException;
use DataAccessKit\Repository\Fixture\AbsoluteSQLFileRepositoryInterface;
use DataAccessKit\Repository\Fixture\CountRepositoryInterface;
use DataAccessKit\Repository\Fixture\DelegateToClassRepositoryInterface;
use DataAccessKit\Repository\Fixture\DelegateToInterfaceRepositoryInterface;
use DataAccessKit\Repository\Fixture\DelegateToTraitRepositoryInterface;
use DataAccessKit\Repository\Fixture\EmptyRepositoryInterface;
use DataAccessKit\Repository\Fixture\FindArrayRepositoryInterface;
use DataAccessKit\Repository\Fixture\FindIterableRepositoryInterface;
use DataAccessKit\Repository\Fixture\GetRepositoryInterface;
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
use DataAccessKit\Repository\Fixture\TableMacroRepositoryInterface;
use DataAccessKit\Repository\Fixture\VariableSQLRepositoryInterface;
use DataAccessKit\Repository\Fixture\VoidSQLRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Spatie\Snapshots\MatchesSnapshots;
use function dirname;
use function lcfirst;
use function str_replace;
use function strrpos;
use function substr;

class CompilerTest extends TestCase
{

	use MatchesSnapshots;

	private Compiler $compiler;

	protected function setUp(): void
	{
		$this->compiler = new Compiler(new Registry(new DefaultNameConverter()));
	}

	public function testCompileAcceptsOnlyInterface()
	{
		$this->expectException(CompilerException::class);
		$this->expectExceptionMessage("must be an interface");
		$this->compiler->compile($this->compiler->prepare(CompilerTest::class));
	}

	public function testCompileAcceptsOnlyInterfaceWithAttribute()
	{
		$this->expectException(CompilerException::class);
		$this->expectExceptionMessage("must have a #[\\DataAccessKit\\Repository\\Attribute\\Repository] attribute");
		$this->compiler->compile($this->compiler->prepare(NoAttributeInterface::class));
	}

	#[DataProvider("provideCompile")]
	public function testCompile(string $interfaceName)
	{
		$this->assertMatchesSnapshot((string) $this->compiler->compile($this->compiler->prepare($interfaceName)));
	}

	public static function provideCompile()
	{
		$classes = [
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
			NullableScalarSQLRepositoryInterface::class,
			VoidSQLRepositoryInterface::class,
			TableMacroRepositoryInterface::class,
			AbsoluteSQLFileRepositoryInterface::class,
			RelativeSQLFileRepositoryInterface::class,
			DelegateToClassRepositoryInterface::class,
			DelegateToInterfaceRepositoryInterface::class,
			DelegateToTraitRepositoryInterface::class,
		];

		foreach ($classes as $class) {
			$p = strrpos($class, "\\");
			yield lcfirst(str_replace("RepositoryInterface", "", substr($class, $p + 1))) => [$class];
		}
	}

	protected function getSnapshotDirectory(): string
	{
		return dirname((new ReflectionClass($this))->getFileName()) . "/Snapshot";
	}

	protected function getSnapshotId(): string
	{
		return (new ReflectionClass($this))->getShortName() . '.' .
			$this->name() . '.' .
			$this->dataName();
	}

}
