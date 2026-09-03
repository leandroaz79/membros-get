<?php

namespace App\Support;

class WebhookEventCatalog
{
    /**
     * Catálogo para UI (sidebar, modal de payload).
     *
     * @return array{groups: list<array<string, mixed>>, events: list<array<string, mixed>>}
     */
    public static function forUi(): array
    {
        $eventClasses = config('webhook_events.events', []);
        $slugsByClass = config('webhook_events.event_slugs', []);
        $descriptions = config('webhook_events.descriptions', []);
        $groupsConfig = config('webhook_events.groups', []);

        $events = [];
        foreach ($slugsByClass as $class => $slug) {
            $events[] = [
                'slug' => $slug,
                'label' => $eventClasses[$class] ?? $slug,
                'description' => $descriptions[$slug] ?? null,
                'class' => $class,
            ];
        }

        usort($events, fn ($a, $b) => strcmp($a['label'], $b['label']));

        $groups = [];
        foreach ($groupsConfig as $key => $group) {
            $groupSlugs = $group['slugs'] ?? [];
            $groupEvents = array_values(array_filter(
                $events,
                fn ($e) => in_array($e['slug'], $groupSlugs, true),
            ));

            $groups[] = [
                'key' => $key,
                'label' => $group['label'] ?? $key,
                'slugs' => $groupSlugs,
                'events' => $groupEvents,
            ];
        }

        return [
            'groups' => $groups,
            'events' => $events,
        ];
    }

    public static function isAllowedSlug(string $slug): bool
    {
        $allowed = array_values(config('webhook_events.event_slugs', []));

        return in_array($slug, $allowed, true);
    }

    public static function labelForSlug(string $slug): string
    {
        $slugsByClass = config('webhook_events.event_slugs', []);
        $events = config('webhook_events.events', []);

        foreach ($slugsByClass as $class => $eventSlug) {
            if ($eventSlug === $slug) {
                return $events[$class] ?? $slug;
            }
        }

        return $slug;
    }
}
