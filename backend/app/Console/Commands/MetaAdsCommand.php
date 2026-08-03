<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Recommendations\AdPerformanceAnalyser;
use Illuminate\Console\Command;

/**
 * Shows exactly what is stored for each ad, so a blank Ads screen can be
 * traced to either nothing being fetched or nothing being displayed.
 */
class MetaAdsCommand extends Command
{
    protected $signature = 'meta:ads
        {--project= : Project id}
        {--days=30 : Window to report on}';

    protected $description = 'List stored per-ad performance, to diagnose missing ad data';

    public function handle(AdPerformanceAnalyser $analyser): int
    {
        $project = $this->option('project')
            ? Project::find($this->option('project'))
            : Project::first();

        if (! $project) {
            $this->error('No project found. Pass --project=<id>.');

            return self::FAILURE;
        }

        $days = (int) $this->option('days');
        $report = $analyser->analyse($project, $days);

        if ($report['ads'] === []) {
            $this->warn("No ad data stored for the last {$days} days.");
            $this->line('Run: php artisan integrations:sync --provider=meta');
            $this->line('If it stays empty, the account may have had no delivery in this window.');

            return self::SUCCESS;
        }

        $this->table(
            ['Ad', 'Objective', 'Spend', 'Clicks', 'Signups', 'Follows', 'Verdict'],
            array_map(fn (array $ad) => [
                mb_strimwidth($ad['ad_name'], 0, 32, '…'),
                $ad['objective'],
                number_format($ad['spend'], 2),
                $ad['clicks'],
                $ad['leads'],
                $ad['follows'],
                $ad['verdict'],
            ], $report['ads']),
        );

        $this->newLine();
        $this->line(sprintf(
            'Window: %d days · %d ads · %s total spend · %d signups',
            $report['days'],
            count($report['ads']),
            number_format($report['benchmark']['total_spend'], 2),
            $report['benchmark']['total_leads'],
        ));

        $earliest = $project->metricSnapshots()
            ->where('metric', 'ad_spend')
            ->orderBy('recorded_at')
            ->value('recorded_at');

        if ($earliest) {
            $this->line('Earliest stored ad day: '.$earliest->toDateString());
        }

        return self::SUCCESS;
    }
}
