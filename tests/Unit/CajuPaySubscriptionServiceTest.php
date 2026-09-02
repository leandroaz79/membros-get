<?php

namespace Tests\Unit;

use App\Models\SubscriptionPlan;
use App\Services\CajuPaySubscriptionService;
use App\Support\FakeConsumerData;
use PHPUnit\Framework\TestCase;

class CajuPaySubscriptionServiceTest extends TestCase
{
    public function test_supports_known_intervals(): void
    {
        $this->assertTrue(CajuPaySubscriptionService::supportsPlanInterval(SubscriptionPlan::INTERVAL_WEEKLY));
        $this->assertTrue(CajuPaySubscriptionService::supportsPlanInterval(SubscriptionPlan::INTERVAL_MONTHLY));
        $this->assertTrue(CajuPaySubscriptionService::supportsPlanInterval(SubscriptionPlan::INTERVAL_QUARTERLY));
        $this->assertTrue(CajuPaySubscriptionService::supportsPlanInterval(SubscriptionPlan::INTERVAL_SEMI_ANNUAL));
        $this->assertTrue(CajuPaySubscriptionService::supportsPlanInterval(SubscriptionPlan::INTERVAL_ANNUAL));
        $this->assertFalse(CajuPaySubscriptionService::supportsPlanInterval(SubscriptionPlan::INTERVAL_LIFETIME));
        $this->assertFalse(CajuPaySubscriptionService::supportsPlanInterval('bimonthly'));
    }

    public function test_map_frequency(): void
    {
        $this->assertSame('WEEKLY', CajuPaySubscriptionService::mapFrequency('weekly'));
        $this->assertSame('MONTHLY', CajuPaySubscriptionService::mapFrequency('monthly'));
        $this->assertSame('QUARTERLY', CajuPaySubscriptionService::mapFrequency('quarterly'));
        $this->assertSame('SEMIANNUALLY', CajuPaySubscriptionService::mapFrequency('semi_annual'));
        $this->assertSame('ANNUALLY', CajuPaySubscriptionService::mapFrequency('annual'));
    }

    public function test_resolve_brazil_address_uses_defaults_when_empty(): void
    {
        $defaults = FakeConsumerData::defaultBrazilAddress();
        $resolved = CajuPaySubscriptionService::resolveBrazilAddress([]);

        $this->assertSame($defaults, $resolved);
    }

    public function test_resolve_brazil_address_keeps_provided_values(): void
    {
        $resolved = CajuPaySubscriptionService::resolveBrazilAddress([
            'zipcode' => '30130-110',
            'street' => 'Rua da Bahia',
            'number' => '500',
            'neighborhood' => 'Centro',
            'city' => 'Belo Horizonte',
            'state' => 'mg',
        ]);

        $this->assertSame('30130110', $resolved['zipcode']);
        $this->assertSame('Rua da Bahia', $resolved['street']);
        $this->assertSame('500', $resolved['number']);
        $this->assertSame('Centro', $resolved['neighborhood']);
        $this->assertSame('Belo Horizonte', $resolved['city']);
        $this->assertSame('MG', $resolved['state']);
    }

    public function test_is_charge_paid_status(): void
    {
        $service = new CajuPaySubscriptionService;
        $this->assertTrue($service->isChargePaidStatus('paid'));
        $this->assertTrue($service->isChargePaidStatus('PAID'));
        $this->assertFalse($service->isChargePaidStatus('pending'));
    }
}
