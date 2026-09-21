<?php

namespace App\Services\Releases;

use App\Models\Release;
use InvalidArgumentException;

/**
 * Validates changelog/releases/*.json entry files (CI + deploy sync).
 */
class ChangelogEntryValidator
{
    /**
     * @return list<string>
     */
    public function validateFile(string $path): array
    {
        if (! is_file($path)) {
            return ["File not found: {$path}"];
        }

        $basename = basename($path);
        if (str_starts_with($basename, '_')) {
            return ["Template/underscore files are not release entries: {$basename}"];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return ["Unable to read: {$path}"];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ["Invalid JSON in {$basename}: ".$e->getMessage()];
        }

        if (! is_array($decoded)) {
            return ["{$basename} must decode to a JSON object."];
        }

        /** @var array<string, mixed> $decoded */
        return $this->validatePayload($decoded, $basename);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function validatePayload(array $payload, ?string $basename = null): array
    {
        $errors = [];

        foreach (['version', 'summary', 'detailed_changes', 'change_types', 'topic_tags'] as $required) {
            if (! array_key_exists($required, $payload)) {
                $errors[] = "Missing required field: {$required}";
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        $version = $payload['version'];
        if (! is_string($version) || ! preg_match('/^v\d+\.\d+\.\d+$/', $version)) {
            $errors[] = 'version must look like v1.2.3';
        } elseif ($basename !== null && $basename !== "{$version}.json") {
            $errors[] = "Filename must match version: expected {$version}.json, got {$basename}";
        }

        if (! is_string($payload['summary']) || trim($payload['summary']) === '') {
            $errors[] = 'summary must be a non-empty string';
        }

        $errors = [...$errors, ...$this->validateStringList($payload['detailed_changes'] ?? null, 'detailed_changes')];
        $errors = [...$errors, ...$this->validateEnumList($payload['change_types'] ?? null, 'change_types', Release::CHANGE_TYPES)];
        $errors = [...$errors, ...$this->validateEnumList($payload['topic_tags'] ?? null, 'topic_tags', Release::TOPIC_TAGS)];

        if (array_key_exists('theme', $payload) && $payload['theme'] !== null && ! is_string($payload['theme'])) {
            $errors[] = 'theme must be a string when present';
        }

        if (array_key_exists('affected_areas', $payload) && $payload['affected_areas'] !== null) {
            $errors = [...$errors, ...$this->validateStringList($payload['affected_areas'], 'affected_areas')];
        }

        if (array_key_exists('version_decision', $payload) && $payload['version_decision'] !== null) {
            $errors = [...$errors, ...$this->validateStringList($payload['version_decision'], 'version_decision')];
        }

        if (array_key_exists('validation', $payload) && $payload['validation'] !== null) {
            $errors = [...$errors, ...$this->validateStringList($payload['validation'], 'validation')];
        }

        if (array_key_exists('released_at', $payload) && $payload['released_at'] !== null) {
            if (! is_string($payload['released_at']) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $payload['released_at'])) {
                $errors[] = 'released_at must be YYYY-MM-DD when present';
            }
        }

        if (array_key_exists('previous_version', $payload) && $payload['previous_version'] !== null) {
            if (! is_string($payload['previous_version']) || ! preg_match('/^v\d+\.\d+\.\d+$/', $payload['previous_version'])) {
                $errors[] = 'previous_version must look like v1.2.3 when present';
            }
        }

        if (array_key_exists('pr', $payload) && $payload['pr'] !== null && ! is_int($payload['pr'])) {
            $errors[] = 'pr must be an integer when present';
        }

        return $errors;
    }

    /**
     * @return array<string, mixed>
     */
    public function parseFile(string $path): array
    {
        $errors = $this->validateFile($path);
        if ($errors !== []) {
            throw new InvalidArgumentException(implode("\n", $errors));
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function validateStringList(mixed $value, string $field): array
    {
        if (! is_array($value) || $value === []) {
            return ["{$field} must be a non-empty array of strings"];
        }

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                return ["{$field} must be a non-empty array of strings"];
            }
        }

        return [];
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function validateEnumList(mixed $value, string $field, array $allowed): array
    {
        if (! is_array($value) || $value === []) {
            return ["{$field} must be a non-empty array"];
        }

        foreach ($value as $item) {
            if (! is_string($item) || ! in_array($item, $allowed, true)) {
                return ["{$field} contains invalid value; allowed: ".implode(', ', $allowed)];
            }
        }

        return [];
    }
}
