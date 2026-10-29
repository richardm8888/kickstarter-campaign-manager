<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Seo\SeoAdvisor;
use App\Services\Seo\SeoAnalyser;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class SeoController extends Controller
{
    use AuthorizesRequests;

    public function show(Request $request, Project $project, SeoAnalyser $analyser, SeoAdvisor $advisor): JsonResponse
    {
        $this->authorize('view', $project);

        $validated = $request->validate([
            'days' => ['sometimes', 'integer', Rule::in(SeoAnalyser::WINDOWS)],
            'refresh' => ['sometimes', 'boolean'],
        ]);

        return $this->withAnalysis($project, $analyser, $validated, fn (?array $analysis) => response()->json([
            'connected' => $analysis !== null,
            'ai_available' => $advisor->available(),
            'analysis' => $analysis,
        ]));
    }

    public function recommendations(Request $request, Project $project, SeoAnalyser $analyser, SeoAdvisor $advisor): JsonResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate(['days' => ['sometimes', 'integer', Rule::in(SeoAnalyser::WINDOWS)]]);

        if (! $advisor->available()) {
            return response()->json([
                'message' => 'AI recommendations are not set up on this server. Add ANTHROPIC_API_KEY to the backend environment to turn them on.',
            ], 503);
        }

        return $this->withAnalysis($project, $analyser, $validated, function (?array $analysis) use ($project, $advisor) {
            if ($analysis === null) {
                return response()->json(['message' => 'Connect Google Search Console first.'], 422);
            }

            $recommendations = $advisor->recommend($project, $analysis);

            return $recommendations === null
                ? response()->json(['message' => 'The AI did not return usable recommendations this time. Try again in a moment.'], 502)
                : response()->json(['recommendations' => $recommendations]);
        });
    }

    /**
     * Runs the analysis, turning Google's refusals into something a
     * creator can act on. The usual one is a key that has not been added
     * to the Search Console property, which Google reports as 403.
     *
     * @param  array<string, mixed>  $options
     * @param  callable(?array): JsonResponse  $then
     */
    private function withAnalysis(Project $project, SeoAnalyser $analyser, array $options, callable $then): JsonResponse
    {
        try {
            $analysis = $analyser->analyse($project, (int) ($options['days'] ?? 90), (bool) ($options['refresh'] ?? false));
        } catch (RequestException $e) {
            $status = $e->response->status();

            return response()->json([
                'message' => in_array($status, [401, 403], true)
                    ? 'Google refused access to this Search Console property. In Search Console → Settings → Users and permissions, add the service account\'s client_email, and check the property name matches exactly (for example sc-domain:yoursite.com).'
                    : "Search Console returned an error (HTTP {$status}). Try again shortly.",
            ], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Could not read Search Console: '.$e->getMessage()], 502);
        }

        return $then($analysis);
    }
}
