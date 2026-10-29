<?php

namespace App\Launch;

use App\Models\LaunchTask;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns a pasted list into launch tasks.
 *
 * One task per line, fields separated by "|":
 *
 *     Title | category | content to write | due | assignee | details
 *
 * Only the title is required. Due is days from launch (-84, +7, 0 or
 * "launch") or a fixed date (2026-11-27). A line "done: <playbook key or
 * exact title>" ticks off an existing task instead, and "#" starts a
 * comment. The format is forgiving about case and labels because it is
 * typed on a phone as often as it is pasted.
 *
 * Nothing is half-imported: the whole list is checked first, and any
 * error is reported against its line number before anything is saved.
 */
class TaskImport
{
    /** @var list<array{line: int, message: string}> */
    public array $errors = [];

    /** @var list<array<string, mixed>> tasks to create */
    public array $tasks = [];

    /** @var list<array{line: int, task: LaunchTask}> existing tasks to tick off */
    public array $done = [];

    /** @var list<array{line: int, title: string}> lines skipped because the task already exists */
    public array $skipped = [];

    public function __construct(private readonly Project $project) {}

    public static function parse(Project $project, string $text): self
    {
        $import = new self($project);
        $import->read($text);

        return $import;
    }

    private function read(string $text): void
    {
        $existing = $this->project->launchTasks()->get();
        $titles = $existing->mapWithKeys(fn (LaunchTask $task) => [Str::lower(trim($task->title)) => true]);

        foreach (preg_split('/\R/', $text) as $index => $raw) {
            $line = $index + 1;
            $raw = trim($raw);

            if ($raw === '' || str_starts_with($raw, '#')) {
                continue;
            }

            if (preg_match('/^done\s*:\s*(.+)$/i', $raw, $match)) {
                $this->markDone($existing, trim($match[1]), $line);

                continue;
            }

            $task = $this->task($raw, $line);

            if ($task === null) {
                continue;
            }

            // Pasting the same list twice should not double the plan.
            $key = Str::lower($task['title']);
            if ($titles->has($key)) {
                $this->skipped[] = ['line' => $line, 'title' => $task['title']];

                continue;
            }

            $titles->put($key, true);
            $this->tasks[] = $task;
        }
    }

    /** @param Collection<int, LaunchTask> $existing */
    private function markDone(Collection $existing, string $ref, int $line): void
    {
        $task = $existing->firstWhere('template_key', $ref)
            ?? $existing->first(fn (LaunchTask $task) => Str::lower(trim($task->title)) === Str::lower($ref));

        if ($task === null) {
            $this->errors[] = ['line' => $line, 'message' => "No task called \"{$ref}\" to mark as done. Load the playbook first, or check the spelling."];

            return;
        }

        $this->done[] = ['line' => $line, 'task' => $task];
    }

    /** @return array<string, mixed>|null */
    private function task(string $raw, int $line): ?array
    {
        $fields = array_map('trim', explode('|', $raw));
        [$title, $category, $type, $due, $assignee, $details] = array_pad($fields, 6, '');

        // Details may themselves contain "|"; keep everything after the fifth separator.
        if (count($fields) > 6) {
            $details = implode(' | ', array_slice($fields, 5));
        }

        if ($title === '') {
            $this->errors[] = ['line' => $line, 'message' => 'A task needs a title before the first "|".'];

            return null;
        }

        if (mb_strlen($title) > 255) {
            $this->errors[] = ['line' => $line, 'message' => 'The title is too long (255 characters at most).'];

            return null;
        }

        $categoryKey = $this->match($category, Playbook::CATEGORIES) ?? ($category === '' ? 'kickstarter' : null);
        if ($categoryKey === null) {
            $this->errors[] = ['line' => $line, 'message' => "Unknown category \"{$category}\". Use one of: ".implode(', ', Playbook::CATEGORIES).'.'];

            return null;
        }

        $typeKey = null;
        if (! in_array(Str::lower($type), ['', '-', 'none', 'no'], true)) {
            $typeKey = $this->match($type, collect(ContentWriter::TYPES)->map(fn ($t) => $t['label'])->all());
            if ($typeKey === null) {
                $this->errors[] = ['line' => $line, 'message' => "Unknown content type \"{$type}\". Use one of: ".collect(ContentWriter::TYPES)->pluck('label')->implode(', ').', or leave it blank.'];

                return null;
            }
        }

        [$offset, $dueOn, $dueError] = $this->due($due);
        if ($dueError !== null) {
            $this->errors[] = ['line' => $line, 'message' => $dueError];

            return null;
        }

        return [
            'line' => $line,
            'title' => $title,
            'category' => $categoryKey,
            'phase' => $this->phase($categoryKey, $offset, $dueOn),
            'content_type' => $typeKey,
            'offset_days' => $offset,
            'due_on' => $dueOn,
            'assignee' => $assignee !== '' ? Str::limit($assignee, 60, '') : null,
            'details' => $details !== '' ? $details : null,
        ];
    }

    /**
     * Matches a key or a label, ignoring case: "outreach", "Outreach" and
     * "BoardGameGeek" or "bgg" all work.
     *
     * @param  array<string, string>  $options
     */
    private function match(string $value, array $options): ?string
    {
        $needle = Str::lower(trim($value));

        if ($needle === '') {
            return null;
        }

        foreach ($options as $key => $label) {
            if ($needle === Str::lower($key) || $needle === Str::lower($label)) {
                return $key;
            }
        }

        return null;
    }

    /** @return array{0: ?int, 1: ?string, 2: ?string} offset, fixed date, error */
    private function due(string $due): array
    {
        $due = Str::lower(trim($due));

        if ($due === '') {
            return [null, null, null];
        }

        if ($due === 'launch' || $due === 'launch day') {
            return [0, null, null];
        }

        if (preg_match('/^[+-]?\d{1,3}$/', $due)) {
            $days = (int) $due;

            return abs($days) > 365
                ? [null, null, 'Days from launch must be within a year either side.']
                : [$days, null, null];
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
            try {
                return [null, Carbon::createFromFormat('!Y-m-d', $due)->toDateString(), null];
            } catch (\Throwable) {
                // Falls through to the error below.
            }
        }

        return [null, null, "Couldn't read the due date \"{$due}\". Use days from launch (-84, +7, launch) or a date (2026-11-27)."];
    }

    /**
     * Puts an imported task in the phase it belongs to, so it sits with the
     * playbook steps around it rather than in a pile at the end.
     */
    private function phase(string $category, ?int $offset, ?string $dueOn): string
    {
        if (in_array($category, ['outreach', 'previews'], true)) {
            return 'outreach';
        }

        if ($offset === null && $dueOn !== null && $this->project->launch_date !== null) {
            $offset = (int) Carbon::parse($this->project->launch_date)->diffInDays(Carbon::parse($dueOn), false);
        }

        return match (true) {
            $offset === null => 'audience',
            $offset > 0 => 'campaign',
            $offset === 0 => 'launch',
            $offset >= -21 => 'countdown',
            default => 'audience',
        };
    }
}
