<?php

namespace DataAccessKit\Replication\Test;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use DataAccessKit\Replication\{EventInterface, DeleteEvent};
use Error;

#[Group("unit")]
class DeleteEventTest extends TestCase
{
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(DeleteEvent::class));
    }

    public function testClassImplementsInterface(): void
    {
        $this->assertTrue(is_subclass_of(DeleteEvent::class, EventInterface::class));
    }

    public function testCanConstructClassWithProperties(): void
    {
        $beforeData = (object)['id' => 1, 'name' => 'John', 'email' => 'john@example.com'];
        $timestamp = time();
        
        $event = new DeleteEvent(
            EventInterface::DELETE,
            $timestamp,
            'checkpoint789',
            'mydb',
            'users',
            $beforeData
        );

        $this->assertEquals(EventInterface::DELETE, $event->type);
        $this->assertEquals($timestamp, $event->timestamp);
        $this->assertEquals('checkpoint789', $event->checkpoint);
        $this->assertEquals('mydb', $event->schema);
        $this->assertEquals('users', $event->table);
        $this->assertEquals($beforeData, $event->before);
    }

    public function testTypePropertyIsReadonly(): void
    {
        $event = new DeleteEvent(
            EventInterface::DELETE,
            time(),
            'checkpoint789',
            'mydb',
            'users',
            (object)['id' => 1]
        );

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');
        $event->type = 'CHANGED';
    }

    public function testTimestampPropertyIsReadonly(): void
    {
        $event = new DeleteEvent(
            EventInterface::DELETE,
            time(),
            'checkpoint789',
            'mydb',
            'users',
            (object)['id' => 1]
        );

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');
        $event->timestamp = 999;
    }

    public function testCheckpointPropertyIsReadonly(): void
    {
        $event = new DeleteEvent(
            EventInterface::DELETE,
            time(),
            'checkpoint789',
            'mydb',
            'users',
            (object)['id' => 1]
        );

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');
        $event->checkpoint = 'changed';
    }

    public function testSchemaPropertyIsReadonly(): void
    {
        $event = new DeleteEvent(
            EventInterface::DELETE,
            time(),
            'checkpoint789',
            'mydb',
            'users',
            (object)['id' => 1]
        );

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');
        $event->schema = 'changed';
    }

    public function testTablePropertyIsReadonly(): void
    {
        $event = new DeleteEvent(
            EventInterface::DELETE,
            time(),
            'checkpoint789',
            'mydb',
            'users',
            (object)['id' => 1]
        );

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');
        $event->table = 'changed';
    }

    public function testBeforePropertyIsReadonly(): void
    {
        $event = new DeleteEvent(
            EventInterface::DELETE,
            time(),
            'checkpoint789',
            'mydb',
            'users',
            (object)['id' => 1]
        );

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');
        $event->before = (object)['id' => 2];
    }
}