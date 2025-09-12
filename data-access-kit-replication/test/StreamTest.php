<?php

namespace DataAccessKit\Replication\Test;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use DataAccessKit\Replication\Stream;
use DataAccessKit\Replication\StreamCheckpointerInterface;
use DataAccessKit\Replication\StreamFilterInterface;
use Exception;

#[Group("unit")]
class StreamTest extends TestCase
{
    public function testStreamClassExists(): void
    {
        $this->assertTrue(class_exists(Stream::class));
    }

    public function testStreamConstructor(): void
    {
        $connectionUrl = 'mysql://user:password@localhost:3306?server_id=100';
        $stream = new Stream($connectionUrl);
        
        $this->assertInstanceOf(Stream::class, $stream);
    }

    public function testStreamImplementsIterator(): void
    {
        $connectionUrl = 'mysql://user:password@localhost:3306?server_id=100';
        $stream = new Stream($connectionUrl);
        
        // Test that Stream class implements Iterator interface
        $this->assertInstanceOf(\Iterator::class, $stream);
    }

    public function testStreamHasConnectMethod(): void
    {
        $connectionUrl = 'mysql://user:password@localhost:3306?server_id=100';
        $stream = new Stream($connectionUrl);
        
        $this->assertTrue(method_exists($stream, 'connect'), 'Stream should have connect() method');
    }

    public function testStreamHasDisconnectMethod(): void
    {
        $connectionUrl = 'mysql://user:password@localhost:3306?server_id=100';
        $stream = new Stream($connectionUrl);
        
        $this->assertTrue(method_exists($stream, 'disconnect'), 'Stream should have disconnect() method');
    }

    public function testStreamSetCheckpointer(): void
    {
        $this->expectNotToPerformAssertions();
        
        $connectionUrl = 'mysql://user:password@localhost:3306?server_id=100';
        $stream = new Stream($connectionUrl);
        
        $checkpointer = new class implements StreamCheckpointerInterface {
            public function loadLastCheckpoint(): ?string {
                return null;
            }
            
            public function saveCheckpoint(string $checkpoint): void {
                // Mock implementation
            }
        };
        
        // Should not throw an exception
        $stream->setCheckpointer($checkpointer);
    }

    public function testStreamSetFilter(): void
    {
        $this->expectNotToPerformAssertions();
        
        $connectionUrl = 'mysql://user:password@localhost:3306?server_id=100';
        $stream = new Stream($connectionUrl);
        
        $filter = new class implements StreamFilterInterface {
            public function accept(string $type, string $schema, string $table): bool {
                return true;
            }
        };
        
        // Should not throw an exception
        $stream->setFilter($filter);
    }

    public function testIteratorHasKeyMethod(): void
    {
        $connectionUrl = 'mysql://user:password@localhost:3306?server_id=100';
        $stream = new Stream($connectionUrl);
        
        $this->assertTrue(method_exists($stream, 'key'), 'Stream should have key() method');
    }

    public function testIteratorHasCurrentMethod(): void
    {
        $connectionUrl = 'mysql://user:password@localhost:3306?server_id=100';
        $stream = new Stream($connectionUrl);
        
        $this->assertTrue(method_exists($stream, 'current'), 'Stream should have current() method');
    }

    public function testIteratorHasNextMethod(): void
    {
        $connectionUrl = 'mysql://user:password@localhost:3306?server_id=100';
        $stream = new Stream($connectionUrl);
        
        $this->assertTrue(method_exists($stream, 'next'), 'Stream should have next() method');
    }

    public function testIteratorHasRewindMethod(): void
    {
        $connectionUrl = 'mysql://user:password@localhost:3306?server_id=100';
        $stream = new Stream($connectionUrl);
        
        $this->assertTrue(method_exists($stream, 'rewind'), 'Stream should have rewind() method');
    }

    public function testIteratorHasValidMethod(): void
    {
        $connectionUrl = 'mysql://user:password@localhost:3306?server_id=100';
        $stream = new Stream($connectionUrl);
        
        $this->assertTrue(method_exists($stream, 'valid'), 'Stream should have valid() method');
    }
}