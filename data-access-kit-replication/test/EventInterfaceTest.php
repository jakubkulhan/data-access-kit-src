<?php

namespace DataAccessKit\Replication\Test;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use DataAccessKit\Replication\EventInterface;

#[Group("unit")]
class EventInterfaceTest extends TestCase
{
    public function testEventInterfaceExists(): void
    {
        $this->assertTrue(interface_exists(EventInterface::class));
    }
    
    public function testEventInterfaceConstants(): void
    {
        $this->assertEquals('INSERT', EventInterface::INSERT);
        $this->assertEquals('UPDATE', EventInterface::UPDATE);
        $this->assertEquals('DELETE', EventInterface::DELETE);
    }
}