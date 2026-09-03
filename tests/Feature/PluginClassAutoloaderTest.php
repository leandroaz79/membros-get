<?php

namespace Tests\Feature;

use App\Plugins\PluginClassAutoloader;
use Tests\TestCase;

class PluginClassAutoloaderTest extends TestCase
{
    public function test_slug_to_pascal_case(): void
    {
        $this->assertSame('GetfyLojaFisica', PluginClassAutoloader::slugToPascalCase('getfy-loja-fisica'));
        $this->assertSame('MyPlugin', PluginClassAutoloader::slugToPascalCase('my-plugin'));
        $this->assertSame('AutoloadTest', PluginClassAutoloader::slugToPascalCase('autoload-test'));
    }

    public function test_loads_class_from_registered_mapping(): void
    {
        $fixture = base_path('tests/fixtures/autoload-plugin/src');
        if (! is_dir($fixture)) {
            $this->markTestSkipped('Fixture autoload-plugin ausente.');
        }

        PluginClassAutoloader::registerMapping('Plugins\\AutoloadTest\\', $fixture);

        $class = 'Plugins\\AutoloadTest\\HelloService';
        $this->assertFalse(class_exists($class, false));
        $this->assertTrue(PluginClassAutoloader::loadClass($class));
        $this->assertTrue(class_exists($class, false));
        $this->assertSame('hello-plugin', $class::greet());
    }
}
