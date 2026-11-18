<?php

namespace Tests\Unit;

use App\Support\Tr069Value;
use PHPUnit\Framework\TestCase;

class Tr069ValueTest extends TestCase
{
    public function test_cast_follows_device_type(): void
    {
        $this->assertSame(1, Tr069Value::cast(true, 'xsd:unsignedInt'));
        $this->assertSame(0, Tr069Value::cast(false, 'xsd:unsignedInt'));
        $this->assertSame(300, Tr069Value::cast('300', 'xsd:unsignedInt'));
        $this->assertTrue(Tr069Value::cast('1', 'xsd:boolean'));
        $this->assertFalse(Tr069Value::cast(0, 'xsd:boolean'));
        $this->assertSame('1', Tr069Value::cast(true, 'xsd:string'));
        $this->assertSame('IP_Bridged', Tr069Value::cast('IP_Bridged', 'xsd:string'));
    }
}
