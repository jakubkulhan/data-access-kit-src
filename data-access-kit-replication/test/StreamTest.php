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
        
        // Test that Stream class has all Iterator interface methods
        $this->assertTrue(method_exists($stream, 'current'), 'Stream should have current() method');
        $this->assertTrue(method_exists($stream, 'key'), 'Stream should have key() method');
        $this->assertTrue(method_exists($stream, 'next'), 'Stream should have next() method');
        $this->assertTrue(method_exists($stream, 'rewind'), 'Stream should have rewind() method');
        $this->assertTrue(method_exists($stream, 'valid'), 'Stream should have valid() method');
    }

    public function testStreamConnectThrowsTodoException(): void
    {
        $connectionUrl = 'mysql://user:password@localhost:3306?server_id=100';
        $stream = new Stream($connectionUrl);
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('TODO: will be implemented');
        
        $stream->connect();
    }

    public function testStreamDisconnectThrowsTodoException(): void
    {
        $connectionUrl = 'mysql://user:password@localhost:3306?server_id=100';
        $stream = new Stream($connectionUrl);
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('TODO: will be implemented');
        
        $stream->disconnect();
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

    public function testIteratorKey(): void
    {
        $connectionUrl = 'mysql://user:password@localhost:3306?server_id=100';
        $stream = new Stream($connectionUrl);
        
        // Initial key should be 0
        $this->assertEquals(0, $stream->key());
    }

    public function testIteratorCurrentThrowsTodoException(): void
    {
        $connectionUrl = 'mysql://user:password@localhost:3306?server_id=100';
        $stream = new Stream($connectionUrl);
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('TODO: will be implemented');
        
        $stream->current();
    }

    public function testIteratorNextThrowsTodoException(): void
    {
        $connectionUrl = 'mysql://user:password@localhost:3306?server_id=100';
        $stream = new Stream($connectionUrl);
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('TODO: will be implemented');
        
        $stream->next();
    }

    public function testIteratorRewindThrowsTodoException(): void
    {
        $connectionUrl = 'mysql://user:password@localhost:3306?server_id=100';
        $stream = new Stream($connectionUrl);
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('TODO: will be implemented');
        
        $stream->rewind();
    }

    public function testIteratorValidThrowsTodoException(): void
    {
        $connectionUrl = 'mysql://user:password@localhost:3306?server_id=100';
        $stream = new Stream($connectionUrl);
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('TODO: will be implemented');
        
        $stream->valid();
    }
}