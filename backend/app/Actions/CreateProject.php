<?php

namespace App\Actions;

use App\Models\Project;
use App\Models\User;
use App\Services\LandingPage\TemplateCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creating a project also provisions its landing page with the default
 * template so a creator can start configuring immediately — minimal setup.
 */
class CreateProject
{
    public function __construct(private readonly TemplateCatalog $templates) {}

    public function handle(User $user, array $attributes): Project
    {
        return DB::transaction(function () use ($user, $attributes) {
            $slug = $this->uniqueSlug($attributes['name']);

            $project = $user->projects()->create([...$attributes, 'slug' => $slug]);

            $page = $project->landingPage()->create([
                'template' => TemplateCatalog::DEFAULT,
                'slug' => $slug,
            ]);

            foreach ($this->templates->sectionsFor(TemplateCatalog::DEFAULT) as $position => $section) {
                $page->sections()->create([...$section, 'position' => $position]);
            }

            return $project;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'project';
        $slug = $base;

        for ($i = 2; Project::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
