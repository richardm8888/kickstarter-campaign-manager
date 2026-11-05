<?php

namespace App\AI;

/**
 * Reads a JSON array of objects out of a model's reply.
 *
 * Models wrap JSON in prose or fences however firmly they are asked not
 * to, and a reply cut off by the token limit loses its closing bracket.
 * Both still carry usable objects, so this pulls out what it can rather
 * than discarding the whole reply.
 */
final class JsonList
{
    /** @return list<array<string, mixed>> */
    public static function parse(string $raw): array
    {
        $start = strpos($raw, '[');

        if ($start === false) {
            return [];
        }

        $body = substr($raw, $start);
        $end = strrpos($body, ']');

        $decoded = $end === false ? null : json_decode(substr($body, 0, $end + 1), true);

        if (! is_array($decoded)) {
            $decoded = self::salvage($body);
        }

        return array_values(array_filter($decoded, is_array(...)));
    }

    /**
     * Pulls whole objects out of a partial array by walking braces, so a
     * reply that stops mid-item still yields the complete ones ahead of it.
     *
     * @return list<array<string, mixed>>
     */
    private static function salvage(string $body): array
    {
        $objects = [];
        $depth = 0;
        $startedAt = null;
        $inString = false;
        $escaped = false;

        foreach (str_split($body) as $i => $char) {
            if ($escaped) {
                $escaped = false;

                continue;
            }

            if ($inString) {
                match ($char) {
                    '\\' => $escaped = true,
                    '"' => $inString = false,
                    default => null,
                };

                continue;
            }

            match ($char) {
                '"' => $inString = true,
                '{' => $depth++ === 0 ? $startedAt = $i : null,
                '}' => --$depth === 0 && $startedAt !== null
                    ? $objects[] = substr($body, $startedAt, $i - $startedAt + 1)
                    : null,
                default => null,
            };
        }

        return array_values(array_filter(array_map(
            fn (string $json) => json_decode($json, true),
            $objects,
        ), is_array(...)));
    }
}
