<?php

namespace Tests\Unit;

use App\Support\SerialNumber;
use PHPUnit\Framework\TestCase;

class SerialNumberTest extends TestCase
{
    public function test_normalize_and_validate(): void
    {
        $this->assertSame('FHTT9D34CF80', SerialNumber::normalize(' fhtt 9d34cf80 '));
        $this->assertTrue(SerialNumber::isValid('ZTEGD4AAA90A'));
        $this->assertFalse(SerialNumber::isValid('ab'));
        $this->assertFalse(SerialNumber::isValid('FHTT"}}$where'));
    }

    public function test_huawei_text_and_hex_forms_are_equivalent(): void
    {
        $this->assertContains('485754439C31B89F', SerialNumber::variants('HWTC9C31B89F'));
        $this->assertContains('HWTC9C31B89F', SerialNumber::variants('485754439C31B89F'));
        $this->assertTrue(SerialNumber::equals('HWTC9C31B89F', '485754439C31B89F'));
        $this->assertFalse(SerialNumber::equals('HWTC9C31B89F', 'HWTC9C31B890'));
    }

    public function test_non_letter_hex_prefix_is_not_decoded(): void
    {
        // 0x00..: bukan huruf, jangan dianggap bentuk teks.
        $this->assertSame(['0000000012345678', '0000000012345678'], array_values(array_unique(SerialNumber::variants('0000000012345678'))) + [1 => '0000000012345678']);
    }
}
