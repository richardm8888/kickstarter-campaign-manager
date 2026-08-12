<?php

namespace App\Daily;

use App\Models\Project;

/**
 * Looks at one part of the funnel and reports what, if anything, is worth
 * doing about it.
 *
 * A detector that finds nothing wrong is expected to say what it checked
 * and found healthy. That reassurance is half the value of the list: the
 * point is not only to say what to do today, but to let a creator stop
 * worrying about everything else.
 */
interface Detector
{
    /** @return list<Signal> */
    public function detect(Project $project): array;

    /**
     * Areas checked and found fine, phrased for a reader.
     *
     * @return list<string>
     */
    public function reassurances(Project $project): array;
}
