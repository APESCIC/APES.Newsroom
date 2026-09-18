<?php

namespace Database\Factories;

use App\Enums\ReleaseChannel;
use App\Models\Release;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Release>
 */
class ReleaseFactory extends Factory
{
    protected $model = Release::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $version = 'v'.fake()->unique()->numerify('#.#.#');

        return [
            'version' => $version,
            'previous_version' => 'v'.fake()->numerify('#.#.#'),
            'released_at' => fake()->date(),
            'channel' => ReleaseChannel::Stable,
            'version_type' => 'patch stable',
            'theme' => fake()->words(3, true),
            'is_current' => false,
            'is_published' => false,
            'slug' => Release::slugFromVersion($version),
            'change_types' => ['changed', 'fixed'],
            'topic_tags' => ['public-facing'],
            'summary' => fake()->sentence(),
            'detailed_changes' => [fake()->sentence(), fake()->sentence()],
            'affected_areas' => [
                'Website: APES Newsroom',
                'Page or route: /change-log-hub',
                'User groups affected: public visitors',
            ],
            'version_decision' => [
                'Previous version: v0.0.0',
                'New version: '.$version,
                'Version type: patch stable',
                'Reason for version bump: test fixture',
            ],
            'validation' => [
                'Checks run: automated tests',
                'Manual checks completed: source review',
                'Known limitations: none',
                'Rollback notes: restore previous release record',
            ],
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'is_published' => true,
        ]);
    }

    public function current(): static
    {
        return $this->state(fn () => [
            'is_published' => true,
            'is_current' => true,
        ]);
    }

    public function beta(): static
    {
        return $this->state(fn () => [
            'channel' => ReleaseChannel::Beta,
            'version_type' => 'patch beta',
        ]);
    }
}
