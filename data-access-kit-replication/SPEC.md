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

## MySQL Configuration Validation

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

### Validation Process

Validation occurs automatically during connection establishment:

```php
use DataAccessKit\Replication\Stream;

$stream = new Stream('mysql://user:pass@localhost:3306?server_id=100');

try {
    // Validation happens automatically in rewind() or explicit connect()
    $stream->connect();
} catch (Exception $e) {
    echo "MySQL binlog configuration invalid: " . $e->getMessage();
}
```

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

### Checkpointer Flow

1. **Stream Start**: Extension calls `loadLastCheckpoint()` to determine starting position
   - If `null` is returned, stream starts from current GTID (live events)
   - If string is returned, stream starts from that checkpoint position
2. **Event Processing**: Extension processes events from the starting checkpoint
3. **Periodic Checkpointing**: Extension calls `saveCheckpoint(string $checkpoint)` periodically with current position
4. **Stream Restart**: On reconnection, process repeats from step 1

### PHP Checkpointer Implementation

```php
use DataAccessKit\Replication\StreamCheckpointerInterface;

// File-based checkpointer
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
            'INSERT INTO stream_positions (stream_id, checkpoint) VALUES (?, ?) '
            . 'ON DUPLICATE KEY UPDATE checkpoint = VALUES(checkpoint)'
        );
        $stmt->execute([$this->streamId, $checkpoint]);
    }
}
```

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

$connectionUrl = 'mysql://repl_user:password@localhost:3306?server_id=100';
$stream = new Stream($connectionUrl);
$stream->setCheckpointer($checkpointer);
$stream->setFilter($filter);

foreach ($stream as $event) {
    // Process event
    processEvent($event);
    
    // Checkpointing is handled automatically by the extension
    // It calls $checkpointer->saveCheckpoint($position) periodically
}
```

## Implementation Details

### Interface Declaration Strategy

Since ext-php-rs doesn't support creating PHP interfaces directly from Rust, the interfaces must be declared by executing PHP code during extension startup. The extension embeds interface definitions at compile time using `include_str!()` to ensure no external file dependencies.

```rust
use ext_php_rs::prelude::*;

#[php_startup]
pub fn startup() {
    if let Err(e) = load_extension_interfaces() {
        eprintln!("Failed to load extension interfaces: {}", e);
    }
}

fn load_extension_interfaces() -> PhpResult<()> {
    let embedded_interfaces = include_str!("../interfaces/replication.php");
    execute_php_code(embedded_interfaces)?;
    Ok(())
}

fn execute_php_code(code: &str) -> PhpResult<()> {
    unsafe {
        let code_cstring = std::ffi::CString::new(code)?;
        let result = ext_php_rs::sys::zend_eval_string(
            code_cstring.as_ptr() as *mut i8,
            std::ptr::null_mut(),
            b"replication_interfaces.php\0".as_ptr() as *const i8,
        );
        
        if result == ext_php_rs::sys::FAILURE {
            return Err("Failed to execute PHP interface code".into());
        }
    }
    
    Ok(())
}
```

**Interface Definition File** (`interfaces/replication.php`):
```php
<?php
// interfaces/replication.php

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
```

### Rust Dependencies

```toml
[dependencies]
ext-php-rs = "0.11"
mysql_async = "0.33"
mysql-binlog-connector-rust = { git = "https://github.com/apecloud/mysql-binlog-connector-rust" }
tokio = { version = "1.0", features = ["full"] }
serde = { version = "1.0", features = ["derive"] }
serde_json = "1.0"
anyhow = "1.0"
log = "0.4"
```

### Key Rust Structures

```rust
use ext_php_rs::prelude::*;

#[php_class(name = "DataAccessKit\\Replication\\Stream")]
#[php(implements = "Iterator")]
pub struct Stream {
    connection_url: String,
    connection: Option<BinlogConnection>,
    checkpointer: Option<ZendObject>,
    filter: Option<ZendObject>,
    current_event: Option<ReplicationEvent>,
    position: u64,
}

#[php_class(name = "DataAccessKit\\Replication\\InsertEvent")]
#[php(implements = "DataAccessKit\\Replication\\EventInterface")]
pub struct InsertEvent {
    pub event_type: String,
    pub timestamp: u64,
    pub checkpoint: String,
    pub schema: String,
    pub table: String,
    pub after: ZendObject, // object with column name => value properties
}

#[php_class(name = "DataAccessKit\\Replication\\UpdateEvent")]
#[php(implements = "DataAccessKit\\Replication\\EventInterface")]
pub struct UpdateEvent {
    pub event_type: String,
    pub timestamp: u64,
    pub checkpoint: String,
    pub schema: String,
    pub table: String,
    pub before: ZendObject, // object with column name => value properties
    pub after: ZendObject,  // object with column name => value properties
}

#[php_class(name = "DataAccessKit\\Replication\\DeleteEvent")]
#[php(implements = "DataAccessKit\\Replication\\EventInterface")]
pub struct DeleteEvent {
    pub event_type: String,
    pub timestamp: u64,
    pub checkpoint: String,
    pub schema: String,
    pub table: String,
    pub before: ZendObject, // object with column name => value properties
}
```

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

**Example Test** (`test/StreamTest.php`):
```php
<?php

namespace DataAccessKit\Replication\Test;

use PHPUnit\Framework\TestCase;
use DataAccessKit\Replication\{Stream, EventInterface};

class StreamTest extends TestCase
{
    public function testStreamConstruction(): void
    {
        $stream = new Stream('mysql://user:pass@localhost:3306?server_id=100');
        $this->assertInstanceOf(Stream::class, $stream);
    }
    
    public function testEventConstants(): void
    {
        $this->assertEquals('INSERT', EventInterface::INSERT);
        $this->assertEquals('UPDATE', EventInterface::UPDATE);
        $this->assertEquals('DELETE', EventInterface::DELETE);
    }
    
    public function testStreamImplementsIterator(): void
    {
        $stream = new Stream('mysql://user:pass@localhost:3306?server_id=100');
        $this->assertInstanceOf(\Iterator::class, $stream);
    }
}
```

**Directory Structure:**
```
data-access-kit-replication/
├── src/
│   └── lib.rs
├── interfaces/
│   └── replication.php    # Interface definitions
├── test/                  # PHPUnit test directory
│   ├── bootstrap.php
│   ├── StreamTest.php
│   ├── CheckpointerTest.php
│   └── FilterTest.php
├── Cargo.toml
├── composer.json          # PHPUnit dependency
├── php.ini                # Local PHP configuration
└── SPEC.md
```

