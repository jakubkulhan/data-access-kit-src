<?php

namespace DataAccessKit\Replication\Test;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use DataAccessKit\Replication\StreamCheckpointerInterface;

#[Group("unit")]
class StreamCheckpointerInterfaceTest extends TestCase
{
    public function testStreamCheckpointerInterfaceExists(): void
    {
        $this->assertTrue(interface_exists(StreamCheckpointerInterface::class));
    }
    
    public function testStreamCheckpointerInterfaceHasRequiredMethods(): void
    {
        $reflection = new \ReflectionClass(StreamCheckpointerInterface::class);
        
        $this->assertTrue($reflection->hasMethod('loadLastCheckpoint'));
        $this->assertTrue($reflection->hasMethod('saveCheckpoint'));
        
        $loadMethod = $reflection->getMethod('loadLastCheckpoint');
        $this->assertEquals('?string', (string)$loadMethod->getReturnType());
        
        $saveMethod = $reflection->getMethod('saveCheckpoint');
        $this->assertEquals('void', (string)$saveMethod->getReturnType());
    }
}