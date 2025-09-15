<?php

namespace DataAccessKit\Replication\Test;

use PHPUnit\Framework\Attributes\Group;
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


}