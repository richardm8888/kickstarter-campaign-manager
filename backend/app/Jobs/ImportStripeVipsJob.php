<?php

namespace App\Jobs;

use App\Actions\ImportStripeVips;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportStripeVipsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly Project $project) {}

    public function handle(ImportStripeVips $import): void
    {
        $import->handle($this->project);
    }
}
