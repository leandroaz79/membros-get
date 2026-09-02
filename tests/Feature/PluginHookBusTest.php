<?php

namespace Tests\Feature;

use App\Plugins\PluginHookBus;
use Tests\TestCase;

class PluginHookBusTest extends TestCase
{
    protected function tearDown(): void
    {
        PluginHookBus::reset();
        parent::tearDown();
    }

    public function test_filter_chain_respects_priority(): void
    {
        PluginHookBus::addFilter('test.chain', fn (int $v) => $v + 10, 20);
        PluginHookBus::addFilter('test.chain', fn (int $v) => $v * 2, 10);

        $result = PluginHookBus::applyFilters('test.chain', 5);

        // Prioridade menor executa primeiro (10 → *2, depois 20 → +10).
        $this->assertSame(20, $result);
    }

    public function test_action_runs_callbacks(): void
    {
        $called = false;
        PluginHookBus::addAction('test.action', function () use (&$called) {
            $called = true;
        });
        PluginHookBus::doAction('test.action');

        $this->assertTrue($called);
    }

    public function test_inertia_shared_filter_integration(): void
    {
        PluginHookBus::addFilter('inertia.shared', function (array $shared) {
            $shared['hook_test_flag'] = true;

            return $shared;
        });

        $filtered = PluginHookBus::applyFilters('inertia.shared', ['csrf_token' => 'x']);

        $this->assertTrue($filtered['hook_test_flag']);
    }
}
