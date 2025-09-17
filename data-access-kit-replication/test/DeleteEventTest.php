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
}