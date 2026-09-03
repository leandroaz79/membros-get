<?php

namespace Tests\Unit;

use App\PluginSdk\Getfy;
use Tests\TestCase;

class PluginSdkFacadeTest extends TestCase
{
    protected function tearDown(): void
    {
        Getfy::resetForTests();
        parent::tearDown();
    }

    public function test_facade_exposes_new_services(): void
    {
        $this->assertSame(2, Getfy::pluginApiVersion());
        $this->assertNotEmpty(Getfy::version());
        $this->assertSame(Getfy::gateways(), Getfy::gateways());
        $this->assertSame(Getfy::extensions(), Getfy::extensions());
        $this->assertSame(Getfy::productTypes(), Getfy::productTypes());
        $this->assertSame(Getfy::capabilities(), Getfy::capabilities());
        $this->assertSame(Getfy::events(), Getfy::events());
        $this->assertNotNull(Getfy::license());
    }
}
