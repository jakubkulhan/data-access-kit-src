<?php declare(strict_types=1);

namespace DataAccessKit\Symfony;

use DataAccessKit\Persistence;
use DataAccessKit\Symfony\Fixture\DummyPersistence;
use DataAccessKit\Symfony\Fixture\FooRepository;
use DataAccessKit\Symfony\Fixture\FooRepositoryInterface;
use DataAccessKit\Symfony\Fixture\FooService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;
use function bin2hex;
use function class_exists;
use function random_bytes;

class DataAccessKitBundleTest extends TestCase
{

	public function testLoadExtension(): void
	{
		$fs = new Filesystem();
		$projectDir = __DIR__ . "/_tmp_project_" . bin2hex(random_bytes(4));
		$fs->mkdir($projectDir);
		try {
			$kernel = new class("test", true, $projectDir) extends Kernel {

				public function __construct(
					string $environment,
					bool $debug,
					private readonly string $projectDir,
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
					$loader->load(function (ContainerBuilder $container) {
						$persistenceDef = new Definition(DummyPersistence::class);
						$container->setDefinition(Persistence::class, $persistenceDef);

						$svcDef = new Definition(FooService::class);
						$svcDef->setAutowired(true);
						$svcDef->setPublic(true);
						$container->setDefinition(FooService::class, $svcDef);

						$container->loadFromExtension("data_access_kit", [
							"paths" => [
								[
									"path" => __DIR__ . "/Fixture",
									"namespace" => (new ReflectionClass(DataAccessKitBundleTest::class))->getNamespaceName() . "\\Fixture",
								],
							],
						]);
					});
				}
			};

			$repositoryFile = $kernel->getProjectDir() . "/var/cache/test/DataAccessKit/DataAccessKit/Symfony/Fixture/FooRepository.php";

			$this->assertFileDoesNotExist($repositoryFile);

			$kernel->boot();

			$this->assertFileExists($repositoryFile);
			$this->assertFileExists($repositoryFile . ".meta");
			require_once $repositoryFile;
			$this->assertTrue(class_exists(FooRepository::class));
			$rc = new ReflectionClass(FooRepository::class);
			$this->assertTrue($rc->implementsInterface(FooRepositoryInterface::class));

			$container = $kernel->getContainer();
			$this->assertTrue($container->has(FooService::class));

		} finally {
			$fs->remove($projectDir);
		}
	}

}
