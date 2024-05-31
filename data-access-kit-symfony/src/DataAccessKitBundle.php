<?php declare(strict_types=1);

namespace DataAccessKit\Symfony;

use DataAccessKit\DefaultNameConverter;
use DataAccessKit\DefaultValueConverter;
use DataAccessKit\NameConverterInterface;
use DataAccessKit\Persistence;
use DataAccessKit\PersistenceInterface;
use DataAccessKit\Registry;
use DataAccessKit\Repository\Attribute\Repository;
use DataAccessKit\Repository\Compiler;
use DataAccessKit\Repository\Exception\CompilerException;
use DataAccessKit\ValueConverterInterface;
use Doctrine\DBAL\Connection;
use LogicException;
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
			->end();
	}

	public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
	{
		$services = $container->services();

		$this->configureGlobalServices($config, $services);

		$this->configureDatabaseServices($config, $services);

		$this->configureRepositoryServices($config, $services, $builder);
	}

	private function persistenceId(string $name): string
	{
		return "data_access_kit.{$name}_persistence";
	}

	private function configureGlobalServices(array $config, ServicesConfigurator $services): void
	{
		$services->set($config["name_converter"])->autowire();
		$services->alias(NameConverterInterface::class, $config["name_converter"]);

		$services->set(Registry::class)->autowire();

		$services->set(DefaultValueConverter::class)->autowire();
		$services->alias(ValueConverterInterface::class, DefaultValueConverter::class);
	}

	private function configureDatabaseServices(array $config, ServicesConfigurator $services): void
	{
		foreach ($config["databases"] as $name => $database) {
			$services->set($this->persistenceId($name), Persistence::class)
				->arg("\$connection", new Reference($database["connection"]))
				->autowire();

			if ($name === $config["default_database"]) {
				$services->alias(PersistenceInterface::class, $this->persistenceId($name));
			}
		}
	}

	private function configureRepositoryServices(array $config, ServicesConfigurator $services, ContainerBuilder $builder): void
	{
		$nameConverter = new $config["name_converter"]();
		$registry = new Registry($nameConverter);
		$compiler = new Compiler($registry);
		$outputDir = $builder->getParameterBag()->resolveValue("%kernel.cache_dir%") . DIRECTORY_SEPARATOR . "DataAccessKit";
		$debug = $builder->getParameterBag()->resolveValue("%kernel.debug%");

		foreach ($config["repositories"] as $namespace => $repository) {
			$finder = (new Finder())
				->in($repository["path"])
				->files()
				->name("*.php");

			if (count($repository["exclude"]) > 0) {
				throw new LogicException("TODO: exclude");
			}

			foreach ($finder as $file) {
				$className = rtrim($namespace, "\\") . "\\" . strtr(substr($file->getRelativePathname(), 0, -4 /* strlen(".php") */), DIRECTORY_SEPARATOR, "\\");
				[$result, $fileName] = $this->compileRepository($className, $compiler, $builder, $outputDir, $debug);
				if ($result === null) {
					continue;
				}

				$cfg = $services->set($result->getName(), $result->getName())
					->file($fileName)
					->autowire();
				if ($result->hasMethod("__construct") && $result->method("__construct")->hasParameter(Compiler::PERSISTENCE_PROPERTY)) {
					$cfg->arg('$' . Compiler::PERSISTENCE_PROPERTY, new Reference($this->persistenceId($result->repository->database)));
				}
				$services->alias($result->reflection->getName(), $result->getName());
			}
		}
	}

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
