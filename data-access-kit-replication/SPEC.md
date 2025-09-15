# DataAccessKit\Replication

## Overview

This specification defines a PHP extension written in Rust using `ext-php-rs` that provides SQL database replication stream capabilities to PHP applications. The extension currently implements MySQL binary log parsing using `mysql-binlog-connector-rust` and exposes replication events to PHP through an iterator interface. The architecture is designed to be extensible to other SQL databases in the future.

## Architecture

```
┌─────────────────────────────────┐    ┌──────────────────┐    ┌─────────────────┐
│          PHP Script             │◄───┤ ext-php-rs Glue │◄───┤  Rust Core      │
│                                 │    │                  │    │                 │
│ $stream = new Stream($url);     │    │ Stream           │    │ MySQL Binlog    │
│ $stream->setCheckpointer(...);  │    │ Event Classes    │    │ Connector       │
│ $stream->setFilter(...);        │    │                  │    │                 │
│ foreach($stream as $event) {    │    │                  │    │                 │
│   // PHP 8.4 properties         │    │                  │    │                 │
│   echo $event->type;            │    │                  │    │                 │
│ }                               │    │                  │    │                 │
└─────────────────────────────────┘    └──────────────────┘    └─────────────────┘
                                                  ▲                       │
                                                  │                       ▼
                                        ┌─────────────────┐    ┌─────────────────┐
                                        │ PHP Interfaces  │    │ MySQL Server    │
                                        │ Checkpointer    │    │ (binlog stream) │
                                        │ Filter          │    │                 │
                                        └─────────────────┘    └─────────────────┘
```

## Core Components

### 1. Stream

The main stream class that manages database replication connections. Uses connection URL protocol for driver selection (MySQL initially, extensible to other databases).

**PHP Interface:**
```php
namespace DataAccessKit\Replication;

class Stream implements Iterator {
    public function __construct(string $connectionUrl);
    public function connect(): void;
    public function disconnect(): void;
    public function setCheckpointer(StreamCheckpointerInterface $checkpointer): void;
    public function setFilter(StreamFilterInterface $filter): void;
    
    // Iterator interface - connection established on rewind() if not connected
    public function current(): Event;
    public function key(): int;
    public function next(): void;
    public function rewind(): void; // Establishes connection if not connected
    public function valid(): bool;
}
```

**Connection URL Examples:**
```php
// MySQL with basic auth
$url = 'mysql://user:password@localhost:3306?server_id=100';

// MySQL with SSL
$url = 'mysql://user:password@localhost:3306?server_id=100&ssl=true';

// MariaDB (uses same mysql:// scheme, auto-detected)
$url = 'mysql://user:password@localhost:3306?server_id=100';

// Future: PostgreSQL logical replication
$url = 'postgresql://user:password@localhost:5432?slot_name=my_slot';
```

### 2. StreamCheckpointerInterface

Interface for checkpoint management, implemented in PHP and passed to the extension.

**PHP Interface:**
```php
namespace DataAccessKit\Replication;

interface StreamCheckpointerInterface {
    public function loadLastCheckpoint(): ?string;
    public function saveCheckpoint(string $checkpoint): void;
}
```

**Example Implementation:**
```php
class FileCheckpointer implements StreamCheckpointerInterface {
    private string $filename;
    
    public function __construct(string $filename) {
        $this->filename = $filename;
    }
    
    public function loadLastCheckpoint(): ?string {
        return file_exists($this->filename) ? file_get_contents($this->filename) : null;
    }
    
    public function saveCheckpoint(string $checkpoint): void {
        file_put_contents($this->filename, $checkpoint);
    }
}
```

### 3. StreamFilterInterface

Interface for filtering events, implemented in PHP and passed to the extension.

**PHP Interface:**
```php
namespace DataAccessKit\Replication;

interface StreamFilterInterface {
    public function accept(string $type, string $schema, string $table): bool;
}
```

**Example Implementation:**
```php
class TableFilter implements StreamFilterInterface {
    private array $allowedTables;
    
    public function __construct(array $allowedTables) {
        $this->allowedTables = $allowedTables;
    }
    
    public function accept(string $type, string $schema, string $table): bool {
        return in_array("$schema.$table", $this->allowedTables);
    }
}
```

### 4. Event Interface (PHP 8.4 Properties)

Base interface for all replication events using PHP 8.4 interface properties.

**PHP Interface:**
```php
namespace DataAccessKit\Replication;

interface EventInterface {
    public const string INSERT = 'INSERT';
    public const string UPDATE = 'UPDATE';
    public const string DELETE = 'DELETE';
    
    public string $type { get; }
    public int $timestamp { get; }
    public string $checkpoint { get; }
    public string $schema { get; }
    public string $table { get; }
}
```

### 5. Event Implementations

Specific event types for DML operations using PHP 8.4 interface properties.

**InsertEvent:**
```php
namespace DataAccessKit\Replication;

class InsertEvent implements EventInterface {
    public string $type { get; }
    public int $timestamp { get; }
    public string $checkpoint { get; }
    public string $schema { get; }
    public string $table { get; }
    
    public object $after { get; } // Column name => value object
}
```

**UpdateEvent:**
```php
namespace DataAccessKit\Replication;

class UpdateEvent implements EventInterface {
    public string $type { get; }
    public int $timestamp { get; }
    public string $checkpoint { get; }
    public string $schema { get; }
    public string $table { get; }
    
    public object $before { get; }
    public object $after { get; }
}
```

**DeleteEvent:**
```php
namespace DataAccessKit\Replication;

class DeleteEvent implements EventInterface {
    public string $type { get; }
    public int $timestamp { get; }
    public string $checkpoint { get; }
    public string $schema { get; }
    public string $table { get; }
    
    public object $before { get; }
}
```

## MySQL/MariaDB Configuration Validation

### Required MySQL Settings

The extension must validate the following MySQL configuration when connecting:

1. **binlog_format = ROW**
   - Ensures row-based replication is enabled
   - Query: `SHOW VARIABLES LIKE 'binlog_format'`

2. **binlog_row_image = FULL**
   - Ensures complete row data is logged
   - Query: `SHOW VARIABLES LIKE 'binlog_row_image'`

3. **binlog_row_metadata = FULL**
   - Provides complete column metadata (MySQL 8.0+)
   - Query: `SHOW VARIABLES LIKE 'binlog_row_metadata'`

4. **gtid_mode = ON** (MySQL only)
   - Enables Global Transaction Identifier (GTID) mode
   - Query: `SHOW VARIABLES LIKE 'gtid_mode'`

### Required MariaDB Settings

For MariaDB, the extension validates:

1. **binlog_format = ROW**
   - Same as MySQL
   - Query: `SHOW VARIABLES LIKE 'binlog_format'`

2. **binlog_row_image = FULL**
   - Same as MySQL
   - Query: `SHOW VARIABLES LIKE 'binlog_row_image'`

3. **gtid_domain_id** (MariaDB GTID)
   - MariaDB uses domain-based GTIDs instead of MySQL's GTID mode
   - Query: `SHOW VARIABLES LIKE 'gtid_domain_id'`
   - Note: MariaDB GTID is always enabled but uses different format

### Server Type Detection and Validation

The extension automatically detects MySQL vs MariaDB and applies appropriate validation:

```php
use DataAccessKit\Replication\Stream;

// Works with both MySQL and MariaDB
$stream = new Stream('mysql://user:pass@localhost:3306?server_id=100');

try {
    // Validation happens automatically - detects MySQL/MariaDB and validates accordingly
    $stream->connect();
} catch (Exception $e) {
    echo "Database binlog configuration invalid: " . $e->getMessage();
}
```

**Server Detection Process:**
1. Query `SELECT VERSION()` to determine if server is MySQL or MariaDB
2. Apply database-specific validation rules
3. Use appropriate checkpointing strategy based on server type

## Column Metadata and Type Mapping

### Metadata Utilization

The extension uses TABLE_MAP_EVENT metadata to:

1. **Column Name Association**: Map column indices to names
2. **Type Information**: Handle MySQL type to PHP type conversion
3. **Enum/Set Values**: Convert enum/set indices to string values
4. **NULL Handling**: Properly handle nullable columns
5. **Character Set**: Handle string encoding properly

### Type Conversion Matrix

| MySQL Type | Rust Type | PHP Type | Notes |
|------------|-----------|----------|-------|
| TINYINT | i8 | int | |
| SMALLINT | i16 | int | |
| MEDIUMINT | i32 | int | |
| INT | i32 | int | |
| BIGINT | i64 | int/string | string if > PHP_INT_MAX |
| DECIMAL | String | string | Preserved precision |
| FLOAT | f32 | float | |
| DOUBLE | f64 | float | |
| VARCHAR/TEXT | String | string | UTF-8 encoded |
| BINARY/BLOB | Vec&lt;u8&gt; | string | Base64 encoded |
| DATE | String | string | YYYY-MM-DD format |
| DATETIME | String | string | YYYY-MM-DD HH:MM:SS format |
| TIMESTAMP | u32 | int | Unix timestamp |
| JSON | String | mixed | Parsed JSON |
| ENUM | String | string | Resolved enum value |
| SET | String | string | Comma-separated values |

## Checkpointing

### Checkpoint Management

Checkpointing is handled entirely through the PHP-side `StreamCheckpointerInterface`. The extension calls the checkpointer methods at appropriate times during stream processing.

### Checkpointing Strategies

The extension supports two checkpointing strategies with explicit prefixing:

#### 1. GTID-Based Checkpointing (MySQL only)
- **Format**: `gtid:` prefix followed by MySQL GTID string
- **Example**: `gtid:3E11FA47-71CA-11E1-9E33-C80AA9429562:23`
- **Advantages**: Globally unique, works across server restarts and failovers
- **Usage**: Only used for MySQL servers with GTID enabled

#### 2. Binlog File/Position Checkpointing
- **Format**: `file:` prefix followed by `filename:position`
- **Example**: `file:mysql-bin.000123:45678`
- **Usage**:
  - Default for MariaDB (always used regardless of GTID support)
  - Fallback for MySQL when GTID is not available or disabled
- **Limitations**: File/position is server-specific and doesn't survive server changes

### Server-Specific Checkpointing Behavior

- **MySQL**: Uses GTID checkpointing when available, falls back to file/position
- **MariaDB**: Always uses binlog file/position checkpointing (due to GTID complexity)

### Checkpoint Format Examples

```php
// MySQL with GTID enabled
$mysql_gtid = "gtid:3E11FA47-71CA-11E1-9E33-C80AA9429562:23";

// MySQL without GTID or MariaDB
$binlog_pos = "file:mysql-bin.000123:45678";
$mariadb_pos = "file:mariadb-bin.000042:12345";
```

### Checkpointer Flow

1. **Stream Start**: Extension calls `loadLastCheckpoint()` to determine starting position
   - If `null` is returned, stream starts from current position (live events)
   - If string is returned, extension parses prefix (`gtid:` or `file:`) and starts from that checkpoint
2. **Event Processing**: Extension processes events from the starting checkpoint
3. **Periodic Checkpointing**: Extension calls `saveCheckpoint(string $checkpoint)` periodically with current position
   - MySQL: Uses `gtid:` prefix when GTID is available, `file:` prefix otherwise
   - MariaDB: Always uses `file:` prefix
4. **Stream Restart**: On reconnection, process repeats from step 1

### PHP Checkpointer Implementation

```php
use DataAccessKit\Replication\StreamCheckpointerInterface;

// File-based checkpointer (works with prefixed checkpoint format)
class FileCheckpointer implements StreamCheckpointerInterface {
    public function __construct(private string $filename) {}

    public function loadLastCheckpoint(): ?string {
        return file_exists($this->filename) ? file_get_contents($this->filename) : null;
    }

    public function saveCheckpoint(string $checkpoint): void {
        file_put_contents($this->filename, $checkpoint);
    }
}

// Database-based checkpointer
class DatabaseCheckpointer implements StreamCheckpointerInterface {
    public function __construct(private PDO $pdo, private string $streamId) {}

    public function loadLastCheckpoint(): ?string {
        $stmt = $this->pdo->prepare('SELECT checkpoint FROM stream_positions WHERE stream_id = ?');
        $stmt->execute([$this->streamId]);
        return $stmt->fetchColumn() ?: null;
    }

    public function saveCheckpoint(string $checkpoint): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO stream_positions (stream_id, checkpoint, updated_at) VALUES (?, ?, NOW()) '
            . 'ON DUPLICATE KEY UPDATE checkpoint = VALUES(checkpoint), updated_at = NOW()'
        );
        $stmt->execute([$this->streamId, $checkpoint]);
    }
}
```

### Database Schema for Checkpointing

```sql
CREATE TABLE stream_positions (
    stream_id VARCHAR(255) PRIMARY KEY,
    checkpoint TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### Checkpoint Format Specification

The extension uses prefixed checkpoint strings for explicit format identification:

#### GTID Format (MySQL Only)
- **Format**: `gtid:` prefix followed by MySQL GTID string
- **Examples**:
  - Single transaction: `gtid:3E11FA47-71CA-11E1-9E33-C80AA9429562:23`
  - Transaction range: `gtid:3E11FA47-71CA-11E1-9E33-C80AA9429562:1-25`
  - Multiple servers: `gtid:3E11FA47-71CA-11E1-9E33-C80AA9429562:1-25,4A2F5DB8-82BC-11E1-B23E-C80AA9429562:1-10`

#### Binlog File/Position Format
- **Format**: `file:` prefix followed by `filename:position`
- **Examples**:
  - MySQL: `file:mysql-bin.000123:45678`
  - MariaDB: `file:mariadb-bin.000042:12345`
  - Custom prefix: `file:binary-log.000001:1024`

#### Format Detection Logic

The extension uses simple prefix detection:

```rust
if checkpoint.starts_with("gtid:") {
    CheckpointType::GTID
} else if checkpoint.starts_with("file:") {
    CheckpointType::BinlogPosition
} else {
    // Invalid checkpoint format
    return Err("Invalid checkpoint format")
}
```

#### Server-Specific Behavior

- **MySQL with GTID**: Extension generates `gtid:` prefixed checkpoints
- **MySQL without GTID**: Extension generates `file:` prefixed checkpoints
- **MariaDB**: Extension always generates `file:` prefixed checkpoints (GTID complexity avoided)

## Error Handling

### Error Scenarios

1. **Connection Errors**: Network issues, authentication failures
2. **Configuration Errors**: Invalid MySQL settings  
3. **Parse Errors**: Corrupted binlog data
4. **Checkpoint Errors**: Issues with checkpointer interface calls
5. **Filter Errors**: Issues with filter interface calls

### Error Handling Strategy

Errors are thrown as standard PHP exceptions. The extension may throw exceptions during:
- Connection establishment (`connect()` or `rewind()`)
- Event iteration (`next()`, `current()`)
- Checkpointer calls
- Filter calls

## Usage Examples

### Basic Usage

```php
<?php

use DataAccessKit\Replication\{Stream, InsertEvent, UpdateEvent, DeleteEvent};

$connectionUrl = 'mysql://repl_user:password@localhost:3306?server_id=100';
$stream = new Stream($connectionUrl);

use DataAccessKit\Replication\Event;

foreach ($stream as $event) {
    match ($event->type) {
        EventInterface::INSERT => (
            function(InsertEvent $event) {
                echo "Insert into {$event->schema}.{$event->table}\n";
                print_r($event->after);
            }
        )($event),
        
        EventInterface::UPDATE => (
            function(UpdateEvent $event) {
                echo "Update {$event->schema}.{$event->table}\n";
                echo "Before: ";
                print_r($event->before);
                echo "After: ";
                print_r($event->after);
            }
        )($event),
        
        EventInterface::DELETE => (
            function(DeleteEvent $event) {
                echo "Delete from {$event->schema}.{$event->table}\n";
                print_r($event->before);
            }
        )($event),
    };
}
```

### With Checkpointing and Filtering

```php
<?php

use DataAccessKit\Replication\Stream;
use DataAccessKit\Replication\{StreamCheckpointerInterface, StreamFilterInterface};

class FileCheckpointer implements StreamCheckpointerInterface {
    public function __construct(private string $filename) {}

    public function loadLastCheckpoint(): ?string {
        return file_exists($this->filename) ? file_get_contents($this->filename) : null;
    }

    public function saveCheckpoint(string $checkpoint): void {
        file_put_contents($this->filename, $checkpoint);
    }
}

class TableFilter implements StreamFilterInterface {
    public function __construct(private array $allowedTables) {}

    public function accept(string $type, string $schema, string $table): bool {
        return in_array("$schema.$table", $this->allowedTables);
    }
}

$checkpointer = new FileCheckpointer('/tmp/binlog_checkpoint.txt');
$filter = new TableFilter(['mydb.users', 'mydb.orders']);

// Works with both MySQL and MariaDB - extension auto-detects server type
$connectionUrl = 'mysql://repl_user:password@localhost:3306?server_id=100';
$stream = new Stream($connectionUrl);
$stream->setCheckpointer($checkpointer);
$stream->setFilter($filter);

foreach ($stream as $event) {
    // Process event
    processEvent($event);

    // Checkpointing is handled automatically by the extension
    // Extension uses prefixed checkpoint format:
    // - MySQL GTID: "gtid:3E11FA47-71CA-11E1-9E33-C80AA9429562:23"
    // - MySQL binlog: "file:mysql-bin.000123:45678"
    // - MariaDB: "file:mariadb-bin.000042:12345" (always file/position)
}
```

### Advanced Checkpointing Examples

```php
<?php

use DataAccessKit\Replication\Stream;

// Example: Resume from specific checkpoint
$pdo = new PDO('mysql:host=localhost;dbname=replication', $user, $pass);
$checkpointer = new DatabaseCheckpointer($pdo, 'my_stream_001');

$stream = new Stream('mysql://repl_user:password@localhost:3306?server_id=100');
$stream->setCheckpointer($checkpointer);

foreach ($stream as $event) {
    echo "Processing {$event->type} event from {$event->schema}.{$event->table}\n";
    echo "Current checkpoint: {$event->checkpoint}\n";

    // Process event...
    processEvent($event);

    // Extension automatically saves checkpoint with appropriate prefix:
    // - MySQL with GTID: "gtid:3E11FA47-71CA-11E1-9E33-C80AA9429562:23"
    // - MySQL without GTID: "file:mysql-bin.000123:45678"
    // - MariaDB: "file:mariadb-bin.000042:12345"
}

// Example: Manual checkpoint handling
class LoggingCheckpointer implements StreamCheckpointerInterface {
    public function __construct(private string $filename) {}

    public function loadLastCheckpoint(): ?string {
        $checkpoint = file_exists($this->filename) ? file_get_contents($this->filename) : null;
        if ($checkpoint) {
            echo "Resuming from checkpoint: $checkpoint\n";
        }
        return $checkpoint;
    }

    public function saveCheckpoint(string $checkpoint): void {
        echo "Saving checkpoint: $checkpoint\n";
        file_put_contents($this->filename, $checkpoint);
    }
}
```

## Implementation Details

### Interface Declaration Strategy

Since ext-php-rs doesn't support creating PHP interfaces directly from Rust, the interfaces must be declared by executing PHP code during extension startup. The extension embeds interface definitions at compile time using `include_str!()` to ensure no external file dependencies.

**Current Implementation:** The extension loads interfaces from `src/lib.php` using a request startup function with `Once` synchronization to ensure interfaces are loaded only once per process:

```rust
unsafe extern "C" fn request_startup_function(_type: i32, _module_number: i32) -> i32 {
    // Use Once to ensure interfaces are only loaded once per process
    INTERFACES_INIT.call_once(|| {
        let interface_code = include_str!("lib.php");
        
        // Prepend ?> to properly handle the <?php opening tag when eval'ing
        let eval_code = format!("?>{}", interface_code);
        
        let code_cstr = match std::ffi::CString::new(eval_code) {
            Ok(cstr) => cstr,
            Err(_) => {
                eprintln!("Failed to create CString from interface code");
                return;
            }
        };
        
        let filename_cstr = match std::ffi::CString::new("lib.php") {
            Ok(cstr) => cstr,
            Err(_) => {
                eprintln!("Failed to create filename CString");
                return;
            }
        };
        
        // Use the FFI to call zend_eval_string when PHP is ready
        let result = ffi::zend_eval_string(
            code_cstr.as_ptr(),
            std::ptr::null_mut(), // No return value needed
            filename_cstr.as_ptr(),
        );
        
        // Check if evaluation was successful
        if result != 0 {
            eprintln!("Failed to evaluate interface code during request startup");
        }
    });
    
    0 // SUCCESS
}
```

**Interface Definition File** (`src/lib.php`):
```php
<?php

namespace DataAccessKit\Replication;

interface StreamCheckpointerInterface {
    public function loadLastCheckpoint(): ?string;
    public function saveCheckpoint(string $checkpoint): void;
}

interface StreamFilterInterface {
    public function accept(string $type, string $schema, string $table): bool;
}

interface EventInterface {
    public const string INSERT = 'INSERT';
    public const string UPDATE = 'UPDATE';
    public const string DELETE = 'DELETE';
    
    public string $type { get; }
    public int $timestamp { get; }
    public string $checkpoint { get; }
    public string $schema { get; }
    public string $table { get; }
}

final readonly class InsertEvent implements EventInterface {
    public function __construct(
        public string $type,
        public int $timestamp,
        public string $checkpoint,
        public string $schema,
        public string $table,
        public object $after
    ) {}
}

final readonly class UpdateEvent implements EventInterface {
    public function __construct(
        public string $type,
        public int $timestamp,
        public string $checkpoint,
        public string $schema,
        public string $table,
        public object $before,
        public object $after
    ) {}
}

final readonly class DeleteEvent implements EventInterface {
    public function __construct(
        public string $type,
        public int $timestamp,
        public string $checkpoint,
        public string $schema,
        public string $table,
        public object $before
    ) {}
}
```

### Rust Dependencies

```toml
[dependencies]
ext-php-rs = "0.14.2"
mysql_async = "0.33"
mysql-binlog-connector-rust = { git = "https://github.com/apecloud/mysql-binlog-connector-rust" }
tokio = { version = "1.0", features = ["full"] }
serde = { version = "1.0", features = ["derive"] }
serde_json = "1.0"
anyhow = "1.0"
log = "0.4"
```

### Key Rust Structures

**Current Implementation:** The Stream class is implemented in Rust with a driver-based architecture, while Event classes are implemented in PHP:

```rust
use ext_php_rs::prelude::*;

#[php_class]
#[php(name = "DataAccessKit\\\\Replication\\\\Stream")]
#[php(implements(ce = ce::iterator, stub = "Iterator"))]
#[derive(Debug)]
pub struct Stream {
    driver: Box<dyn StreamDriver>,
    position: u64,
}

// StreamDriver trait for database-specific implementations
trait StreamDriver: std::fmt::Debug {
    fn connect(&mut self) -> PhpResult<()>;
    fn disconnect(&mut self) -> PhpResult<()>;
    fn set_checkpointer(&mut self, checkpointer: &Zval) -> PhpResult<()>;
    fn set_filter(&mut self, filter: &Zval) -> PhpResult<()>;
    fn current(&self) -> PhpResult<Option<Zval>>;
    fn key(&self) -> PhpResult<i32>;
    fn next(&mut self) -> PhpResult<()>;
    fn rewind(&mut self) -> PhpResult<()>;
    fn valid(&self) -> PhpResult<bool>;
}
```

**Event Classes:** Event classes are implemented as PHP readonly classes (defined in `src/lib.php`) rather than Rust structs, providing better PHP integration and simpler property access using PHP 8.4's readonly class features.

### Threading and Async Handling

- Use Tokio runtime for async MySQL operations
- Implement proper synchronization for PHP thread safety
- Handle async stream to sync iterator conversion
- Implement proper resource cleanup on PHP request end


## Extension Metadata

### Build Process
```bash
# Create interfaces directory
mkdir -p interfaces

# Create interface definition file
cat > interfaces/replication.php << 'EOF'
<?php
namespace DataAccessKit\Replication {
    interface StreamCheckpointerInterface {
        public function loadLastCheckpoint(): ?string;
        public function saveCheckpoint(string $checkpoint): void;
    }
    
    interface StreamFilterInterface {
        public function accept(string $type, string $schema, string $table): bool;
    }
    
    interface EventInterface {
        public const string INSERT = 'INSERT';
        public const string UPDATE = 'UPDATE';
        public const string DELETE = 'DELETE';
        
        public string $type { get; }
        public int $timestamp { get; }
        public string $checkpoint { get; }
        public string $schema { get; }
        public string $table { get; }
    }
}
EOF

# Build (embeds interfaces at compile time)
cargo build --release
```

**Installation:**
```bash
# Install PHP dependencies (PHPUnit)
composer install

# Create local php.ini with extension
cat > php.ini << 'EOF'
; DataAccessKit Replication Extension Configuration
extension=data_access_kit_replication

; Optional: Enable extension debugging
; log_errors = On
; error_log = php_errors.log
EOF
```

### Testing

**Composer Configuration** (`composer.json`):
```json
{
    "name": "data-access-kit/replication",
    "description": "DataAccessKit Replication Extension",
    "type": "php-ext",
    "require": {
        "php": ">=8.4"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    },
    "autoload-dev": {
        "psr-4": {
            "DataAccessKit\\Replication\\Test\\": "test/"
        }
    },
    "scripts": {
        "test": "php -c php.ini vendor/bin/phpunit",
        "test-coverage": "php -c php.ini vendor/bin/phpunit --coverage-html coverage"
    }
}
```

**PHPUnit Configuration** (`phpunit.xml`):
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/11.0/phpunit.xsd"
         bootstrap="test/bootstrap.php"
         colors="true">
    <testsuites>
        <testsuite name="DataAccessKit Replication">
            <directory>test</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory suffix=".php">src</directory>
        </include>
    </source>
</phpunit>
```

**Test Bootstrap** (`test/bootstrap.php`):
```php
<?php

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Check if extension is loaded
if (!extension_loaded('data_access_kit_replication')) {
    throw new Exception(
        'DataAccessKit Replication extension is not loaded. ' .
        'Please install and enable the extension before running tests.'
    );
}

// Verify required classes are available
$requiredClasses = [
    'DataAccessKit\\Replication\\Stream',
    'DataAccessKit\\Replication\\InsertEvent',
    'DataAccessKit\\Replication\\UpdateEvent',
    'DataAccessKit\\Replication\\DeleteEvent',
];

foreach ($requiredClasses as $class) {
    if (!class_exists($class)) {
        throw new Exception("Required class {$class} is not available");
    }
}

echo "DataAccessKit Replication extension loaded successfully\n";
```

**Running Tests:**
```bash
# Build the extension (interfaces embedded at compile time)
cargo build --release

# Copy extension to local directory (no sudo needed)
cp target/release/libdata_access_kit_replication.so ./data_access_kit_replication.so

# Verify extension is loaded with local php.ini
php -c php.ini -m | grep data_access_kit_replication

# Run all tests with local php.ini
php -c php.ini vendor/bin/phpunit

# Run specific test
php -c php.ini vendor/bin/phpunit test/StreamTest.php

# Run with coverage
php -c php.ini vendor/bin/phpunit --coverage-html coverage
```

**Example Tests** (separate files for each interface):
```php
<?php

namespace DataAccessKit\Replication\Test;

use PHPUnit\Framework\TestCase;
use DataAccessKit\Replication\EventInterface;

class EventInterfaceTest extends TestCase
{
    public function testEventInterfaceExists(): void
    {
        $this->assertTrue(interface_exists(EventInterface::class));
    }
    
    public function testEventInterfaceConstants(): void
    {
        $this->assertEquals('INSERT', EventInterface::INSERT);
        $this->assertEquals('UPDATE', EventInterface::UPDATE);
        $this->assertEquals('DELETE', EventInterface::DELETE);
    }
}
```

**Directory Structure:**
```
data-access-kit-replication/
├── src/
│   ├── lib.rs             # Main Rust implementation
│   ├── lib.php            # Interface and event class definitions
│   └── mysql.rs           # MySQL driver implementation
├── test/                  # PHPUnit test directory
│   ├── bootstrap.php
│   ├── EventInterfaceTest.php
│   ├── StreamCheckpointerInterfaceTest.php
│   ├── StreamFilterInterfaceTest.php
│   └── StreamTest.php     # Stream class tests
├── Cargo.toml
├── composer.json          # PHPUnit dependency
├── php.ini                # Local PHP configuration
├── CLAUDE.md              # Development instructions
└── SPEC.md
```

