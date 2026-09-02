<?php

namespace Tests\Feature;

use Tests\TestCase;

class PluginStarterValidateTest extends TestCase
{
    public function test_official_starters_validate(): void
    {
        $slugs = [
            'getfy-plugin-starter',
            'getfy-example-gateway',
            'getfy-example-commerce',
            'getfy-example-integration',
            'getfy-example-member',
        ];

        foreach ($slugs as $slug) {
            if (! is_dir(base_path('plugins/'.$slug))) {
                $this->markTestSkipped("Plugin {$slug} ausente.");
            }
            $this->artisan('plugin:validate', ['slug' => $slug])->assertSuccessful();
        }
    }
}
