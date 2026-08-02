<?php

namespace App\Services\LandingPage;

/**
 * Opinionated landing page templates. Creators configure content,
 * not layout — every template ships the full conversion funnel:
 * hero → proof → email capture → £1 VIP upgrade.
 */
class TemplateCatalog
{
    public const DEFAULT = 'classic';

    /** @return array<string, array{name: string, sections: list<array{type: string, content: array}>}> */
    public function all(): array
    {
        return [
            'classic' => [
                'name' => 'Classic',
                'sections' => $this->classicSections(),
            ],
        ];
    }

    /** @return list<array{type: string, content: array}> */
    public function sectionsFor(string $template): array
    {
        return $this->all()[$template]['sections'] ?? $this->classicSections();
    }

    private function classicSections(): array
    {
        return [
            ['type' => 'hero', 'content' => [
                'headline' => '',
                'subheadline' => '',
                'image' => '',
                'cta_label' => 'Get notified at launch',
            ]],
            ['type' => 'video', 'content' => ['url' => '']],
            ['type' => 'feature_grid', 'content' => ['features' => []]],
            ['type' => 'testimonials', 'content' => ['items' => []]],
            ['type' => 'faq', 'content' => ['items' => []]],
            ['type' => 'email_capture', 'content' => [
                'headline' => 'Be first in line',
                'body' => 'Join the launch list for early-bird pricing.',
                'button_label' => 'Notify me',
            ]],
            ['type' => 'vip_upgrade', 'content' => [
                'headline' => 'Become a VIP for £1',
                'body' => 'Lock in the best early-bird reward before anyone else.',
                'price' => 100,
                'button_label' => 'Upgrade for £1',
            ]],
            ['type' => 'cta', 'content' => [
                'headline' => 'Launching soon on Kickstarter',
                'button_label' => 'Get notified',
            ]],
            ['type' => 'footer', 'content' => ['links' => []]],
        ];
    }
}
