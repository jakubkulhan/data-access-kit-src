# DataAccessKit Replication - Development Notes

## Running Tests

To run all tests:
```bash
composer test
```

This command will:
1. Build the Rust extension (`cargo build --release`)
2. Load the extension via the local `php.ini` configuration
3. Run all PHPUnit tests

**IMPORTANT: Always run tests after making any changes to Rust or PHP code.**

The tests ensure:
- Extension builds correctly
- Interfaces load automatically on startup
- All interface definitions are valid
- Extension integrates properly with PHP 8.4

## Documentation

The project specification is in `SPEC.md`. **Update SPEC.md when implementation diverges from the documented design** to keep documentation accurate and current.