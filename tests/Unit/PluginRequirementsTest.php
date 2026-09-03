<?php

namespace Tests\Unit;

use App\Support\PluginRequirements;
use Tests\TestCase;

class PluginRequirementsTest extends TestCase
{
    public function test_plugin_api_too_high_fails(): void
    {
        config(['plugins.plugin_api' => 2]);
        $errors = PluginRequirements::validate(['requires' => ['plugin_api' => 99]]);
        $this->assertNotEmpty($errors);
    }

    public function test_getfy_constraint_caret(): void
    {
        config(['getfy.version' => '2.0.4']);
        $this->assertTrue(PluginRequirements::satisfiesGetfy('2.0.4', '^2.0'));
        $this->assertFalse(PluginRequirements::satisfiesGetfy('1.9.0', '^2.0'));
        $this->assertTrue(PluginRequirements::satisfiesGetfy('2.0.4', '>=2.0.0'));
    }

    public function test_validate_passes_current(): void
    {
        config(['plugins.plugin_api' => 2, 'getfy.version' => '2.0.4']);
        $errors = PluginRequirements::validate([
            'requires' => ['getfy' => '>=2.0.0', 'plugin_api' => 2],
        ]);
        $this->assertSame([], $errors);
    }
}
