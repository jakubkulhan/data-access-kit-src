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

## Rust Code Guidelines

### Formatting Requirements

**Always run `cargo fmt` after making changes to Rust code.** This ensures consistent formatting across the codebase.

```bash
cargo fmt
```

The formatter will automatically:
- Organize imports alphabetically
- Apply consistent indentation
- Format code according to Rust style guidelines
- Ensure consistent spacing and line breaks

**Important:** Run `cargo fmt` before committing any Rust code changes.

## Rust Import Rules

### Import Organization Guidelines

When writing or refactoring Rust code in this project, follow these import rules:

1. **Use statements always at the top of the file, never inside functions**
   - All `use` statements must be placed at the top of the file after any comments or attributes
   - Never place `use` statements inside functions, methods, or other code blocks

2. **Types should be imported directly**
   - Import types (structs, enums, traits) by their full path so they can be used directly
   - Examples:
     ```rust
     use std::ffi::CString;
     use ext_php_rs::types::Zval;
     use ext_php_rs::zend::ClassEntry;
     ```

3. **Functions should be used with module prefix**
   - Import modules containing functions, then call functions with module prefix
   - Examples:
     ```rust
     use std::{mem, ptr};

     // Then call:
     mem::zeroed()
     ptr::null_mut()
     ```

4. **Group related imports**
   - Group imports from the same crate/module using braces
   - Examples:
     ```rust
     use std::{mem, ptr};
     use std::collections::{HashMap, VecDeque};
     use ext_php_rs::zend::{self, ce};
     ```

### Examples

**Good:**
```rust
use ext_php_rs::prelude::*;
use ext_php_rs::types::Zval;
use ext_php_rs::zend::{ClassEntry, ZendType};
use std::ffi::CString;
use std::{mem, ptr};

fn example() {
    let interface_ce: ffi::zend_class_entry = mem::zeroed();
    let null_ptr = ptr::null_mut();
}
```

**Bad:**
```rust
use ext_php_rs::prelude::*;

fn example() {
    use std::mem;  // ❌ use inside function
    use std::ptr;  // ❌ use inside function

    let interface_ce = mem::zeroed();
}
```

## Documentation

The project specification is in `SPEC.md`. **Update SPEC.md when implementation diverges from the documented design** to keep documentation accurate and current.