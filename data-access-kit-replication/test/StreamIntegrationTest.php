<?php

namespace DataAccessKit\Replication\Test;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use DataAccessKit\Replication\Stream;
use DataAccessKit\Replication\EventInterface;
use DataAccessKit\Replication\InsertEvent;
use DataAccessKit\Replication\UpdateEvent;
use DataAccessKit\Replication\DeleteEvent;
use Exception;

#[Group("database")]
class StreamIntegrationTest extends TestCase
{
    private ?string $originalBinlogFormat = null;
    private ?string $originalBinlogRowImage = null;
    private ?string $originalBinlogRowMetadata = null;
    private ?\PDO $pdo = null;
    private ?array $dbConfig = null;
    private ?array $replicationConfig = null;

    protected function setUp(): void
    {
        $databaseUrl = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
        if (!$databaseUrl) {
            return; // Skip setup if no database URL
        }
        
        $parsedUrl = parse_url($databaseUrl);
        $this->dbConfig = [
            'host' => $parsedUrl['host'] ?? 'localhost',
            'port' => $parsedUrl['port'] ?? 3306,
            'user' => $parsedUrl['user'] ?? 'root',
            'password' => $parsedUrl['pass'] ?? '',
        ];
        
        // Get replication database URL for binlog streaming
        $replicationUrl = $_ENV['REPLICATION_DATABASE_URL'] ?? getenv('REPLICATION_DATABASE_URL');
        if ($replicationUrl) {
            $replicationParsedUrl = parse_url($replicationUrl);
            $this->replicationConfig = [
                'host' => $replicationParsedUrl['host'] ?? $this->dbConfig['host'],
                'port' => $replicationParsedUrl['port'] ?? $this->dbConfig['port'],
                'user' => $replicationParsedUrl['user'] ?? 'replication_test',
                'password' => $replicationParsedUrl['pass'] ?? 'replication_test',
            ];
        } else {
            // Fall back to using same credentials as main database
            $this->replicationConfig = $this->dbConfig;
        }
        
        try {
            $this->pdo = new \PDO(
                "mysql:host={$this->dbConfig['host']};port={$this->dbConfig['port']}", 
                $this->dbConfig['user'], 
                $this->dbConfig['password']
            );
            
            // Store original values
            $stmt = $this->pdo->query("SELECT @@GLOBAL.binlog_format");
            $this->originalBinlogFormat = $stmt->fetchColumn();
            
            $stmt = $this->pdo->query("SELECT @@GLOBAL.binlog_row_image");
            $this->originalBinlogRowImage = $stmt->fetchColumn();
            
            $stmt = $this->pdo->query("SELECT @@GLOBAL.binlog_row_metadata");
            $this->originalBinlogRowMetadata = $stmt->fetchColumn();
            
            // Set correct values for tests using prepared statements
            $stmt = $this->pdo->prepare("SET @@GLOBAL.binlog_format = ?");
            $stmt->execute(['ROW']);
            
            $stmt = $this->pdo->prepare("SET @@GLOBAL.binlog_row_image = ?");
            $stmt->execute(['FULL']);
            
            $stmt = $this->pdo->prepare("SET @@GLOBAL.binlog_row_metadata = ?");
            $stmt->execute(['FULL']);
            
        } catch (\Exception $e) {
            // Ignore setup errors for tests that don't need database
        }
    }
    
    protected function tearDown(): void
    {
        if ($this->pdo === null) {
            return;
        }
        
        try {
            // Restore original values using prepared statements
            if ($this->originalBinlogFormat !== null) {
                $stmt = $this->pdo->prepare("SET @@GLOBAL.binlog_format = ?");
                $stmt->execute([$this->originalBinlogFormat]);
            }
            if ($this->originalBinlogRowImage !== null) {
                $stmt = $this->pdo->prepare("SET @@GLOBAL.binlog_row_image = ?");
                $stmt->execute([$this->originalBinlogRowImage]);
            }
            if ($this->originalBinlogRowMetadata !== null) {
                $stmt = $this->pdo->prepare("SET @@GLOBAL.binlog_row_metadata = ?");
                $stmt->execute([$this->originalBinlogRowMetadata]);
            }
        } catch (\Exception $e) {
            // Ignore teardown errors
        }
        
        $this->pdo = null;
        $this->dbConfig = null;
    }

    private function createConnectionUrl(array $params = []): string
    {
        if ($this->dbConfig === null) {
            throw new \Exception('Database configuration not available');
        }
        
        // Extract database from params if provided
        $database = isset($params['database']) ? '/' . $params['database'] : '';
        unset($params['database']); // Remove from query params
        
        $queryParams = array_merge(['server_id' => '100'], $params);
        $queryString = http_build_query($queryParams);
        
        // Build URL based on whether password is provided
        if (!empty($this->dbConfig['password'])) {
            return sprintf(
                'mysql://%s:%s@%s:%d%s?%s',
                $this->dbConfig['user'],
                $this->dbConfig['password'],
                $this->dbConfig['host'],
                $this->dbConfig['port'],
                $database,
                $queryString
            );
        } else {
            return sprintf(
                'mysql://%s@%s:%d%s?%s',
                $this->dbConfig['user'],
                $this->dbConfig['host'],
                $this->dbConfig['port'],
                $database,
                $queryString
            );
        }
    }
    
    private function createReplicationConnectionUrl(array $params = []): string
    {
        if ($this->replicationConfig === null) {
            throw new \Exception('Replication configuration not available');
        }
        
        // Extract database from params if provided
        $database = isset($params['database']) ? '/' . $params['database'] : '';
        unset($params['database']); // Remove from query params
        
        $queryParams = array_merge(['server_id' => '100'], $params);
        $queryString = http_build_query($queryParams);
        
        // Build URL based on whether password is provided
        if (!empty($this->replicationConfig['password'])) {
            return sprintf(
                'mysql://%s:%s@%s:%d%s?%s',
                $this->replicationConfig['user'],
                $this->replicationConfig['password'],
                $this->replicationConfig['host'],
                $this->replicationConfig['port'],
                $database,
                $queryString
            );
        } else {
            return sprintf(
                'mysql://%s@%s:%d%s?%s',
                $this->replicationConfig['user'],
                $this->replicationConfig['host'],
                $this->replicationConfig['port'],
                $database,
                $queryString
            );
        }
    }
    public function testCompleteStreamFlow(): void
    {
        if ($this->pdo === null) {
            $this->markTestSkipped('DATABASE_URL environment variable is required');
        }
        
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
        if ($this->pdo === null) {
            $this->markTestSkipped('DATABASE_URL environment variable is required');
        }
        
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
        if ($this->pdo === null) {
            $this->markTestSkipped('DATABASE_URL environment variable is required');
        }
        
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
        if ($this->pdo === null) {
            $this->markTestSkipped('DATABASE_URL environment variable is required');
        }
        
        // Set invalid binlog_row_metadata globally
        $stmt = $this->pdo->prepare("SET @@GLOBAL.binlog_row_metadata = ?");
        $stmt->execute(['MINIMAL']);
        
        $stream = new Stream($this->createReplicationConnectionUrl());
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/binlog_row_metadata must be FULL/i');
        $stream->connect();
    }

}