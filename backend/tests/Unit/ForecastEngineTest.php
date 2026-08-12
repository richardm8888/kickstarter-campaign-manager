<?php

namespace Tests\Unit;

use App\Forecasting\AudienceMix;
use App\Forecasting\BackerRates;
use App\Forecasting\ForecastEngine;
use App\Forecasting\ForecastInput;
use App\Services\Analytics\AdTotals;
use App\Services\Analytics\SegmentTotals;
use App\Services\Analytics\AudienceSize;
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
        $series = new MetricSeries(new MetricCatalog);

        return new ForecastEngine($series, new AudienceSize($series), new AdTotals(new SegmentTotals));
    }

    private function input(array $overrides = []): ForecastInput
    {
        return new ForecastInput(...array_merge([
            // 900 plain subscribers, 200 followers, 100 paid VIPs.
            'audience' => new AudienceMix(standard: 900, followers: 200, vips: 100),
            'plannedAdSpend' => 1000_00,   // £1,000
            'cpc' => 1.0,
            'visitorToSubscriberRate' => 0.20,
            'backerRates' => BackerRates::planning(),
            'averagePledge' => 50_00,      // £50
            'fundingGoal' => 10000_00,     // £10,000
            'dataCompleteness' => 1.0,
        ], $overrides));
    }

    public function test_forecast_arithmetic_is_exact(): void
    {
        $forecast = $this->engine()->forecast($this->input());

        // £1,000 at £1 CPC → 1,000 visitors; 20% convert → 200 new signups.
        $this->assertSame(1000, $forecast->projectedVisitors);

        // With no measured ad mix, new signups are assumed to be the least
        // valuable kind: plain email leads.
        $this->assertSame(1200, $forecast->projectedSubscribers);
        $this->assertSame(200, $forecast->projectedFollowers);
        $this->assertSame(100, $forecast->projectedVips);

        // 1,100 standard × 2% + 200 followers × 20% + 100 VIPs × 30%
        // = 22 + 40 + 30 = 92 backers.
        $this->assertSame(92, $forecast->expectedBackers);
        $this->assertSame(
            [BackerRates::STANDARD => 22, BackerRates::FOLLOWERS => 40, BackerRates::VIPS => 30],
            $forecast->backersBySegment,
        );

        // 92 × £50 = £4,600 → 46% of the £10,000 goal.
        $this->assertSame(4600_00, $forecast->expectedFunding);
        $this->assertSame(0.46, $forecast->goalCoverage);
    }

    public function test_a_follower_is_worth_ten_email_subscribers(): void
    {
        $subscribers = $this->input([
            'audience' => new AudienceMix(standard: 1000, followers: 0, vips: 0),
            'plannedAdSpend' => 0,
        ]);
        $followers = $this->input([
            'audience' => new AudienceMix(standard: 0, followers: 100, vips: 0),
            'plannedAdSpend' => 0,
        ]);

        // 1,000 at 2% and 100 at 20% both come to 20 backers.
        $this->assertSame(
            $this->engine()->forecast($subscribers)->expectedBackers,
            $this->engine()->forecast($followers)->expectedBackers,
        );
    }

    public function test_spend_on_follower_ads_is_forecast_at_follower_rates(): void
    {
        $engine = $this->engine();
        $audience = new AudienceMix(standard: 0, followers: 0, vips: 0);

        $toEmail = $engine->forecast($this->input([
            'audience' => $audience,
            'adMix' => [BackerRates::STANDARD => 1.0, BackerRates::FOLLOWERS => 0.0],
        ]));
        $toKickstarter = $engine->forecast($this->input([
            'audience' => $audience,
            'adMix' => [BackerRates::STANDARD => 0.0, BackerRates::FOLLOWERS => 1.0],
        ]));

        // Same spend, same clicks, same conversion — ten times the backers,
        // purely because of where the ads sent people.
        $this->assertSame(4, $toEmail->expectedBackers);
        $this->assertSame(40, $toKickstarter->expectedBackers);
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
        $small = $this->engine()->forecast($this->input(['audience' => new AudienceMix(500, 0, 0)]));
        $large = $this->engine()->forecast($this->input(['audience' => new AudienceMix(5000, 0, 0)]));

        $this->assertGreaterThan($small->expectedFunding, $large->expectedFunding);
    }

    public function test_vips_outperform_standard_subscribers(): void
    {
        $noVips = $this->engine()->forecast($this->input(['audience' => new AudienceMix(1000, 0, 0)]));
        $withVips = $this->engine()->forecast($this->input(['audience' => new AudienceMix(800, 0, 200)]));

        $this->assertGreaterThan($noVips->expectedFunding, $withVips->expectedFunding);
    }

    public function test_scenarios_move_every_segment_together(): void
    {
        $cautious = BackerRates::scenario(BackerRates::CAUTIOUS);
        $optimistic = BackerRates::scenario(BackerRates::OPTIMISTIC);

        foreach (BackerRates::segments() as $segment) {
            $this->assertLessThan(
                $optimistic[$segment],
                $cautious[$segment],
                "{$segment} must improve from cautious to optimistic",
            );
        }
    }
}
