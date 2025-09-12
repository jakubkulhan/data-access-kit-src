<?php

namespace DataAccessKit\Replication\Test;

use PHPUnit\Framework\TestCase;
use DataAccessKit\Replication\Stream;
use DataAccessKit\Replication\EventInterface;
use DataAccessKit\Replication\InsertEvent;
use DataAccessKit\Replication\UpdateEvent;
use DataAccessKit\Replication\DeleteEvent;
use Exception;

class StreamIntegrationTest extends TestCase
{
    public function testCompleteStreamFlow(): void
    {
        // Check for required DATABASE_URL environment variable
        $databaseUrl = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
        if (!$databaseUrl) {
            $this->fail('DATABASE_URL environment variable is required but not set. Example: mysql://user:password@host:3306/database');
        }
        
        // Parse database URL to extract connection components
        $parsedUrl = parse_url($databaseUrl);
        if (!$parsedUrl) {
            $this->fail('Invalid DATABASE_URL format. Expected: mysql://user:password@host:3306/database');
        }
        
        $host = $parsedUrl['host'] ?? 'localhost';
        $port = $parsedUrl['port'] ?? 3306;
        $user = $parsedUrl['user'] ?? 'root';
        $password = $parsedUrl['pass'] ?? '';
        $database = ltrim($parsedUrl['path'] ?? '', '/');
        
        if (empty($database)) {
            $this->fail('DATABASE_URL must include a database name in the path');
        }
        
        $stream = null;
        
        try {
            // Set up test database using parsed connection info
            $basePdo = new \PDO("mysql:host={$host};port={$port}", $user, $password);
            $basePdo->exec("CREATE DATABASE IF NOT EXISTS `test_replication_db`");
            $basePdo->exec("USE `test_replication_db`");
            
            // Set up test table
            $testPdo = new \PDO("mysql:host={$host};port={$port};dbname=test_replication_db", $user, $password);
            $testPdo->exec("
                CREATE TABLE IF NOT EXISTS `test_users` (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    email VARCHAR(100) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            // Create stream with MySQL connection using test database
            $connectionUrl = sprintf(
                'mysql://%s:%s@%s:%d/test_replication_db?server_id=100',
                $user,
                $password,
                $host,
                $port
            );
            $stream = new Stream($connectionUrl);
            $this->assertInstanceOf(Stream::class, $stream);
            
            // Test 1: Connect to database
            $stream->connect();
            
            // Test 2: Insert test data to generate INSERT event
            $testPdo = new \PDO("mysql:host={$host};port={$port};dbname=test_replication_db", $user, $password);
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
            
            // Test 9: Move past available events
            $stream->next();
            $this->assertFalse($stream->valid()); // Should be invalid past end
            
            // Test 10: Test disconnect
            $stream->disconnect();
            
            // Test 11: After disconnect, valid should return false
            $this->assertFalse($stream->valid());
            
            // Test 12: Calling iterator methods after disconnect should fail or return false
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
                $cleanupPdo = new \PDO("mysql:host={$host};port={$port};dbname=test_replication_db", $user, $password);
                $cleanupPdo->exec("DROP TABLE IF EXISTS `test_users`");
            } catch (Exception $e) {
                // Ignore cleanup errors
            }
            
            // Cleanup: drop test database
            try {
                $cleanupPdo = new \PDO("mysql:host={$host};port={$port}", $user, $password);
                $cleanupPdo->exec("DROP DATABASE IF EXISTS `test_replication_db`");
            } catch (Exception $e) {
                // Ignore cleanup errors
            }
        }
    }
}