<?php

namespace App\Services\Analytics;

/**
 * How each metric aggregates over time.
 *
 * The store is append-only, so the same day can be observed many times —
 * every hourly sync re-reports it. Reads must therefore know whether
 * repeated observations restate a value or add to it:
 *
 *   DAILY_TOTAL — provider's total for that day (sessions, spend). The
 *                 latest observation wins per day; days sum across a window.
 *   LEVEL       — a running level (list size, CPC). Latest wins per day and
 *                 across a window; summing would be meaningless.
 *   DELTA       — one event, recorded once (a signup). Observations add up.
 */
class MetricCatalog
{
    public const DAILY_TOTAL = 'daily_total';
    public const LEVEL = 'level';
    public const DELTA = 'delta';

    private const AGGREGATIONS = [
        'sessions' => self::DAILY_TOTAL,
        'users' => self::DAILY_TOTAL,
        'pageviews' => self::DAILY_TOTAL,
        'spend' => self::DAILY_TOTAL,
        'impressions' => self::DAILY_TOTAL,
        'clicks' => self::DAILY_TOTAL,
        'revenue' => self::DAILY_TOTAL,
        'payments' => self::DAILY_TOTAL,
        'email_opens' => self::DAILY_TOTAL,
        'email_clicks' => self::DAILY_TOTAL,
        'email_unsubscribes' => self::DAILY_TOTAL,

        'cpc' => self::LEVEL,
        'cpm' => self::LEVEL,
        'ctr' => self::LEVEL,
        'email_subscribers' => self::LEVEL,
        'email_vip_subscribers' => self::LEVEL,
        'email_subscribers_in_group' => self::LEVEL,
        'email_form_contacts' => self::LEVEL,
        'email_other_contacts' => self::LEVEL,
        'email_form_open_rate' => self::LEVEL,
        'email_other_open_rate' => self::LEVEL,
        'email_form_click_rate' => self::LEVEL,
        'email_other_click_rate' => self::LEVEL,
        'ks_followers' => self::LEVEL,

        // GA4's own signup count, and the same traffic and signups cut by
        // referrer and region. The cuts carry their own names because a
        // day collapses to one figure per metric — dimensioned copies of
        // `sessions` would have segments overwriting each other.
        'site_leads' => self::DAILY_TOTAL,
        'sessions_by_source' => self::DAILY_TOTAL,
        'leads_by_source' => self::DAILY_TOTAL,
        'sessions_by_region' => self::DAILY_TOTAL,
        'leads_by_region' => self::DAILY_TOTAL,

        'signups' => self::DELTA,
        'vip_upgrades' => self::DELTA,

        // Per-ad series (Meta restates each day, so daily totals).
        'ad_spend' => self::DAILY_TOTAL,
        'ad_clicks' => self::DAILY_TOTAL,
        'ad_impressions' => self::DAILY_TOTAL,
        'ad_leads' => self::DAILY_TOTAL,
        'ad_follows' => self::DAILY_TOTAL,
        'ad_view_content' => self::DAILY_TOTAL,
        'ad_landing_page_views' => self::DAILY_TOTAL,
        'ad_form_views' => self::DAILY_TOTAL,
    ];

    public function aggregation(string $metric): string
    {
        return self::AGGREGATIONS[$metric] ?? self::DAILY_TOTAL;
    }

    public function isLevel(string $metric): bool
    {
        return $this->aggregation($metric) === self::LEVEL;
    }

    public function isDelta(string $metric): bool
    {
        return $this->aggregation($metric) === self::DELTA;
    }
}
