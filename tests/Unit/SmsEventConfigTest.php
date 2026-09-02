<?php

namespace Tests\Unit;

use App\Support\SmsEventConfig;
use PHPUnit\Framework\TestCase;

class SmsEventConfigTest extends TestCase
{
    public function test_is_enabled_accepts_boolean_and_string_values(): void
    {
        $this->assertTrue(SmsEventConfig::isEnabled(true));
        $this->assertFalse(SmsEventConfig::isEnabled(false));
        $this->assertTrue(SmsEventConfig::isEnabled(1));
        $this->assertFalse(SmsEventConfig::isEnabled(0));
        $this->assertTrue(SmsEventConfig::isEnabled('1'));
        $this->assertTrue(SmsEventConfig::isEnabled('true'));
        $this->assertFalse(SmsEventConfig::isEnabled('0'));
        $this->assertFalse(SmsEventConfig::isEnabled(null));
    }
}
