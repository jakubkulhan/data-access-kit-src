<?php

namespace DataAccessKit\Replication\Test;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use DataAccessKit\Replication\{EventInterface, UpdateEvent};
use Error;

#[Group("unit")]
class UpdateEventTest extends TestCase
{
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(UpdateEvent::class));
    }

    public function testClassImplementsInterface(): void
    {
        $this->assertTrue(is_subclass_of(UpdateEvent::class, EventInterface::class));
    }

    public function testCanConstructClassWithProperties(): void
    {
        $beforeData = (object)['id' => 1, 'name' => 'John', 'email' => 'john@old.com'];
        $afterData = (object)['id' => 1, 'name' => 'John', 'email' => 'john@new.com'];
        $timestamp = time();
        
        $event = new UpdateEvent(
            EventInterface::UPDATE,
            $timestamp,
            'checkpoint456',
            'mydb',
            'users',
            $beforeData,
            $afterData
        );

        $this->assertEquals(EventInterface::UPDATE, $event->type);
        $this->assertEquals($timestamp, $event->timestamp);
        $this->assertEquals('checkpoint456', $event->checkpoint);
        $this->assertEquals('mydb', $event->schema);
        $this->assertEquals('users', $event->table);
        $this->assertEquals($beforeData, $event->before);
        $this->assertEquals($afterData, $event->after);
    }

    public function testTypePropertyIsReadonly(): void
    {
        $event = new UpdateEvent(
            EventInterface::UPDATE,
            time(),
            'checkpoint456',
            'mydb',
            'users',
            (object)['id' => 1],
            (object)['id' => 1, 'updated' => true]
        );

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');
        $event->type = 'CHANGED';
    }

    public function testTimestampPropertyIsReadonly(): void
    {
        $event = new UpdateEvent(
            EventInterface::UPDATE,
            time(),
            'checkpoint456',
            'mydb',
            'users',
            (object)['id' => 1],
            (object)['id' => 1, 'updated' => true]
        );

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');
        $event->timestamp = 999;
    }

    public function testCheckpointPropertyIsReadonly(): void
    {
        $event = new UpdateEvent(
            EventInterface::UPDATE,
            time(),
            'checkpoint456',
            'mydb',
            'users',
            (object)['id' => 1],
            (object)['id' => 1, 'updated' => true]
        );

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');
        $event->checkpoint = 'changed';
    }

    public function testSchemaPropertyIsReadonly(): void
    {
        $event = new UpdateEvent(
            EventInterface::UPDATE,
            time(),
            'checkpoint456',
            'mydb',
            'users',
            (object)['id' => 1],
            (object)['id' => 1, 'updated' => true]
        );

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');
        $event->schema = 'changed';
    }

    public function testTablePropertyIsReadonly(): void
    {
        $event = new UpdateEvent(
            EventInterface::UPDATE,
            time(),
            'checkpoint456',
            'mydb',
            'users',
            (object)['id' => 1],
            (object)['id' => 1, 'updated' => true]
        );

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');
        $event->table = 'changed';
    }

    public function testBeforePropertyIsReadonly(): void
    {
        $event = new UpdateEvent(
            EventInterface::UPDATE,
            time(),
            'checkpoint456',
            'mydb',
            'users',
            (object)['id' => 1],
            (object)['id' => 1, 'updated' => true]
        );

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');
        $event->before = (object)['id' => 2];
    }

    public function testAfterPropertyIsReadonly(): void
    {
        $event = new UpdateEvent(
            EventInterface::UPDATE,
            time(),
            'checkpoint456',
            'mydb',
            'users',
            (object)['id' => 1],
            (object)['id' => 1, 'updated' => true]
        );

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');
        $event->after = (object)['id' => 2];
    }
}