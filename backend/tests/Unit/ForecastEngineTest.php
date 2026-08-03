<?php

namespace Tests\Unit;

use App\Forecasting\ForecastEngine;
use App\Forecasting\ForecastInput;
use App\Services\Analytics\MetricCatalog;
use App\Services\Analytics\MetricSeries;
use PHPUnit\Framework\TestCase;

/**
 * Critical forecasting tests: the engine must be deterministic and
 * its funnel arithmetic exact.
 */
class ForecastEngineTest extends TestCase
{
    private function engine(): ForecastEngine
    {
        return new ForecastEngine(new MetricSeries(new MetricCatalog));
    }

    private function input(array $overrides = []): ForecastInput
    {
        return new ForecastInput(...array_merge([
            'emailSubscribers' => 1000,
            'vipCount' => 100,
            'plannedAdSpend' => 1000_00,   // £1,000
            'cpc' => 1.0,
            'visitorToSubscriberRate' => 0.20,
            'subscriberToBackerRate' => 0.05,
            'vipToBackerRate' => 0.30,
            'averagePledge' => 50_00,      // £50
            'fundingGoal' => 10000_00,     // £10,000
            'dataCompleteness' => 1.0,
        ], $overrides));
    }

    public function test_forecast_arithmetic_is_exact(): void
    {
        $forecast = $this->engine()->forecast($this->input());

        // £1,000 at £1 CPC → 1,000 visitors; 20% convert → 200 new subscribers.
        $this->assertSame(1000, $forecast->projectedVisitors);
        $this->assertSame(1200, $forecast->projectedSubscribers);

        // VIP share is 10% of the list, applied to new subscribers too.
        $this->assertSame(120, $forecast->projectedVips);

        // 1,080 standard × 5% + 120 VIP × 30% = 54 + 36 = 90 backers.
        $this->assertSame(90, $forecast->expectedBackers);

        // 90 × £50 = £4,500 → 45% of the £10,000 goal.
        $this->assertSame(4500_00, $forecast->expectedFunding);
        $this->assertSame(0.45, $forecast->goalCoverage);
    }

    public function test_forecast_is_deterministic(): void
    {
        $a = $this->engine()->forecast($this->input());
        $b = $this->engine()->forecast($this->input());

        $this->assertSame($a->toArray(), $b->toArray());
    }

    public function test_zero_cpc_produces_no_projected_visitors(): void
    {
        $forecast = $this->engine()->forecast($this->input(['cpc' => 0.0]));

        $this->assertSame(0, $forecast->projectedVisitors);
        $this->assertSame(1000, $forecast->projectedSubscribers);
    }

    public function test_zero_goal_yields_zero_coverage_without_dividing_by_zero(): void
    {
        $forecast = $this->engine()->forecast($this->input(['fundingGoal' => 0]));

        $this->assertSame(0.0, $forecast->goalCoverage);
    }

    public function test_confidence_tracks_data_completeness(): void
    {
        $this->assertSame('high', $this->engine()->forecast($this->input(['dataCompleteness' => 1.0]))->confidence);
        $this->assertSame('medium', $this->engine()->forecast($this->input(['dataCompleteness' => 0.5]))->confidence);
        $this->assertSame('low', $this->engine()->forecast($this->input(['dataCompleteness' => 0.25]))->confidence);
    }

    public function test_a_bigger_list_never_forecasts_less_funding(): void
    {
        $small = $this->engine()->forecast($this->input(['emailSubscribers' => 500, 'vipCount' => 0]));
        $large = $this->engine()->forecast($this->input(['emailSubscribers' => 5000, 'vipCount' => 0]));

        $this->assertGreaterThan($small->expectedFunding, $large->expectedFunding);
    }

    public function test_vips_outperform_standard_subscribers(): void
    {
        $noVips = $this->engine()->forecast($this->input(['vipCount' => 0]));
        $withVips = $this->engine()->forecast($this->input(['vipCount' => 200]));

        $this->assertGreaterThan($noVips->expectedFunding, $withVips->expectedFunding);
    }
}
