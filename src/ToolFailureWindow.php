<?php

declare(strict_types=1);

namespace Milpa\AiGateway;

/**
 * A partial view of a structured tool error, separate from its original durable receipt.
 *
 * Only oversized JSON objects are projected. Large object string fields may be omitted;
 * arrays and scalar facts are never rewritten. If that structure still cannot fit, the
 * whole preview is unavailable. Neither form certifies the tool's claims or grants authority.
 *
 * @internal
 * Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency
 */
final class ToolFailureWindow
{
    /**
     * Fit a failure projection within the caller's existing character budget, including metadata.
     *
     * Return null for the unchanged legacy path: short errors, unsupported JSON, or a budget
     * smaller than the minimal envelope. This method never writes or replaces the original error.
     */
    public static function project(string $error, int $budget): ?string
    {
        if (mb_strlen('Error executing tool: ' . $error) <= $budget) {
            return null;
        }
        try {
            $preview = json_decode($error, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!$preview instanceof \stdClass) {
            return null;
        }
        $identity = ['characters' => mb_strlen($error), 'sha256' => hash('sha256', $error)];
        $envelope = ['schema' => 'milpa.tool-failure-window/v1', 'ok' => false, 'partial' => false,
            'preview' => $preview, 'omitted' => [], 'original' => $identity];
        $originalNumbers = self::numbers($error);
        $previewNumbers = self::numbers(self::encode((array) $preview));
        if ($originalNumbers !== $previewNumbers) {
            // PHP's numeric range/precision must not silently change a tool's scalar facts.
            $envelope['partial'] = true;
            $envelope['preview'] = null;
            $envelope['omitted'] = [['path' => ''] + $identity];
            $encoded = self::encode($envelope);
            return mb_strlen($encoded) <= $budget ? $encoded : null;
        }
        $encoded = self::encode($envelope);
        if (mb_strlen($encoded) <= $budget) {
            return $encoded;
        }
        $envelope['partial'] = true;
        $strings = self::strings($preview);
        usort($strings, static fn (array $a, array $b): int => $b['characters'] <=> $a['characters']);
        foreach ($strings as $field) {
            $node = $preview;
            $path = $field['keys'];
            $last = array_pop($path);
            foreach ($path as $key) {
                $node = $node->{$key};
            }
            unset($node->{$last});
            $pointer = '/' . implode('/', array_map(static fn (string $key): string => str_replace(['~', '/'], ['~0', '~1'], $key), $field['keys']));
            $envelope['omitted'][] = ['path' => $pointer, 'characters' => $field['characters'], 'sha256' => $field['sha256']];
            $encoded = self::encode($envelope);
            if (mb_strlen($encoded) <= $budget) {
                return $encoded;
            }
        }
        // A large array, many small fields, or omission metadata itself may exceed the budget.
        // Do not turn unavailable data into an empty object, zero count, or apparent success.
        $envelope['preview'] = null;
        $envelope['omitted'] = [['path' => ''] + $identity];
        $encoded = self::encode($envelope);
        return mb_strlen($encoded) <= $budget ? $encoded : null;
    }

    /**
     * Collect only long object string fields; keep arrays and all non-string scalar values intact.
     *
     * @param list<string> $path
     *
     * @return list<array{keys: list<string>, characters: int, sha256: string}>
     */
    private static function strings(\stdClass $object, array $path = []): array
    {
        $fields = [];
        foreach (get_object_vars($object) as $name => $value) {
            $keys = [...$path, (string) $name];
            if ($value instanceof \stdClass) {
                array_push($fields, ...self::strings($value, $keys));
            } elseif (is_string($value) && mb_strlen($value) > 256) {
                $fields[] = ['keys' => $keys, 'characters' => mb_strlen($value), 'sha256' => hash('sha256', $value)];
            }
        }
        return $fields;
    }

    /** @param array<string, mixed> $envelope Encode the final projected wire, including escapes. */
    private static function encode(array $envelope): string
    {
        return json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Compare number spellings in already validated JSON, skipping strings and their escapes.
     * @return list<string>
     */
    private static function numbers(string $json): array
    {
        $numbers = [];
        $length = strlen($json);
        $i = 0;
        while ($i < $length) {
            if ($json[$i] === '"') {
                ++$i;
                while ($i < $length) {
                    $i += strcspn($json, "\"\\", $i);
                    if ($json[$i] === "\\") {
                        $i += 2;
                    } else {
                        ++$i;
                        break;
                    }
                }
            } elseif (str_contains('-0123456789', $json[$i])) {
                $size = strspn($json, '0123456789+-.eE', $i);
                $numbers[] = substr($json, $i, $size);
                $i += $size;
            } else {
                ++$i;
            }
        }
        return $numbers;
    }
}
