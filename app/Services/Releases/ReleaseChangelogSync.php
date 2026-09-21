<?php

namespace App\Services\Releases;

use App\Enums\ReleaseChannel;
use App\Models\Release;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use RuntimeException;

class ReleaseChangelogSync
{
    public function __construct(
        private readonly ChangelogEntryValidator $validator,
    ) {}

    public function directory(): string
    {
        $configured = config('newsroom.changelog_path');

        return is_string($configured) && $configured !== ''
            ? $configured
            : base_path('changelog/releases');
    }

    /**
     * @return list<string> Absolute paths to release JSON files (excludes _* templates).
     */
    public function entryPaths(): array
    {
        $dir = $this->directory();
        if (! is_dir($dir)) {
            return [];
        }

        $paths = File::glob($dir.'/*.json') ?: [];
        $paths = array_values(array_filter(
            $paths,
            fn (string $path) => ! str_starts_with(basename($path), '_'),
        ));

        usort($paths, function (string $a, string $b): int {
            return version_compare(
                $this->normalizeVersion(basename($a, '.json')),
                $this->normalizeVersion(basename($b, '.json')),
            );
        });

        return $paths;
    }

    /**
     * Upsert all file-backed releases and mark the highest semver as current.
     *
     * @return int Number of releases upserted
     */
    public function sync(): int
    {
        $paths = $this->entryPaths();
        if ($paths === []) {
            throw new RuntimeException('No changelog/releases/*.json entries found.');
        }

        $inBeta = (bool) config('newsroom.releases_in_beta', true);
        $channel = $inBeta ? ReleaseChannel::Beta : ReleaseChannel::Stable;
        $channelSuffix = $inBeta ? 'beta' : 'stable';

        $previousVersion = null;
        $upserted = 0;
        $highestSlug = null;

        foreach ($paths as $path) {
            $payload = $this->validator->parseFile($path);
            $version = (string) $payload['version'];
            $slug = Release::slugFromVersion($version);
            $bump = $this->bumpLabel($previousVersion, $version);
            $versionType = "{$bump} {$channelSuffix}";

            $existing = Release::query()->where('slug', $slug)->first();
            $releasedAt = $payload['released_at']
                ?? $existing?->released_at?->toDateString()
                ?? Carbon::today()->toDateString();

            $previous = $payload['previous_version'] ?? $previousVersion;

            $attributes = [
                'version' => $version,
                'previous_version' => $previous,
                'released_at' => $releasedAt,
                'channel' => $channel,
                'version_type' => $versionType,
                'theme' => $payload['theme'] ?? null,
                'is_published' => true,
                'slug' => $slug,
                'change_types' => $payload['change_types'],
                'topic_tags' => $payload['topic_tags'],
                'summary' => $payload['summary'],
                'detailed_changes' => $payload['detailed_changes'],
                'affected_areas' => $payload['affected_areas'] ?? [
                    'Website: APES Newsroom',
                    'See detailed changes for routes and surfaces touched',
                ],
                'version_decision' => $payload['version_decision'] ?? $this->defaultVersionDecision(
                    $previous,
                    $version,
                    $versionType,
                    $payload['pr'] ?? null,
                ),
                'validation' => $payload['validation'] ?? $this->defaultValidation($payload['pr'] ?? null),
            ];

            Release::query()->updateOrCreate(['slug' => $slug], $attributes);
            $previousVersion = $version;
            $highestSlug = $slug;
            $upserted++;
        }

        if ($highestSlug !== null) {
            $current = Release::query()->where('slug', $highestSlug)->firstOrFail();
            $current->markAsCurrent();
        }

        return $upserted;
    }

    private function normalizeVersion(string $version): string
    {
        return ltrim($version, 'vV');
    }

    private function bumpLabel(?string $previous, string $next): string
    {
        if ($previous === null) {
            return 'major';
        }

        [$pMajor, $pMinor, $pPatch] = array_map('intval', explode('.', $this->normalizeVersion($previous)));
        [$nMajor, $nMinor, $nPatch] = array_map('intval', explode('.', $this->normalizeVersion($next)));

        if ($nMajor > $pMajor) {
            return 'major';
        }
        if ($nMinor > $pMinor) {
            return 'minor';
        }
        if ($nPatch > $pPatch) {
            return 'patch';
        }

        return 'patch';
    }

    /**
     * @return list<string>
     */
    private function defaultVersionDecision(?string $previous, string $version, string $versionType, mixed $pr): array
    {
        $lines = [
            'Previous version: '.($previous ?? 'none'),
            'New version: '.$version,
            'Version type: '.$versionType,
            'Reason for version bump: changelog/releases entry shipped with the website update',
        ];

        if (is_int($pr) && $pr > 0) {
            $lines[] = 'Pull request: #'.$pr;
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function defaultValidation(mixed $pr): array
    {
        $checks = 'Checks run: CI on pull request';
        if (is_int($pr) && $pr > 0) {
            $checks .= ' #'.$pr;
        }

        return [
            $checks,
            'Manual checks completed: author-provided changelog entry reviewed in PR',
            'Known limitations: hub rows are overwritten from changelog/releases on each deploy sync',
            'Rollback notes: revert the changelog JSON and redeploy, or unpublish the release row',
        ];
    }
}
