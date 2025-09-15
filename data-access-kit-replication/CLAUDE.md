# DataAccessKit Replication - Development Notes

## Running Tests

### Unit Tests
To run only unit tests (fast, no database required):
```bash
composer run test:unit
```

### Database Integration Tests
To run database tests:
```bash
composer run test:database:all
```

To run database tests against specific databases:
```bash
composer run test:database:mysql     # MySQL on port 32016
composer run test:database:mariadb   # MariaDB on port 35098
```

All test commands will:
1. Build the Rust extension (`cargo build`)
2. Load the extension via the local `php.ini` configuration
3. Run the specified PHPUnit test groups

### Running All Tests
**The agent should always run both test suites to ensure complete validation:**
```bash
composer run test:unit          # Run all unit tests (fast, no database)
composer run test:database:all  # Run database tests against MySQL and MariaDB
```

### Test Groups
- **Unit tests** (`#[Group("unit")]`): Interface validation, event property tests - no database required
- **Database tests** (`#[Group("database")]`): Integration tests requiring DATABASE_URL environment variable

The tests ensure:
- Extension builds correctly
- Interfaces load automatically on startup
- All interface definitions are valid
- Extension integrates properly with PHP 8.4
- Database replication functionality works correctly

## Test Writing Guidelines

### Test Assertions Best Practices

**For tests that don't need explicit assertions:**
```php
public function testSomeAction(): void
{
    // Use expectNotToPerformAssertions() at the start of the test
    $this->expectNotToPerformAssertions();

    // Test code that should complete without exceptions
    $stream->setCheckpointer(null);
}
```

**Avoid using `addToAssertionCount()`** - it's an internal PHPUnit method and `expectNotToPerformAssertions()` is the proper public API.

### Test Structure

- **Unit tests**: Test individual components without external dependencies
- **Database tests**: Test full integration with real database connections
- Always clean up resources in `tearDown()` methods
- Use descriptive test method names that explain what is being tested
- **Do not add comments to PHP test files** - keep test code clean and minimal
- Group related assertions with clear, descriptive assertion messages

## Documentation

The project specification is in `SPEC.md`. **Update SPEC.md when implementation diverges from the documented design** to keep documentation accurate and current.