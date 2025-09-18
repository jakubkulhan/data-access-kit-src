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
        $beforeData = (object)['id' => 1, 'name' => 'John', 'email' => 'john@example.com'];
        $afterData = (object)['id' => 1, 'name' => 'Jane', 'email' => 'jane@example.com'];
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
}