# DataAccessKit\Symfony

## Quick start

Add bundle to `config/bundles.php`.

```php
<?php

return [
    // ...
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true], // DataAccessKit depends on Doctrine\DBAL
    DataAccessKit\Symfony\DataAccessKitBundle::class => ['all' => true],
];
```

(Or add to `Kernel::registerBundles()` if you don't use `MicroKernelTrait`.)

Then configure paths to your repository classes in `config/packages/data_access_kit.yaml`.

```yaml
data_access_kit:
  repositories:
    # similar to how autoload in composer.json works
    App\Repository\:
      path: %kernel.project_dir%/src/Repository
```

And that's it! Follow repositories [quick start](https://github.com/jakubkulhan/data-access-kit#quick-start) to learn more.

## Installation

```shell
composer require data-access-kit/data-access-kit-symfony@dev-main
```

### Requirements

- PHP 8.3 or higher.
- Symfony 7.0 or higher.

## Configuration

```yaml
data_access_kit:
  default_database: default # this database Persistence will be aliased to PersistenceInterface
  databases:
    default:
      connection: doctrine.dbal.default_connection # service reference to Doctrine\DBAL\Connection
    other:
      connection: doctrine.dbal.other_connection
  repositories:
    App\Repository: # namespace prefix
      path: %kernel.project_dir%/src/Repository # path to repository classes
      exclude: # excluded file paths, you can use glob patterns
        - Support/**
        - Tests/**
  name_converter: DataAccessKit\Converter\DefaultNameConverter # service reference to NameConverterInterface, if the service doesn't exist, the string is considered to be a class name and a service is added to the container
  value_converter: DataAccessKit\Converter\DefaultValueConverter # service reference to ValueConverterInterface, the same behavior as with name_converter
```

## Contributing

This repository is automatically split from the [main repository](https://github.com/jakubkulhan/data-access-kit-src). Please open issues and pull requests there.

## License

Licensed under MIT license. See [LICENSE](https://github.com/jakubkulhan/data-access-kit-src/blob/main/LICENSE).
