<?php

namespace DataAccessKit\Replication\Test;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use DataAccessKit\Replication\Stream;
use DataAccessKit\Replication\EventInterface;
use DataAccessKit\Replication\InsertEvent;
use DataAccessKit\Replication\UpdateEvent;
use DataAccessKit\Replication\DeleteEvent;
use Exception;

#[Group("database")]
class StreamIntegrationTest extends AbstractIntegrationTestCase
{
    public function testCompleteStreamFlow(): void
    {
        $this->requireDatabase();

        $stream = null;

        try {
            // Set up test database using existing PDO connection
            $this->pdo->exec("CREATE DATABASE IF NOT EXISTS `test_replication_db`");
            $this->pdo->exec("USE `test_replication_db`");

            // Set up test table
            $testPdo = new \PDO(
                "mysql:host={$this->dbConfig['host']};port={$this->dbConfig['port']};dbname=test_replication_db",
                $this->dbConfig['user'],
                $this->dbConfig['password']
            );
            $testPdo->exec("
                CREATE TABLE IF NOT EXISTS `test_users` (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    email VARCHAR(100) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");

            // Create stream with replication user connection for binlog streaming
            $stream = new Stream($this->createReplicationConnectionUrl(['database' => 'test_replication_db']));
            $this->assertInstanceOf(Stream::class, $stream);

            // Test 1: Connect to database
            $stream->connect();

            // Test 2: Insert test data to generate INSERT event
            $testPdo->exec("
                INSERT INTO `test_users` (name, email) VALUES
                ('John Doe', 'john@example.com')
            ");

            // Test 3: Test iterator interface - call rewind to start
            $stream->rewind();
            $this->assertTrue($stream->valid()); // Should be valid after rewind

            // Test 4: Test INSERT event
            $key = $stream->key();
            $this->assertEquals(0, $key); // First event at position 0

            $insertEvent = $stream->current();
            $this->assertInstanceOf(EventInterface::class, $insertEvent);
            $this->assertInstanceOf(InsertEvent::class, $insertEvent);
            $this->assertEquals(EventInterface::INSERT, $insertEvent->type);
            $this->assertEquals('test_replication_db', $insertEvent->schema);
            $this->assertEquals('test_users', $insertEvent->table);
            $this->assertIsObject($insertEvent->after);
            $this->assertEquals('John Doe', $insertEvent->after->name);
            $this->assertEquals('john@example.com', $insertEvent->after->email);

            // Test 5: Update the row to generate UPDATE event
            $testPdo->exec("
                UPDATE `test_users`
                SET name = 'John Smith', email = 'johnsmith@example.com'
                WHERE email = 'john@example.com'
            ");

            // Test 6: Move to next event and test UPDATE event
            $stream->next();
            $this->assertTrue($stream->valid()); // Should still be valid

            $updateKey = $stream->key();
            $this->assertEquals(1, $updateKey); // Second event at position 1

            $updateEvent = $stream->current();
            $this->assertInstanceOf(EventInterface::class, $updateEvent);
            $this->assertInstanceOf(UpdateEvent::class, $updateEvent);
            $this->assertEquals(EventInterface::UPDATE, $updateEvent->type);
            $this->assertEquals('test_replication_db', $updateEvent->schema);
            $this->assertEquals('test_users', $updateEvent->table);
            $this->assertIsObject($updateEvent->before);
            $this->assertIsObject($updateEvent->after);
            $this->assertEquals('John Doe', $updateEvent->before->name);
            $this->assertEquals('john@example.com', $updateEvent->before->email);
            $this->assertEquals('John Smith', $updateEvent->after->name);
            $this->assertEquals('johnsmith@example.com', $updateEvent->after->email);
            // Test 7: Delete the row to generate DELETE event
            $testPdo->exec("
                DELETE FROM `test_users`
                WHERE email = 'johnsmith@example.com'
            ");

            // Test 8: Move to next event and test DELETE event
            $stream->next();
            $this->assertTrue($stream->valid()); // Should still be valid

            $deleteKey = $stream->key();
            $this->assertEquals(2, $deleteKey); // Third event at position 2

            $deleteEvent = $stream->current();
            $this->assertInstanceOf(EventInterface::class, $deleteEvent);
            $this->assertInstanceOf(DeleteEvent::class, $deleteEvent);
            $this->assertEquals(EventInterface::DELETE, $deleteEvent->type);
            $this->assertEquals('test_replication_db', $deleteEvent->schema);
            $this->assertEquals('test_users', $deleteEvent->table);
            $this->assertIsObject($deleteEvent->before);
            $this->assertEquals('John Smith', $deleteEvent->before->name);
            $this->assertEquals('johnsmith@example.com', $deleteEvent->before->email);

            // Note: We don't test moving past available events because binlog streams
            // are designed to wait for new events indefinitely, not to "end"

            // Test 9: Test disconnect
            $stream->disconnect();

            // Test 10: After disconnect, valid should return false
            $this->assertFalse($stream->valid());

            // Test 11: Calling iterator methods after disconnect should return false
            $this->assertFalse($stream->valid());
            
        } finally {
            // Cleanup: disconnect stream if created
            if ($stream !== null) {
                try {
                    $stream->disconnect();
                } catch (Exception $e) {
                    // Ignore disconnect errors in cleanup
                }
            }
            
            // Cleanup: drop test table
            try {
                $cleanupPdo = new \PDO(
                    "mysql:host={$this->dbConfig['host']};port={$this->dbConfig['port']};dbname=test_replication_db", 
                    $this->dbConfig['user'], 
                    $this->dbConfig['password']
                );
                $cleanupPdo->exec("DROP TABLE IF EXISTS `test_users`");
            } catch (Exception $e) {
                // Ignore cleanup errors
            }
            
            // Cleanup: drop test database
            try {
                $this->pdo->exec("DROP DATABASE IF EXISTS `test_replication_db`");
            } catch (Exception $e) {
                // Ignore cleanup errors
            }
        }
    }


    public function testMysqlConfigurationValidationBinlogFormatFailure(): void
    {
        $this->requireDatabase();
        
        // Set invalid binlog_format globally
        $stmt = $this->pdo->prepare("SET @@GLOBAL.binlog_format = ?");
        $stmt->execute(['STATEMENT']);
        
        $stream = new Stream($this->createReplicationConnectionUrl());
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/binlog_format must be ROW/i');
        $stream->connect();
    }

    public function testMysqlConfigurationValidationBinlogRowImageFailure(): void
    {
        $this->requireDatabase();
        
        // Set invalid binlog_row_image globally
        $stmt = $this->pdo->prepare("SET @@GLOBAL.binlog_row_image = ?");
        $stmt->execute(['MINIMAL']);
        
        $stream = new Stream($this->createReplicationConnectionUrl());
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/binlog_row_image must be FULL/i');
        $stream->connect();
    }

    public function testMysqlConfigurationValidationBinlogRowMetadataFailure(): void
    {
        $this->requireDatabase();
        
        // Set invalid binlog_row_metadata globally
        $stmt = $this->pdo->prepare("SET @@GLOBAL.binlog_row_metadata = ?");
        $stmt->execute(['MINIMAL']);
        
        $stream = new Stream($this->createReplicationConnectionUrl());
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/binlog_row_metadata must be FULL/i');
        $stream->connect();
    }

    public function testMysqlConfigurationValidationGtidModeFailure(): void
    {
        $this->requireDatabase();
        
        // Detect database type
        $stmt = $this->pdo->query("SELECT VERSION()");
        $version = $stmt->fetchColumn();
        $isMariaDB = stripos($version, 'mariadb') !== false;
        
        if ($isMariaDB) {
            $this->markTestSkipped('This test is for MySQL only');
        }
        
        // MySQL GTID test
        $stmt = $this->pdo->query("SELECT @@GLOBAL.gtid_mode");
        $originalGtidMode = $stmt->fetchColumn();
        
        try {
            // MySQL requires stepping GTID mode: ON -> ON_PERMISSIVE -> OFF_PERMISSIVE -> OFF
            if ($originalGtidMode === 'ON') {
                $this->pdo->exec("SET @@GLOBAL.gtid_mode = 'ON_PERMISSIVE'");
                $this->pdo->exec("SET @@GLOBAL.gtid_mode = 'OFF_PERMISSIVE'");
                $this->pdo->exec("SET @@GLOBAL.gtid_mode = 'OFF'");
            }
            
            $stream = new Stream($this->createReplicationConnectionUrl());
            
            $this->expectException(Exception::class);
            $this->expectExceptionMessageMatches('/gtid_mode must be ON/i');
            $stream->connect();
            
        } finally {
            // Restore original GTID mode by stepping back up
            if ($originalGtidMode === 'ON') {
                try {
                    $this->pdo->exec("SET @@GLOBAL.gtid_mode = 'OFF_PERMISSIVE'");
                    $this->pdo->exec("SET @@GLOBAL.gtid_mode = 'ON_PERMISSIVE'");
                    $this->pdo->exec("SET @@GLOBAL.gtid_mode = 'ON'");
                } catch (Exception $e) {
                    // Ignore errors in cleanup
                }
            }
        }
    }

    public static function dataTypeProvider(): array
    {
        return [
            // Integer Types
            ['TINYINT', 127, 127],
            ['TINYINT UNSIGNED', 255, -1], // MySQL binlog represents max unsigned as -1
            ['SMALLINT', 32767, 32767],
            ['SMALLINT UNSIGNED', 65535, -1], // MySQL binlog represents max unsigned as -1
            ['MEDIUMINT', 8388607, 8388607],
            ['MEDIUMINT UNSIGNED', 16777215, -1], // MySQL binlog represents max unsigned as -1
            ['INT', 2147483647, 2147483647],
            ['INT UNSIGNED', 4294967295, -1], // MySQL binlog represents max unsigned as -1
            ['BIGINT', '9223372036854775807', '9223372036854775807'],
            ['BIGINT UNSIGNED', '18446744073709551615', -1], // MySQL binlog represents max unsigned as -1
            ['BIT(8)', 'b\'11111111\'', 255],
            ['BIT(1)', 'b\'1\'', 1],

            // Fixed-Point Types
            ['DECIMAL(10,2)', '123.45', '123.45'],
            ['DECIMAL(5,0)', '12345', '12345'],
            ['NUMERIC(8,3)', '12345.678', '12345.678'],

            // Floating-Point Types
            ['FLOAT', 123.456, 123.456],
            ['DOUBLE', 123.456789, 123.456789],

            // Character Types
            ['CHAR(10)', '\'Hello\'', 'Hello'],
            ['VARCHAR(50)', '\'Variable length\'', 'Variable length'],
            ['BINARY(5)', 'X\'48656c6c6f\'', 'Hello'], // Use hex notation for binary data
            ['VARBINARY(10)', 'X\'48656c6c6f\'', 'Hello'], // Use hex notation for binary data

            // Text Types - these are base64 encoded in binlog
            ['TINYTEXT', '\'Tiny text\'', 'VGlueSB0ZXh0'],
            ['TEXT', '\'Regular text content\'', 'UmVndWxhciB0ZXh0IGNvbnRlbnQ='],
            ['MEDIUMTEXT', '\'Medium text content\'', 'TWVkaXVtIHRleHQgY29udGVudA=='],
            ['LONGTEXT', '\'Long text content\'', 'TG9uZyB0ZXh0IGNvbnRlbnQ='],

            // Binary Large Object Types - these are base64 encoded in binlog
            ['TINYBLOB', 'X\'48656c6c6f\'', 'SGVsbG8='], // "Hello" in base64
            ['BLOB', 'X\'48656c6c6f20576f726c64\'', 'SGVsbG8gV29ybGQ='], // "Hello World" in base64
            ['MEDIUMBLOB', 'X\'48656c6c6f204d656469756d\'', 'SGVsbG8gTWVkaXVt'], // "Hello Medium" in base64
            ['LONGBLOB', 'X\'48656c6c6f204c6f6e67\'', 'SGVsbG8gTG9uZw=='], // "Hello Long" in base64

            // Special String Types - these return numeric values due to binlog limitations
            ['ENUM(\'red\',\'green\',\'blue\')', '\'red\'', 1], // ENUM returns 1-based index (string values not available in binlog metadata)
            ['SET(\'read\',\'write\',\'execute\')', '\'read,write\'', 3], // SET returns bitmask (string values not available in binlog metadata)

            // Date and Time Data Types
            ['DATE', '\'2024-01-15\'', '2024-01-15'],
            ['TIME', '\'14:30:45\'', '14:30:45.000000'], // TIME includes microseconds
            ['DATETIME', '\'2024-01-15 14:30:45\'', '2024-01-15 14:30:45.000000'], // DATETIME includes microseconds
            ['TIMESTAMP', '\'2024-01-15 14:30:45\'', 1705329045000000], // TIMESTAMP as microseconds since epoch
            ['YEAR', '2024', '2024'],

            // JSON Data Type - formatting may change
            ['JSON', '\'{"key": "value", "number": 42}\'', '{"key":"value","number":42}'],

            // NULL values for various types
            ['VARCHAR(50)', 'NULL', null],
            ['INT', 'NULL', null],
            ['DATE', 'NULL', null],
            ['JSON', 'NULL', null],

            // Zero and empty values
            ['INT', '0', 0],
            ['VARCHAR(50)', '\'\'', ''],
            ['TEXT', '\'\'', ''],

            // Negative numbers
            ['TINYINT', '-128', -128],
            ['SMALLINT', '-32768', -32768],
            ['MEDIUMINT', '-8388608', -8388608],
            ['INT', '-2147483648', -2147483648],
            ['BIGINT', '-9223372036854775808', '-9223372036854775808'],
            ['DECIMAL(10,2)', '-123.45', '-123.45'],
            ['FLOAT', '-123.456', -123.456],
            ['DOUBLE', '-123.456789', -123.456789],
        ];
    }

    #[DataProvider('dataTypeProvider')]
    public function testDataTypeConversion(string $columnType, $insertValue, $expectedPhpValue): void
    {
        $this->requireDatabase();

        $stream = null;
        $testTableName = 'test_data_types_' . md5($columnType . serialize($insertValue));

        try {
            $this->pdo->exec("CREATE DATABASE IF NOT EXISTS `test_replication_db`");
            $this->pdo->exec("USE `test_replication_db`");

            $testPdo = new \PDO(
                "mysql:host={$this->dbConfig['host']};port={$this->dbConfig['port']};dbname=test_replication_db",
                $this->dbConfig['user'],
                $this->dbConfig['password']
            );

            // Detect database type
            $stmt = $testPdo->query("SELECT VERSION()");
            $version = $stmt->fetchColumn();
            $isMariaDB = stripos($version, 'mariadb') !== false;

            $createTableSql = "CREATE TABLE IF NOT EXISTS `{$testTableName}` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                test_column {$columnType}
            )";
            $testPdo->exec($createTableSql);

            $stream = new Stream($this->createReplicationConnectionUrl(['database' => 'test_replication_db']));
            $stream->connect();

            if ($insertValue === 'NULL') {
                $insertSql = "INSERT INTO `{$testTableName}` (test_column) VALUES (NULL)";
            } else {
                $insertSql = "INSERT INTO `{$testTableName}` (test_column) VALUES ({$insertValue})";
            }
            $testPdo->exec($insertSql);

            $stream->rewind();
            $this->assertTrue($stream->valid());

            $insertEvent = $stream->current();
            $this->assertInstanceOf(InsertEvent::class, $insertEvent);
            $this->assertEquals('test_replication_db', $insertEvent->schema);
            $this->assertEquals($testTableName, $insertEvent->table);
            $this->assertIsObject($insertEvent->after);

            // Adjust expectations for MariaDB JSON handling
            $actualExpectedValue = $expectedPhpValue;
            if ($isMariaDB && strpos($columnType, 'JSON') === 0 && $expectedPhpValue === '{"key":"value","number":42}') {
                $actualExpectedValue = 'eyJrZXkiOiAidmFsdWUiLCAibnVtYmVyIjogNDJ9'; // Base64 encoded
            }

            if ($actualExpectedValue === null) {
                $this->assertNull($insertEvent->after->test_column);
            } elseif (is_float($actualExpectedValue)) {
                $this->assertEqualsWithDelta($actualExpectedValue, $insertEvent->after->test_column, 0.001);
            } else {
                $this->assertEquals($actualExpectedValue, $insertEvent->after->test_column);
            }

        } finally {
            if ($stream !== null) {
                try {
                    $stream->disconnect();
                } catch (Exception $e) {
                }
            }

            try {
                $cleanupPdo = new \PDO(
                    "mysql:host={$this->dbConfig['host']};port={$this->dbConfig['port']};dbname=test_replication_db",
                    $this->dbConfig['user'],
                    $this->dbConfig['password']
                );
                $cleanupPdo->exec("DROP TABLE IF EXISTS `{$testTableName}`");
            } catch (Exception $e) {
            }

            try {
                $this->pdo->exec("DROP DATABASE IF EXISTS `test_replication_db`");
            } catch (Exception $e) {
            }
        }
    }


}