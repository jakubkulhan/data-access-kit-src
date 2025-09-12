# DataAccessKit Replication - Development Notes

## Running Tests

### Unit Tests
To run only unit tests (fast, no database required):
```bash
composer run test:unit
```

### Database Integration Tests
To run database tests with environment variable:
```bash
composer run test:database:env
```

To run database tests against specific databases:
```bash
composer run test:database:mysql     # MySQL on port 32016
composer run test:database:mariadb   # MariaDB on port 35098
composer run test:database:all       # Both MySQL and MariaDB
```

All test commands will:
1. Build the Rust extension (`cargo build --release`)
2. Load the extension via the local `php.ini` configuration
3. Run the specified PHPUnit test groups

**IMPORTANT: Always run tests after making any changes to Rust or PHP code.**

### Test Groups
- **Unit tests** (`#[Group("unit")]`): Interface validation, event property tests - no database required
- **Database tests** (`#[Group("database")]`): Integration tests requiring DATABASE_URL environment variable

The tests ensure:
- Extension builds correctly
- Interfaces load automatically on startup
- All interface definitions are valid
- Extension integrates properly with PHP 8.4
- Database replication functionality works correctly

## Documentation

The project specification is in `SPEC.md`. **Update SPEC.md when implementation diverges from the documented design** to keep documentation accurate and current.