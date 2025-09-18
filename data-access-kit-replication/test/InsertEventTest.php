<?php

namespace DataAccessKit\Replication\Test;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use DataAccessKit\Replication\{EventInterface, InsertEvent};
use Error;

#[Group("unit")]
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

}