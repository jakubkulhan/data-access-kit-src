# DataAccessKit\Replication

> Real-time MySQL/MariaDB binary log replication stream for PHP

## Quick start

Start by creating a replication stream to capture database changes in real-time.

```php
use DataAccessKit\Replication\Stream;

// Connect to MySQL replication stream
$stream = new Stream('mysql://user:password@localhost:3306');

// Process events as they occur
foreach ($stream as $event) {
    match ($event->type) {
        'INSERT' => handleInsert($event),
        'UPDATE' => handleUpdate($event),
        'DELETE' => handleDelete($event),
    };
}

function handleInsert($event) {
    echo "New record in {$event->schema}.{$event->table}\n";
    var_dump($event->after); // New row data
}

function handleUpdate($event) {
    echo "Updated record in {$event->schema}.{$event->table}\n";
    var_dump($event->before); // Old row data
    var_dump($event->after);  // New row data
}

function handleDelete($event) {
    echo "Deleted record from {$event->schema}.{$event->table}\n";
    var_dump($event->before); // Deleted row data
}
```

## Installation

### Prerequisites

- **PHP 8.4** or higher
- **Rust toolchain** (rustc, cargo)
- **cargo-php** for building PHP extensions

Install cargo-php:

```bash
cargo install cargo-php
```

### Build and Install Extension

```bash
# Clone the repository
git clone https://github.com/jakubkulhan/data-access-kit-replication.git
cd data-access-kit-replication

# Build the extension
cargo build --release

# Install the extension using cargo-php
cargo php install --release --yes
```

### Remove Extension

To uninstall the extension:

```bash
cargo php remove --yes
```

## Usage

### Stream

Initialize a stream to connect to the MySQL replication log:

```php
use DataAccessKit\Replication\Stream;

// Create stream with connection URL
$stream = new Stream('mysql://user:password@localhost:3306');

// Start iterating over events
foreach ($stream as $event) {
    // Process each replication event
    echo "Event: {$event->type} on {$event->schema}.{$event->table}\n";
}
```

Connection URL formats:

```php
// MySQL/MariaDB (user only)
$url = 'mysql://user@localhost:3306';

// MySQL/MariaDB (user and password)
$url = 'mysql://user:password@localhost:3306';

// MySQL/MariaDB (explicitly specify server ID)
$url = 'mysql://user:password@localhost:3306?server_id=123';
```

### Events

The extension provides three types of events for database changes:

#### InsertEvent

```php
// Properties available on InsertEvent
$event->type;       // 'INSERT'
$event->timestamp;  // Unix timestamp
$event->checkpoint; // Replication checkpoint
$event->schema;     // Database schema name
$event->table;      // Table name
$event->after;      // stdClass with new row data
```

#### UpdateEvent

```php
// Properties available on UpdateEvent
$event->type;       // 'UPDATE'
$event->timestamp;  // Unix timestamp
$event->checkpoint; // Replication checkpoint
$event->schema;     // Database schema name
$event->table;      // Table name
$event->before;     // stdClass with old row data
$event->after;      // stdClass with new row data
```

#### DeleteEvent

```php
// Properties available on DeleteEvent
$event->type;       // 'DELETE'
$event->timestamp;  // Unix timestamp
$event->checkpoint; // Replication checkpoint
$event->schema;     // Database schema name
$event->table;      // Table name
$event->before;     // stdClass with deleted row data
```

### Filter

Filter events to only process specific tables or event types:

```php
use DataAccessKit\Replication\{Stream, StreamFilterInterface};

class TableFilter implements StreamFilterInterface {
    public function __construct(private array $allowedTables) {}

    public function accept(string $type, string $schema, string $table): bool {
        return in_array("$schema.$table", $this->allowedTables);
    }
}

$stream = new Stream('mysql://root@localhost:32016?server_id=100');
$stream->setFilter(new TableFilter(['myapp.users', 'myapp.orders']));

foreach ($stream as $event) {
    // Only receives events for users and orders tables
    var_dump($event);
}
```

You can also filter by event type:

```php
class EventTypeFilter implements StreamFilterInterface {
    public function accept(string $type, string $schema, string $table): bool {
        // Only process INSERT and UPDATE events
        return in_array($type, ['INSERT', 'UPDATE']);
    }
}
```

### Checkpointer

Save and resume from specific positions in the binlog stream:

```php
use DataAccessKit\Replication\{Stream, StreamCheckpointerInterface};

class FileCheckpointer implements StreamCheckpointerInterface {
    public function __construct(private string $filename) {}

    public function loadLastCheckpoint(): ?string {
        return file_exists($this->filename) ? file_get_contents($this->filename) : null;
    }

    public function saveCheckpoint(string $checkpoint): void {
        file_put_contents($this->filename, $checkpoint);
    }
}

$stream = new Stream('mysql://root@localhost:32016?server_id=100');
$stream->setCheckpointer(new FileCheckpointer('/tmp/replication.checkpoint'));

foreach ($stream as $event) {
    // Process event...
    // Checkpoint is automatically saved by the extension
    var_dump($event);
}
```

For production systems, you'll probably want to use something like database-based checkpointing:

```php
class DatabaseCheckpointer implements StreamCheckpointerInterface {
    public function __construct(private PDO $pdo, private string $streamId) {}

    public function loadLastCheckpoint(): ?string {
        $stmt = $this->pdo->prepare('SELECT checkpoint FROM stream_positions WHERE stream_id = ?');
        $stmt->execute([$this->streamId]);
        return $stmt->fetchColumn() ?: null;
    }

    public function saveCheckpoint(string $checkpoint): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO stream_positions (stream_id, checkpoint, updated_at) VALUES (?, ?, NOW()) ' .
            'ON DUPLICATE KEY UPDATE checkpoint = VALUES(checkpoint), updated_at = NOW()'
        );
        $stmt->execute([$this->streamId, $checkpoint]);
    }
}
```

The extension supports two checkpoint formats:

- **GTID format (MySQL only)**: `gtid:3E11FA47-71CA-11E1-9E33-C80AA9429562:23`
- **File/position format**: `file:mysql-bin.000123:45678`

The extension automatically chooses the appropriate format based on server type and configuration.

## Contributing

This repository is part of the [DataAccessKit project](https://github.com/jakubkulhan/data-access-kit-src). Please open issues and pull requests in the main repository.

### Local Development Setup

For development, clone the source repository and install dependencies:

```bash
composer install
```

Start databases for testing:

```bash
# Start MySQL and MariaDB for testing
docker-compose up -d mysql mariadb
```

Build and test the extension:

```bash
# Build extension for development
cargo build

# Run unit tests (fast, no database required)
composer run test:unit

# Run database integration tests (requires running databases)
composer run test:database:all

# Run tests against specific databases
composer run test:database:mysql     # MySQL on port 32016
composer run test:database:mariadb   # MariaDB on port 35098
```

The test commands will:
1. Build the Rust extension (`cargo build`)
2. Load the extension via local PHP configuration
3. Run the specified PHPUnit test groups

## License

Licensed under MIT license. See [LICENSE](LICENSE) for details.
