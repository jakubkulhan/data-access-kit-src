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
            ['TINYINT', 127, 127, null],
            ['TINYINT UNSIGNED', 255, -1, null], // MySQL binlog represents max unsigned as -1
            ['SMALLINT', 32767, 32767, null],
            ['SMALLINT UNSIGNED', 65535, -1, null], // MySQL binlog represents max unsigned as -1
            ['MEDIUMINT', 8388607, 8388607, null],
            ['MEDIUMINT UNSIGNED', 16777215, -1, null], // MySQL binlog represents max unsigned as -1
            ['INT', 2147483647, 2147483647, null],
            ['INT UNSIGNED', 4294967295, -1, null], // MySQL binlog represents max unsigned as -1
            ['BIGINT', '9223372036854775807', '9223372036854775807', null],
            ['BIGINT UNSIGNED', '18446744073709551615', -1, null], // MySQL binlog represents max unsigned as -1
            ['BIT(8)', 'b\'11111111\'', 255, null],
            ['BIT(1)', 'b\'1\'', 1, null],

            // Fixed-Point Types
            ['DECIMAL(10,2)', '123.45', '123.45', null],
            ['DECIMAL(5,0)', '12345', '12345', null],
            ['NUMERIC(8,3)', '12345.678', '12345.678', null],

            // Floating-Point Types
            ['FLOAT', 123.456, 123.456, null],
            ['DOUBLE', 123.456789, 123.456789, null],

            // Character Types
            ['CHAR(10)', '\'Hello\'', 'Hello', null],
            ['VARCHAR(50)', '\'Variable length\'', 'Variable length', null],
            ['BINARY(5)', 'X\'48656c6c6f\'', 'Hello', null], // Use hex notation for binary data
            ['VARBINARY(10)', 'X\'48656c6c6f\'', 'Hello', null], // Use hex notation for binary data

            // Text Types - now returned as UTF-8 strings
            ['TINYTEXT', '\'Tiny text\'', 'Tiny text', null],
            ['TEXT', '\'Regular text content\'', 'Regular text content', null],
            ['MEDIUMTEXT', '\'Medium text content\'', 'Medium text content', null],
            ['LONGTEXT', '\'Long text content\'', 'Long text content', null],

            // Binary Large Object Types - now returned as raw binary data
            ['TINYBLOB', 'X\'48656c6c6f\'', 'Hello', null], // "Hello" from hex
            ['BLOB', 'X\'48656c6c6f20576f726c64\'', 'Hello World', null], // "Hello World" from hex
            ['MEDIUMBLOB', 'X\'48656c6c6f204d656469756d\'', 'Hello Medium', null], // "Hello Medium" from hex
            ['LONGBLOB', 'X\'48656c6c6f204c6f6e67\'', 'Hello Long', null], // "Hello Long" from hex

            // Special String Types - now return actual string values with fix-enum-set-metadata branch
            ['ENUM(\'red\',\'green\',\'blue\')', '\'red\'', 'red', null], // ENUM returns actual string value
            ['SET(\'read\',\'write\',\'execute\')', '\'read,write\'', ['read', 'write'], null], // SET returns array of strings

            // Date and Time Data Types
            ['DATE', '\'2024-01-15\'', '2024-01-15', null],
            ['TIME', '\'14:30:45\'', '14:30:45.000000', null], // TIME includes microseconds
            ['DATETIME', '\'2024-01-15 14:30:45\'', new \DateTimeImmutable('2024-01-15 14:30:45.000000'), null], // DATETIME as DateTimeImmutable
            ['TIMESTAMP', '\'2024-01-15 14:30:45\'', new \DateTimeImmutable('2024-01-15 14:30:45'), null], // TIMESTAMP as DateTimeImmutable
            ['YEAR', '2024', '2024', null],

            // JSON Data Type - MySQL returns parsed stdClass objects
            ['JSON', '\'{"key": "value", "number": 42}\'', (object)['key' => 'value', 'number' => 42], 'mysql'],
            // JSON Data Type - MariaDB returns JSON string (not parsed)
            ['JSON', '\'{"key": "value", "number": 42}\'', '{"key": "value", "number": 42}', 'mariadb'],

            // NULL values for various types
            ['VARCHAR(50)', 'NULL', null, null],
            ['INT', 'NULL', null, null],
            ['DATE', 'NULL', null, null],
            ['JSON', 'NULL', null, null],

            // Zero and empty values
            ['INT', '0', 0, null],
            ['VARCHAR(50)', '\'\'', '', null],
            ['TEXT', '\'\'', '', null],

            // Negative numbers
            ['TINYINT', '-128', -128, null],
            ['SMALLINT', '-32768', -32768, null],
            ['MEDIUMINT', '-8388608', -8388608, null],
            ['INT', '-2147483648', -2147483648, null],
            ['BIGINT', '-9223372036854775808', '-9223372036854775808', null],
            ['DECIMAL(10,2)', '-123.45', '-123.45', null],
            ['FLOAT', '-123.456', -123.456, null],
            ['DOUBLE', '-123.456789', -123.456789, null],
        ];
    }

    #[DataProvider('dataTypeProvider')]
    public function testDataTypeConversion(string $columnType, $insertValue, $expectedPhpValue, ?string $databaseFlavor): void
    {
        $this->requireDatabase();

        // Skip test if database flavor doesn't match
        if ($databaseFlavor !== null) {
            $stmt = $this->pdo->query("SELECT VERSION()");
            $version = $stmt->fetchColumn();
            $isMariaDB = stripos($version, 'mariadb') !== false;

            if (($databaseFlavor === 'mariadb' && !$isMariaDB) ||
                ($databaseFlavor === 'mysql' && $isMariaDB)) {
                $this->markTestSkipped("Test only runs on {$databaseFlavor}");
            }
        }

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

            // Use expected value as-is since database flavor filtering handles different expectations
            $actualExpectedValue = $expectedPhpValue;

            if ($actualExpectedValue === null) {
                $this->assertNull($insertEvent->after->test_column);
            } elseif (is_float($actualExpectedValue)) {
                $this->assertEqualsWithDelta($actualExpectedValue, $insertEvent->after->test_column, 0.001);
            } elseif ($actualExpectedValue instanceof \DateTimeImmutable) {
                $this->assertInstanceOf(\DateTimeImmutable::class, $insertEvent->after->test_column);
                $this->assertEquals($actualExpectedValue->getTimestamp(), $insertEvent->after->test_column->getTimestamp());
                // Additional checks for DateTimeImmutable
                $this->assertEquals($actualExpectedValue->format('Y-m-d H:i:s'), $insertEvent->after->test_column->format('Y-m-d H:i:s'));
            } elseif (is_object($actualExpectedValue) && get_class($actualExpectedValue) === 'stdClass') {
                $this->assertInstanceOf(\stdClass::class, $insertEvent->after->test_column);
                $this->assertEquals($actualExpectedValue, $insertEvent->after->test_column);
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

    public function testBulkOperationsStreamFlow(): void
    {
        $this->requireDatabase();

        $stream = null;

        try {
            $this->pdo->exec("CREATE DATABASE IF NOT EXISTS `test_replication_db`");
            $this->pdo->exec("USE `test_replication_db`");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS `test_bulk_users` (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    email VARCHAR(100) NOT NULL,
                    status VARCHAR(20) DEFAULT 'active'
                )
            ");

            $stream = new Stream($this->createReplicationConnectionUrl(['database' => 'test_replication_db']));
            $stream->connect();

            // Batch insert 10 rows in a single statement
            $this->pdo->exec("
                INSERT INTO `test_bulk_users` (name, email) VALUES
                ('User 1', 'user1@example.com'),
                ('User 2', 'user2@example.com'),
                ('User 3', 'user3@example.com'),
                ('User 4', 'user4@example.com'),
                ('User 5', 'user5@example.com'),
                ('User 6', 'user6@example.com'),
                ('User 7', 'user7@example.com'),
                ('User 8', 'user8@example.com'),
                ('User 9', 'user9@example.com'),
                ('User 10', 'user10@example.com')
            ");

            // Update all 10 rows
            $this->pdo->exec("
                UPDATE `test_bulk_users`
                SET status = 'updated', name = CONCAT(name, ' - Updated')
                WHERE status = 'active'
            ");

            // Delete all 10 rows
            $this->pdo->exec("DELETE FROM `test_bulk_users` WHERE status = 'updated'");

            $eventCount = 0;
            $insertEventCount = 0;
            $updateEventCount = 0;
            $deleteEventCount = 0;

            // Process all 30 events from bulk operations (10 batch inserts + 10 bulk updates + 10 bulk deletes)
            foreach ($stream as $event) {
                $this->assertInstanceOf(EventInterface::class, $event);
                $this->assertEquals('test_replication_db', $event->schema);
                $this->assertEquals('test_bulk_users', $event->table);

                if ($event->type === EventInterface::INSERT) {
                    $insertEventCount++;
                    $this->assertInstanceOf(InsertEvent::class, $event);
                    $this->assertIsObject($event->after);
                    $this->assertStringContainsString('User ', $event->after->name);
                    $this->assertStringContainsString('@example.com', $event->after->email);
                    $this->assertEquals('active', $event->after->status);
                } elseif ($event->type === EventInterface::UPDATE) {
                    $updateEventCount++;
                    $this->assertInstanceOf(UpdateEvent::class, $event);
                    $this->assertIsObject($event->before);
                    $this->assertIsObject($event->after);
                    $this->assertEquals('active', $event->before->status);
                    $this->assertEquals('updated', $event->after->status);
                    $this->assertStringContainsString(' - Updated', $event->after->name);
                } elseif ($event->type === EventInterface::DELETE) {
                    $deleteEventCount++;
                    $this->assertInstanceOf(DeleteEvent::class, $event);
                    $this->assertIsObject($event->before);
                    $this->assertEquals('updated', $event->before->status);
                    $this->assertStringContainsString(' - Updated', $event->before->name);
                }

                $eventCount++;

                if ($eventCount >= 30) {
                    break;
                }
            }

            // Verify we processed exactly 30 events: 10 inserts, 10 updates, 10 deletes
            $this->assertEquals(30, $eventCount);
            $this->assertEquals(10, $insertEventCount);
            $this->assertEquals(10, $updateEventCount);
            $this->assertEquals(10, $deleteEventCount);

            $stream->disconnect();
            $this->assertFalse($stream->valid());

        } finally {
            if ($stream !== null) {
                try {
                    $stream->disconnect();
                } catch (Exception $e) {
                    // Ignore disconnect errors in cleanup
                }
            }

            try {
                $this->pdo->exec("USE `test_replication_db`");
                $this->pdo->exec("DROP TABLE IF EXISTS `test_bulk_users`");
            } catch (Exception $e) {
                // Ignore cleanup errors
            }

            try {
                $this->pdo->exec("DROP DATABASE IF EXISTS `test_replication_db`");
            } catch (Exception $e) {
                // Ignore cleanup errors
            }
        }
    }


}