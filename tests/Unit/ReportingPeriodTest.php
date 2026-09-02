<?php

namespace Tests\Unit;

use App\Support\ReportingPeriod;
use Carbon\Carbon;
use Tests\TestCase;

class ReportingPeriodTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_bounds_for_dashboard_hoje_uses_app_timezone_midnight(): void
    {
        config(['app.timezone' => 'America/Sao_Paulo']);
        Carbon::setTestNow(Carbon::parse('2026-05-27 01:29:00', 'America/Sao_Paulo'));

        [$start, $end] = ReportingPeriod::boundsForDashboard('hoje');

        $this->assertSame('2026-05-27 00:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('America/Sao_Paulo', $start->timezone->getName());
        $this->assertSame('2026-05-27 23:59:59', $end->format('Y-m-d H:i:s'));
    }

    public function test_order_at_01_05_is_inside_hoje_bounds(): void
    {
        config(['app.timezone' => 'America/Sao_Paulo']);
        Carbon::setTestNow(Carbon::parse('2026-05-27 01:29:00', 'America/Sao_Paulo'));

        [$start, $end] = ReportingPeriod::boundsForDashboard('hoje');
        $orderAt = Carbon::parse('2026-05-27 01:05:00', 'America/Sao_Paulo');

        $this->assertTrue($orderAt->between($start, $end));
    }

    public function test_dashboard_cache_suffix_changes_per_calendar_day(): void
    {
        config(['app.timezone' => 'America/Sao_Paulo']);
        Carbon::setTestNow(Carbon::parse('2026-05-27 01:00:00', 'America/Sao_Paulo'));
        $this->assertSame('2026-05-27', ReportingPeriod::dashboardCacheSuffix('hoje'));

        Carbon::setTestNow(Carbon::parse('2026-05-26 23:50:00', 'America/Sao_Paulo'));
        $this->assertSame('2026-05-26', ReportingPeriod::dashboardCacheSuffix('hoje'));
    }
}
