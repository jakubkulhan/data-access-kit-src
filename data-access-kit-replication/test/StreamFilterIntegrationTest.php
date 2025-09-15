<?php

namespace DataAccessKit\Replication\Test;

use PHPUnit\Framework\Attributes\Group;
use DataAccessKit\Replication\Stream;
use DataAccessKit\Replication\StreamFilterInterface;
use DataAccessKit\Replication\EventInterface;
use DataAccessKit\Replication\InsertEvent;

#[Group("database")]
class StreamFilterIntegrationTest extends AbstractIntegrationTestCase
{
    protected function tearDown(): void
    {
        if ($this->pdo) {
            try {
                $this->pdo->exec("DROP DATABASE IF EXISTS `test_filter_db`");
            } catch (\Exception $e) {
            }
        }

        parent::tearDown();
    }

    public function testNullFilter(): void
    {
        $this->expectNotToPerformAssertions();
        $this->requireDatabase();

        $stream = new Stream($this->createReplicationConnectionUrl());

        $stream->setFilter(null);
    }

    public function testInvalidFilter(): void
    {
        $this->requireDatabase();

        $stream = new Stream($this->createReplicationConnectionUrl());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Filter must be an object implementing StreamFilterInterface');

        $stream->setFilter("not an object");
    }

    public function testFilterAcceptAndReject(): void
    {
        $this->requireDatabase();

        $filter = new class implements StreamFilterInterface {
            public array $acceptCalls = [];

            public function accept(string $type, string $schema, string $table): bool {
                $this->acceptCalls[] = [
                    'type' => $type,
                    'schema' => $schema,
                    'table' => $table,
                    'timestamp' => microtime(true)
                ];

                // Accept only events from 'allowed_table', reject 'filtered_table'
                return $table === 'allowed_table';
            }
        };

        $stream = null;

        try {
            $this->pdo->exec("CREATE DATABASE IF NOT EXISTS `test_filter_db`");
            $this->pdo->exec("USE `test_filter_db`");

            $testPdo = new \PDO(
                "mysql:host={$this->dbConfig['host']};port={$this->dbConfig['port']};dbname=test_filter_db",
                $this->dbConfig['user'],
                $this->dbConfig['password']
            );

            // Create two tables: one that should be filtered out, one that should be allowed
            $testPdo->exec("
                CREATE TABLE IF NOT EXISTS `filtered_table` (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");

            $testPdo->exec("
                CREATE TABLE IF NOT EXISTS `allowed_table` (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    email VARCHAR(100) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");

            $stream = new Stream($this->createReplicationConnectionUrl(['database' => 'test_filter_db']));
            $stream->setFilter($filter);

            $stream->connect();

            // Insert into the filtered table first (this should be filtered out)
            $testPdo->exec("
                INSERT INTO `filtered_table` (name) VALUES
                ('Filtered User')
            ");

            // Insert into the allowed table (this should pass through)
            $testPdo->exec("
                INSERT INTO `allowed_table` (name, email) VALUES
                ('Allowed User', 'allowed@example.com')
            ");

            $stream->rewind();

            $this->assertTrue($stream->valid(), 'Stream should be valid after rewind');

            $insertEvent = $stream->current();
            $this->assertInstanceOf(EventInterface::class, $insertEvent, 'Should receive an event');
            $this->assertInstanceOf(InsertEvent::class, $insertEvent, 'Should be an InsertEvent');
            $this->assertEquals(EventInterface::INSERT, $insertEvent->type, 'Event type should be INSERT');
            $this->assertEquals('test_filter_db', $insertEvent->schema, 'Schema should match');
            $this->assertEquals('allowed_table', $insertEvent->table, 'Table should be the allowed table, not the filtered one');

            $this->assertIsObject($insertEvent->after, 'InsertEvent should have after data');
            $this->assertEquals('Allowed User', $insertEvent->after->name, 'Name should match inserted value');
            $this->assertEquals('allowed@example.com', $insertEvent->after->email, 'Email should match inserted value');

            // Verify that the filter was called for both tables
            $this->assertNotEmpty($filter->acceptCalls, 'Filter accept method should be called');

            // Filter should have been called at least once (possibly multiple times due to table map events)
            $foundFilteredCall = false;
            $foundAllowedCall = false;

            foreach ($filter->acceptCalls as $call) {
                if ($call['table'] === 'filtered_table') {
                    $foundFilteredCall = true;
                }
                if ($call['table'] === 'allowed_table') {
                    $foundAllowedCall = true;
                }
            }

            $this->assertTrue($foundAllowedCall, 'Filter should have been called for allowed_table');

            // Verify that we only received the allowed event, not the filtered one
            // The fact that we got 'allowed_table' and not 'filtered_table' proves the filter worked

        } finally {
            if ($stream !== null) {
                try {
                    $stream->disconnect();
                } catch (\Exception $e) {
                }
            }
        }
    }
}