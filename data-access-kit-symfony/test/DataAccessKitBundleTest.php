<?php declare(strict_types=1);

namespace DataAccessKit\Symfony;

use DataAccessKit\Persistence;
use DataAccessKit\Repository\Compiler;
use DataAccessKit\Symfony\Fixture\Default\FooRepository;
use DataAccessKit\Symfony\Fixture\Default\FooRepositoryInterface;
use DataAccessKit\Symfony\Fixture\Default\FooService;
use DataAccessKit\Symfony\Fixture\Other\BarEmptyRepositoryInterface;
use DataAccessKit\Symfony\Fixture\Other\BarRepositoryInterface;
use DataAccessKit\Symfony\Fixture\Other\BarService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;
use function bin2hex;
use function random_bytes;

#[Group("unit")]
#[RunTestsInSeparateProcesses]
class DataAccessKitBundleTest extends TestCase
{

	private function createKernel(callable $configureContainer)
	{
		$fs = new Filesystem();
		$projectDir = __DIR__ . "/_tmp_project_" . bin2hex(random_bytes(4));
		$fs->mkdir($projectDir);

		return new class("test", true, $projectDir, $configureContainer) extends Kernel {

			public function __construct(
				string $environment,
				bool $debug,
				private readonly string $projectDir,
				private $configureContainer,
			)
			{
				parent::__construct($environment, $debug);
			}


			public function getProjectDir(): string
			{
				return $this->projectDir;
			}

			public function registerBundles(): iterable
			{
				yield new DataAccessKitBundle();
			}

			public function registerContainerConfiguration(LoaderInterface $loader)
			{
				$loader->load($this->configureContainer);
			}
		};
	}

	private function createDummyConnectionDefinition(): Definition
	{
		return (new Definition(Connection::class))
			->setFactory([DriverManager::class, "getConnection"])
			->setArguments([
				(new DsnParser())->parse("pdo-sqlite:///:memory:"),
			])
			->setPublic(true);
	}

	public function testSingleDatabase(): void
	{

		$kernel = $this->createKernel(function (ContainerBuilder $container) {
			$container->setDefinition(Connection::class, $this->createDummyConnectionDefinition());

			$container->setDefinition(
				FooService::class,
				(new Definition(FooService::class))
					->setAutowired(true)
					->setPublic(true),
			);

			$container->loadFromExtension("data_access_kit", [
				"paths" => [
					[
						"path" => __DIR__ . "/Fixture/Default",
						"namespace" => (new ReflectionClass(DataAccessKitBundleTest::class))->getNamespaceName() . "\\Fixture\\Default",
					],
				],
			]);
		});
		try {
			$repositoryFile = $kernel->getProjectDir() . "/var/cache/test/DataAccessKit/DataAccessKit/Symfony/Fixture/Default/FooRepository.php";

			$this->assertFileDoesNotExist($repositoryFile);

			$kernel->boot();

			$this->assertFileExists($repositoryFile);
			$this->assertFileExists($repositoryFile . ".meta");

			/** @var FooService $fooService */
			$fooService = $kernel->getContainer()->get(FooService::class);
			$this->assertInstanceOf(FooRepositoryInterface::class, $fooService->fooRepository);

		} finally {
			(new Filesystem())->remove($kernel->getProjectDir());
		}
	}

	public function testMultipleDatabases(): void
	{
		$kernel = $this->createKernel(function (ContainerBuilder $container) {
			$container->setDefinition("doctrine.dbal.default_connection", $this->createDummyConnectionDefinition());
			$container->setDefinition("doctrine.dbal.other_connection", $this->createDummyConnectionDefinition());

			$container->setDefinition(
				FooService::class,
				(new Definition(FooService::class))
					->setAutowired(true)
					->setPublic(true),
			);

			$container->setDefinition(
				BarService::class,
				(new Definition(BarService::class))
					->setAutowired(true)
					->setPublic(true),
			);

			$container->loadFromExtension("data_access_kit", [
				"databases" => [
					"default" => [
						"connection" => "doctrine.dbal.default_connection",
					],
					"other" => [
						"connection" => "doctrine.dbal.other_connection",
					],
				],
				"paths" => [
					[
						"path" => __DIR__ . "/Fixture/Default",
						"namespace" => (new ReflectionClass(DataAccessKitBundleTest::class))->getNamespaceName() . "\\Fixture\\Default",
					],
					[
						"path" => __DIR__ . "/Fixture/Other",
						"namespace" => (new ReflectionClass(DataAccessKitBundleTest::class))->getNamespaceName() . "\\Fixture\\Other",
					]
				],
			]);
		});
		try {
			$fooRepositoryFile = $kernel->getProjectDir() . "/var/cache/test/DataAccessKit/DataAccessKit/Symfony/Fixture/Default/FooRepository.php";
			$this->assertFileDoesNotExist($fooRepositoryFile);

			$barRepositoryFile = $kernel->getProjectDir() . "/var/cache/test/DataAccessKit/DataAccessKit/Symfony/Fixture/Other/BarRepository.php";
			$this->assertFileDoesNotExist($barRepositoryFile);

			$kernel->boot();

			$this->assertFileExists($fooRepositoryFile);
			$this->assertFileExists($fooRepositoryFile . ".meta");
			$this->assertFileExists($barRepositoryFile);
			$this->assertFileExists($barRepositoryFile . ".meta");

			$connectionRP = (new ReflectionClass(Persistence::class))->getProperty("connection");

			/** @var FooService $fooService */
			$fooService = $kernel->getContainer()->get(FooService::class);
			$this->assertInstanceOf(FooRepositoryInterface::class, $fooService->fooRepository);

			$fooRC = new ReflectionClass($fooService->fooRepository);
			$fooRP = $fooRC->getProperty(Compiler::PERSISTENCE_PROPERTY);

			$this->assertSame(
				$kernel->getContainer()->get("doctrine.dbal.default_connection"),
				$connectionRP->getValue($fooRP->getValue($fooService->fooRepository)),
			);

			/** @var BarService $barService */
			$barService = $kernel->getContainer()->get(BarService::class);
			$this->assertInstanceOf(BarEmptyRepositoryInterface::class, $barService->barEmptyRepository);
			$this->assertInstanceOf(BarRepositoryInterface::class, $barService->barRepository);

			$barRC = new ReflectionClass($barService->barRepository);
			$barRP = $barRC->getProperty(Compiler::PERSISTENCE_PROPERTY);

			$this->assertNotSame($fooRP->getValue($fooService->fooRepository), $barRP->getValue($barService->barRepository));

			$this->assertSame(
				$kernel->getContainer()->get("doctrine.dbal.other_connection"),
				$connectionRP->getValue($barRP->getValue($barService->barRepository)),
			);

		} finally {
			(new Filesystem())->remove($kernel->getProjectDir());
		}
	}

}
