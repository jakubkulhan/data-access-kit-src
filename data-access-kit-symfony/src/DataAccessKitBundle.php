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
use DataAccessKit\ValueConverterInterface;
use LogicException;
use ReflectionClass;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Resource\ReflectionClassResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use function strtr;
use function substr;
use const DIRECTORY_SEPARATOR;

class DataAccessKitBundle extends AbstractBundle
{
	public function configure(DefinitionConfigurator $definition): void
	{
		$definition->rootNode()
			->children()
				->scalarNode("name_converter")
					->defaultValue(DefaultNameConverter::class)
					->info("Name converter class to use. Class constructor must not have any parameters.")
				->end()
				->arrayNode("paths")
					->arrayPrototype()
						->children()
							->scalarNode("path")
								->isRequired()
								->info("Path to the PSR-4 directory containing the classes.")
							->end()
							->scalarNode("namespace")
								->isRequired()
								->info("Namespace of the classes in the path.")
							->end()
								->arrayNode("exclude")
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
		$services->set(Persistence::class)->autowire();
		$services->alias(PersistenceInterface::class, Persistence::class);

		$services->set(Registry::class)->autowire();

		$services->set(DefaultNameConverter::class)->autowire();
		$services->alias(NameConverterInterface::class, DefaultNameConverter::class);

		$services->set(DefaultValueConverter::class)->autowire();
		$services->alias(ValueConverterInterface::class, DefaultValueConverter::class);

		$nameConverter = new $config["name_converter"]();
		$registry = new Registry($nameConverter);
		$compiler = new Compiler($registry);

		$outputDir = $builder->getParameterBag()->resolveValue("%kernel.cache_dir%") . DIRECTORY_SEPARATOR . "DataAccessKit";
		$fs = new Filesystem();

		foreach ($config["paths"] as $path) {
			$finder = (new Finder())
				->in($path["path"])
				->files()
				->name("*.php");

			if (count($path["exclude"]) > 0) {
				throw new LogicException("TODO: exclude");
			}

			foreach ($finder as $file) {
				$className = $path["namespace"] . "\\" . strtr(substr($file->getRelativePathname(), 0, -4 /* strlen(".php") */), DIRECTORY_SEPARATOR, "\\");
				$rc = new ReflectionClass($className);
				if (count($rc->getAttributes(Repository::class)) === 0) {
					continue;
				}

				$result = $compiler->prepare($rc);
				$cache = new ConfigCache(
					$outputDir . DIRECTORY_SEPARATOR . strtr($result->getName(), "\\", DIRECTORY_SEPARATOR) . ".php",
					$builder->getParameterBag()->resolveValue("%kernel.debug%"),
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

				$services->set($result->getName(), $result->getName())
					->file($cache->getPath())
					->autowire();
				$services->alias($rc->getName(), $result->getName());
			}
		}
	}
}
