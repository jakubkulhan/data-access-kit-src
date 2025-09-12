<?php

namespace DataAccessKit\Replication\Test;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use DataAccessKit\Replication\StreamFilterInterface;

#[Group("unit")]
class StreamFilterInterfaceTest extends TestCase
{
    public function testStreamFilterInterfaceExists(): void
    {
        $this->assertTrue(interface_exists(StreamFilterInterface::class));
    }
    
    public function testStreamFilterInterfaceHasRequiredMethods(): void
    {
        $reflection = new \ReflectionClass(StreamFilterInterface::class);
        
        $this->assertTrue($reflection->hasMethod('accept'));
        
        $acceptMethod = $reflection->getMethod('accept');
        $this->assertEquals('bool', (string)$acceptMethod->getReturnType());
        $this->assertCount(3, $acceptMethod->getParameters());
    }
}