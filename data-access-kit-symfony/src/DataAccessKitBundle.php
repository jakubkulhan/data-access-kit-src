<?php declare(strict_types=1);

namespace DataAccessKit\Symfony;

use DataAccessKit\Converter\DefaultNameConverter;
use DataAccessKit\Converter\DefaultValueConverter;
use DataAccessKit\Converter\NameConverterInterface;
use DataAccessKit\Converter\ValueConverterInterface;
use DataAccessKit\Persistence;
use DataAccessKit\PersistenceInterface;
use DataAccessKit\Registry;
use DataAccessKit\Repository\Attribute\Repository;
use DataAccessKit\Repository\Compiler;
use DataAccessKit\Repository\Exception\CompilerException;
use Doctrine\DBAL\Connection;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Resource\ReflectionClassResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use function count;
use function rtrim;
use function strtr;
use function substr;
use const DIRECTORY_SEPARATOR;

class DataAccessKitBundle extends AbstractBundle
{
	public function configure(DefinitionConfigurator $definition): void
	{
		$definition->rootNode()
			->children()
				->scalarNode("default_database")
					->defaultValue(Repository::DEFAULT_DATABASE)
					->info("Name of the default database.")
				->end()
				->arrayNode("databases")
					->useAttributeAsKey("name")
					->requiresAtLeastOneElement()
					->defaultValue([
						Repository::DEFAULT_DATABASE => [
							"connection" => Connection::class,
						],
					])
					->arrayPrototype()
						->children()
							->scalarNode("connection")
								->isRequired()
								->info("Database connection service.")
							->end()
						->end()
					->end()
				->end()
				->scalarNode("name_converter")
					->defaultValue(DefaultNameConverter::class)
					->info("Name converter class to use. Class constructor must not have any parameters.")
				->end()
				->scalarNode("value_converter")
					->defaultValue(DefaultValueConverter::class)
					->info("Value converter class or service to use.")
				->end()
				->arrayNode("repositories")
					->useAttributeAsKey("namespace")
					->requiresAtLeastOneElement()
					->defaultValue([])
					->arrayPrototype()
						->children()
							->scalarNode("path")
								->isRequired()
								->info("Path to the PSR-4 directory containing the classes.")
							->end()
							->arrayNode("exclude")
								->defaultValue([])
								->scalarPrototype()->end()
								->info("List of file patterns to exclude.")
							->end()
						->end()
					->end()
				->end()
				->booleanNode("repositories_public")
					->defaultTrue()
					->info("Make repository services public.")
				->end()
			->end();
	}

	/**
	 * @param array<string, mixed> $config
	 */
	public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
	{
		$services = $container->services();

		$this->configureGlobalServices($config, $services, $builder);

		$this->configureDatabaseServices($config, $services);

		$this->configureRepositoryServices($config, $services, $builder);
	}

	private function persistenceId(string $name): string
	{
		return "data_access_kit.{$name}_persistence";
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function configureGlobalServices(array $config, ServicesConfigurator $services, ContainerBuilder $builder): void
	{
		/** @var string $nameConverter */
		$nameConverter = $config["name_converter"];
		$services->set($nameConverter)->autowire();
		$services->alias(NameConverterInterface::class, $nameConverter);

		$services->set(Registry::class)->autowire();

		/** @var string $valueConverter */
		$valueConverter = $config["value_converter"];
		if (!$builder->hasDefinition($valueConverter)) {
			$services->set($valueConverter)->autowire();
		}
		$services->alias(ValueConverterInterface::class, $valueConverter);
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function configureDatabaseServices(array $config, ServicesConfigurator $services): void
	{
		/** @var array<string, array<string, mixed>> $databases */
		$databases = $config["databases"];
		foreach ($databases as $name => $database) {
			/** @var string $connection */
			$connection = $database["connection"];
			$services->set($this->persistenceId($name), Persistence::class)
				->arg("\$connection", new Reference($connection))
				->autowire();

			/** @var string $defaultDatabase */
			$defaultDatabase = $config["default_database"];
			if ($name === $defaultDatabase) {
				$services->alias(PersistenceInterface::class, $this->persistenceId($name));
			}
		}
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function configureRepositoryServices(array $config, ServicesConfigurator $services, ContainerBuilder $builder): void
	{
		/** @var class-string<NameConverterInterface> $nameConverterClass */
		$nameConverterClass = $config["name_converter"];
		$nameConverter = new $nameConverterClass();
		$registry = new Registry($nameConverter);
		$compiler = new Compiler($registry);
		
		/** @var string $outputDirValue */
		$outputDirValue = $builder->getParameterBag()->resolveValue("%kernel.cache_dir%");
		$outputDir = $outputDirValue . DIRECTORY_SEPARATOR . "DataAccessKit";
		
		/** @var bool $debug */
		$debug = $builder->getParameterBag()->resolveValue("%kernel.debug%");

		/** @var array<string, array<string, mixed>> $repositories */
		$repositories = $config["repositories"];
		foreach ($repositories as $namespace => $repository) {
			/** @var string $path */
			$path = $repository["path"];
			$finder = (new Finder())
				->in($path)
				->files()
				->name("*.php");

			/** @var array<string> $excludePatterns */
			$excludePatterns = $repository["exclude"];
			if (count($excludePatterns) > 0) {
				$finder->notName($excludePatterns);
			}

			foreach ($finder as $file) {
				$className = rtrim($namespace, "\\") . "\\" . strtr(substr($file->getRelativePathname(), 0, -4 /* strlen(".php") */), DIRECTORY_SEPARATOR, "\\");
				/** @var class-string $className */
				[$result, $fileName] = $this->compileRepository($className, $compiler, $builder, $outputDir, $debug);
				if ($result === null || $fileName === null) {
					continue;
				}

				$cfg = $services->set($result->getName(), $result->getName())
					->file($fileName)
					->autowire();
				if ($result->hasMethod("__construct") && $result->method("__construct")->hasParameter(Compiler::PERSISTENCE_PROPERTY)) {
					$cfg->arg('$' . Compiler::PERSISTENCE_PROPERTY, new Reference($this->persistenceId($result->repository->database)));
				}
				$aliasCfg = $services->alias($result->reflection->getName(), $result->getName());
				
				/** @var bool $repositoriesPublic */
				$repositoriesPublic = $config["repositories_public"];
				if ($repositoriesPublic) {
					$aliasCfg->public();
				}
			}
		}
	}

	/**
	 * @param class-string $className
	 * @return array{0: \DataAccessKit\Repository\Result|null, 1: string|null}
	 */
	private function compileRepository(string $className, Compiler $compiler, ContainerBuilder $builder, string $outputDir, bool $debug): array
	{
		try {
			$result = $compiler->prepare($className);
		} catch (CompilerException $e) {
			return [null, null];
		}

		$cache = new ConfigCache(
			$outputDir . DIRECTORY_SEPARATOR . strtr($result->getName(), "\\", DIRECTORY_SEPARATOR) . ".php",
			$debug,
		);
		if (!$cache->isFresh()) {
			$compiler->compile($result);
			$metadata = [];
			foreach ($result->dependencies as $dependency) {
				$builder->addResource($metadata[] = new ReflectionClassResource($dependency));
			}
			$cache->write((string) $result, $metadata);
		}
		require_once $cache->getPath();
		$fileName = $cache->getPath();

		return [$result, $fileName];
	}

}
