<?php

namespace DataAccessKit\Replication\Test;

use PHPUnit\Framework\TestCase;
use DataAccessKit\Replication\{EventInterface, InsertEvent};
use Error;

class InsertEventTest extends TestCase
{
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(InsertEvent::class));
    }

    public function testClassImplementsInterface(): void
    {
        $this->assertTrue(is_subclass_of(InsertEvent::class, EventInterface::class));
    }

    public function testCanConstructClassWithProperties(): void
    {
        $afterData = (object)['id' => 1, 'name' => 'John', 'email' => 'john@example.com'];
        $timestamp = time();
        
        $event = new InsertEvent(
            EventInterface::INSERT,
            $timestamp,
            'checkpoint123',
            'mydb',
            'users',
            $afterData
        );

        $this->assertEquals(EventInterface::INSERT, $event->type);
        $this->assertEquals($timestamp, $event->timestamp);
        $this->assertEquals('checkpoint123', $event->checkpoint);
        $this->assertEquals('mydb', $event->schema);
        $this->assertEquals('users', $event->table);
        $this->assertEquals($afterData, $event->after);
    }

    public function testPropertiesAreReadonly(): void
    {
        $event = new InsertEvent(
            EventInterface::INSERT,
            time(),
            'checkpoint123',
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
        $event = new InsertEvent(
            EventInterface::INSERT,
            time(),
            'checkpoint123',
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
        $event = new InsertEvent(
            EventInterface::INSERT,
            time(),
            'checkpoint123',
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
        $event = new InsertEvent(
            EventInterface::INSERT,
            time(),
            'checkpoint123',
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
        $event = new InsertEvent(
            EventInterface::INSERT,
            time(),
            'checkpoint123',
            'mydb',
            'users',
            (object)['id' => 1]
        );

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');
        $event->table = 'changed';
    }

    public function testAfterPropertyIsReadonly(): void
    {
        $event = new InsertEvent(
            EventInterface::INSERT,
            time(),
            'checkpoint123',
            'mydb',
            'users',
            (object)['id' => 1]
        );

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');
        $event->after = (object)['id' => 2];
    }
}