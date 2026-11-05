<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Launch\ContentWriter;
use App\Launch\HandoffPrompt;
use App\Launch\Playbook;
use App\Launch\TaskImport;
use App\Models\LaunchTask;
use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LaunchTaskController extends Controller
{
    use AuthorizesRequests;

    public function index(Project $project, ContentWriter $writer): JsonResponse
    {
        $this->authorize('view', $project);

        $tasks = $project->launchTasks()
            ->with('latestDraft')
            ->withCount('drafts')
            ->get()
            // The project is already loaded; giving each task it saves a
            // query per row when the due date is worked out.
            ->each(fn (LaunchTask $task) => $task->setRelation('project', $project))
            ->sortBy([
                fn (LaunchTask $a, LaunchTask $b) => array_search($a->phase, array_keys(Playbook::PHASES)) <=> array_search($b->phase, array_keys(Playbook::PHASES)),
                fn (LaunchTask $a, LaunchTask $b) => [$a->offset_days ?? PHP_INT_MAX, $a->sort_order, $a->id] <=> [$b->offset_days ?? PHP_INT_MAX, $b->sort_order, $b->id],
            ])
            ->values();

        return response()->json([
            'tasks' => $tasks,
            'phases' => Playbook::PHASES,
            'categories' => Playbook::CATEGORIES,
            'content_types' => collect(ContentWriter::TYPES)->map(fn ($type) => $type['label']),
            'team' => $project->team ?? [],
            'content_brief' => $project->content_brief,
            'launch_date' => $project->launch_date?->toDateString(),
            'launch_date_confirmed' => (bool) $project->launch_date_confirmed,
            'website_repo' => $project->website_repo,
            'assets_repo' => $project->assets_repo,
            'ai_available' => $writer->available(),
        ]);
    }

    /**
     * Adds the playbook steps this project does not have yet.
     *
     * Safe to run again: steps already loaded, done or deleted-and-re-added
     * are matched by their key, so nothing is duplicated and nothing the
     * creator changed is overwritten.
     */
    public function loadPlaybook(Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $existing = $project->launchTasks()->whereNotNull('template_key')->pluck('template_key')->all();
        $added = 0;

        foreach (Playbook::steps() as $order => $step) {
            if (in_array($step['key'], $existing, true)) {
                continue;
            }

            $project->launchTasks()->create([
                'template_key' => $step['key'],
                'phase' => $step['phase'],
                'category' => $step['category'],
                'title' => $step['title'],
                'details' => $step['details'],
                'offset_days' => $step['offset_days'],
                'content_type' => $step['content_type'],
                'sort_order' => $order,
            ]);

            $added++;
        }

        return response()->json(['added' => $added]);
    }

    /**
     * Adds tasks from a pasted list and ticks off "done:" lines.
     *
     * With preview set, nothing is saved: the page shows what would
     * happen first. A list with any error saves nothing either, so a typo
     * on line 12 never leaves the first 11 imported and the rest missing.
     */
    public function import(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:50000'],
            'preview' => ['sometimes', 'boolean'],
        ]);

        $import = TaskImport::parse($project, $validated['text']);

        $summary = [
            'tasks' => array_values($import->tasks),
            'done' => collect($import->done)->map(fn (array $row) => ['line' => $row['line'], 'id' => $row['task']->id, 'title' => $row['task']->title])->values(),
            'skipped' => $import->skipped,
            'errors' => $import->errors,
        ];

        if (($validated['preview'] ?? false) || $import->errors !== []) {
            // Errors are an answer about the list, not a failed request: the
            // page shows them line by line, so they come back as a normal reply.
            return response()->json([...$summary, 'saved' => false]);
        }

        DB::transaction(function () use ($project, $import) {
            $order = (int) $project->launchTasks()->max('sort_order');

            foreach ($import->tasks as $task) {
                $project->launchTasks()->create([
                    ...collect($task)->except('line')->all(),
                    'sort_order' => ++$order,
                ]);
            }

            foreach ($import->done as $row) {
                if ($row['task']->status !== LaunchTask::DONE) {
                    $row['task']->update(['status' => LaunchTask::DONE, 'completed_at' => now()]);
                }
            }
        });

        return response()->json([...$summary, 'saved' => true]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $task = $project->launchTasks()->create($request->validate($this->rules(required: true)));

        return response()->json(['task' => $task->setRelation('project', $project)], 201);
    }

    public function update(Request $request, Project $project, LaunchTask $task): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($task->project_id === $project->id, 404);

        $validated = $request->validate([
            ...$this->rules(required: false),
            'status' => ['sometimes', Rule::in([LaunchTask::TODO, LaunchTask::DONE, LaunchTask::SKIPPED])],
        ]);

        if (array_key_exists('status', $validated)) {
            $validated['completed_at'] = $validated['status'] === LaunchTask::DONE ? now() : null;
        }

        $task->update($validated);

        return response()->json(['task' => $task->setRelation('project', $project)->load('latestDraft')->loadCount('drafts')]);
    }

    public function destroy(Project $project, LaunchTask $task): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($task->project_id === $project->id, 404);

        $task->delete();

        return response()->json(['message' => 'Task deleted.']);
    }

    /** Who is on the team, what the AI should know, and whether the date can be announced. */
    public function settings(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'team' => ['sometimes', 'array', 'max:10'],
            // Nullable because a blank name arrives as null (empty strings are
            // converted before validation); blanks are dropped below.
            'team.*' => ['nullable', 'string', 'max:60', 'distinct'],
            'content_brief' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'launch_date_confirmed' => ['sometimes', 'boolean'],
            // owner/name, or a full GitHub URL: whatever the agent will be told to open.
            'website_repo' => ['sometimes', 'nullable', 'string', 'max:255'],
            'assets_repo' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if (array_key_exists('team', $validated)) {
            $validated['team'] = array_values(array_filter(array_map(fn ($name) => trim((string) $name), $validated['team']), 'filled'));
        }

        $project->update($validated);

        return response()->json(['project' => $project]);
    }

    /** A prompt to hand this task to a coding agent such as Claude Code. */
    public function handoff(Project $project, LaunchTask $task): JsonResponse
    {
        $this->authorize('view', $project);
        abort_unless($task->project_id === $project->id, 404);

        $task->setRelation('project', $project)->load('latestDraft');

        return response()->json(['prompt' => HandoffPrompt::for($task)]);
    }

    public function drafts(Project $project, LaunchTask $task): JsonResponse
    {
        $this->authorize('view', $project);
        abort_unless($task->project_id === $project->id, 404);

        return response()->json(['drafts' => $task->drafts()->latest('id')->take(20)->get()]);
    }

    public function generate(Request $request, Project $project, LaunchTask $task, ContentWriter $writer): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($task->project_id === $project->id, 404);

        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:4000']]);

        if ($task->content_type === null) {
            return response()->json(['message' => 'This task has nothing to write.'], 422);
        }

        if (! $writer->available()) {
            return response()->json([
                'message' => 'AI writing is not set up on this server. Add ANTHROPIC_API_KEY to the backend environment to turn it on.',
            ], 503);
        }

        $content = $writer->write($task->setRelation('project', $project), $validated['notes'] ?? null);

        if ($content === null) {
            return response()->json(['message' => 'The AI did not return a draft this time. Try again in a moment.'], 502);
        }

        $draft = $task->drafts()->create([
            'content' => $content,
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json(['draft' => $draft], 201);
    }

    /** @return array<string, list<mixed>> */
    private function rules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'title' => [$presence, 'string', 'max:255'],
            'phase' => [$presence, Rule::in(array_keys(Playbook::PHASES))],
            'category' => [$presence, Rule::in(array_keys(Playbook::CATEGORIES))],
            'details' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'assignee' => ['sometimes', 'nullable', 'string', 'max:60'],
            'offset_days' => ['sometimes', 'nullable', 'integer', 'between:-365,365'],
            'due_on' => ['sometimes', 'nullable', 'date'],
            'content_type' => ['sometimes', 'nullable', Rule::in(array_keys(ContentWriter::TYPES))],
        ];
    }
}
