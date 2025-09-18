<?php

namespace DataAccessKit\Replication\Test;

use PHPUnit\Framework\Attributes\Group;
use DataAccessKit\Replication\Stream;
use DataAccessKit\Replication\StreamCheckpointerInterface;
use DataAccessKit\Replication\EventInterface;
use DataAccessKit\Replication\InsertEvent;

#[Group("database")]
class StreamCheckpointerIntegrationTest extends AbstractIntegrationTestCase
{
    protected function tearDown(): void
    {
        if ($this->pdo) {
            try {
                $this->pdo->exec("DROP DATABASE IF EXISTS `test_checkpointer_db`");
            } catch (\Exception $e) {
            }
        }

        parent::tearDown();
    }

    public function testNullCheckpointer(): void
    {
        $this->expectNotToPerformAssertions();
        $this->requireDatabase();

        $stream = new Stream($this->createReplicationConnectionUrl());

        $stream->setCheckpointer(null);
    }

    public function testInvalidCheckpointer(): void
    {
        $this->requireDatabase();

        $stream = new Stream($this->createReplicationConnectionUrl());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Checkpointer must be an object implementing StreamCheckpointerInterface');

        $stream->setCheckpointer("not an object");
    }

    public function testCheckpointerSaveCheckpoint(): void
    {
        $this->requireDatabase();

        $checkpointer = new class implements StreamCheckpointerInterface {
            public array $loadCalls = [];
            public array $saveCalls = [];

            public function loadLastCheckpoint(): ?string {
                $this->loadCalls[] = microtime(true);
                return null;
            }

            public function saveCheckpoint(string $checkpoint): void {
                $this->saveCalls[] = [
                    'checkpoint' => $checkpoint,
                    'timestamp' => microtime(true)
                ];
            }
        };

        $stream = null;

        try {
            $this->pdo->exec("CREATE DATABASE IF NOT EXISTS `test_checkpointer_db`");
            $this->pdo->exec("USE `test_checkpointer_db`");

            $testPdo = new \PDO(
                "mysql:host={$this->dbConfig['host']};port={$this->dbConfig['port']};dbname=test_checkpointer_db",
                $this->dbConfig['user'],
                $this->dbConfig['password']
            );
            $testPdo->exec("
                CREATE TABLE IF NOT EXISTS `test_checkpoint_table` (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    email VARCHAR(100) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");

            $stream = new Stream($this->createReplicationConnectionUrl(['database' => 'test_checkpointer_db']));
            $stream->setCheckpointer($checkpointer);

            $stream->connect();

            $testPdo->exec("
                INSERT INTO `test_checkpoint_table` (name, email) VALUES
                ('Test User', 'test@example.com')
            ");

            $stream->rewind();

            $this->assertNotEmpty($checkpointer->loadCalls, 'loadLastCheckpoint should be called during rewind');

            $this->assertTrue($stream->valid(), 'Stream should be valid after rewind');

            $insertEvent = $stream->current();
            $this->assertInstanceOf(EventInterface::class, $insertEvent, 'Should receive an event');
            $this->assertInstanceOf(InsertEvent::class, $insertEvent, 'Should be an InsertEvent');
            $this->assertEquals(EventInterface::INSERT, $insertEvent->type, 'Event type should be INSERT');
            $this->assertEquals('test_checkpointer_db', $insertEvent->schema, 'Schema should match');
            $this->assertEquals('test_checkpoint_table', $insertEvent->table, 'Table should match');

            $this->assertIsObject($insertEvent->after, 'InsertEvent should have after data');
            $this->assertEquals('Test User', $insertEvent->after->name, 'Name should match inserted value');
            $this->assertEquals('test@example.com', $insertEvent->after->email, 'Email should match inserted value');

            $this->assertNotEmpty($checkpointer->saveCalls, 'saveCheckpoint should be called during event processing');

            $latestSave = end($checkpointer->saveCalls);
            $this->assertIsArray($latestSave, 'Save call should be recorded');
            $this->assertArrayHasKey('checkpoint', $latestSave, 'Save call should have checkpoint');

            $savedCheckpoint = $latestSave['checkpoint'];
            $this->assertIsString($savedCheckpoint, 'Checkpoint should be a string');

            $this->assertTrue(
                str_starts_with($savedCheckpoint, 'gtid:') || str_starts_with($savedCheckpoint, 'file:'),
                'Checkpoint should start with "gtid:" or "file:" prefix. Got: ' . $savedCheckpoint
            );

            $this->assertEquals(
                $savedCheckpoint,
                $insertEvent->checkpoint,
                'Saved checkpoint should match the checkpoint in the InsertEvent'
            );

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