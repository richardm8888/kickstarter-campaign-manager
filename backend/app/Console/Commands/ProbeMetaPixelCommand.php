<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Models\Project;
use App\Services\Ads\MetaPixelProbe;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * The terminal face of MetaPixelProbe. The same probe runs from the Ads
 * page, because the answer is needed by whoever is holding a phone and
 * the question is not a developer's.
 */
class ProbeMetaPixelCommand extends Command
{
    protected $signature = 'meta:pixel-probe {--project= : Which project\'s credentials to use}';

    protected $description = 'Find out which pixel event data Meta will actually return';

    public function handle(MetaPixelProbe $probe): int
    {
        $project = Project::query()
            ->when($this->option('project'), fn ($q, $id) => $q->whereKey($id))
            ->whereHas('integrations', fn ($q) => $q
                ->where('provider', 'meta')
                ->where('status', Integration::STATUS_CONNECTED))
            ->first();

        if ($project === null) {
            $this->components->error('No project has Meta connected.');

            return self::FAILURE;
        }

        $this->components->info("Using {$project->name}");

        try {
            $result = $probe->run($project);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result['pixels'] === []) {
            $this->components->warn(
                'No pixels on this ad account. Follows are being recorded, so the pixel '
                .'may belong to a different account than the one advertising.',
            );

            return self::SUCCESS;
        }

        foreach ($result['pixels'] as $pixel) {
            $this->newLine();
            $this->components->twoColumnDetail(
                "<fg=cyan>{$pixel['name']}</>",
                $pixel['id'].' · last fired '.($pixel['last_fired_at'] ?? 'never'),
            );

            foreach ($pixel['checks'] as $check) {
                $this->components->twoColumnDetail(
                    "  {$check['label']}",
                    $check['ok']
                        ? "<fg=green>ok</> · {$check['rows']} rows"
                        : '<fg=red>error</>',
                );

                foreach ([$check['detail'], $check['sample']] as $line) {
                    if ($line !== null) {
                        $this->line('    '.$line);
                    }
                }
            }
        }

        return self::SUCCESS;
    }
}
