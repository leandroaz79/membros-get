<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Support\ReportingPeriod;

class InvalidateDashboardCacheOnOrderCompleted
{
    public function handle(OrderCompleted $event): void
    {
        ReportingPeriod::bustDashboardCache($event->order->tenant_id);
    }
}
