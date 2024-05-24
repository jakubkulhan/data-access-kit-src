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
  paths:
    # the code must be structured in PSR-4 way
    - path: %kernel.project_dir%/src/Repository
      namespace: App\Repository
```

And that's it! Follow repositories [quick start](https://github.com/jakubkulhan/data-access-kit#quick-start) to learn more.

## Installation

```shell
composer require data-access-kit/data-access-kit-symfony@dev-main
```

### Requirements

- PHP 8.3 or higher.
- Symfony 7.0 or higher.

## Contributing

This repository is automatically split from the [main repository](https://github.com/jakubkulhan/data-access-kit-src). Please open issues and pull requests there.

## License

Licensed under MIT license. See [LICENSE](https://github.com/jakubkulhan/data-access-kit-src/blob/main/LICENSE).
