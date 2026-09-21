<?php

namespace Database\Seeders;

use App\Enums\ReleaseChannel;
use App\Models\Release;
use Illuminate\Database\Seeder;

class ReleaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedRelease([
            'version' => 'v1.0.0',
            'previous_version' => null,
            'released_at' => '2026-08-06',
            'channel' => ReleaseChannel::Beta,
            'version_type' => 'major beta',
            'theme' => 'Public Newsroom launch',
            'is_current' => false,
            'is_published' => true,
            'slug' => 'release-v100',
            'change_types' => ['added'],
            'topic_tags' => ['public-facing'],
            'summary' => 'Launched APES Newsroom as the public publishing platform for APES CIC, Shelter & Rescue, and Pet Care Clinic, replacing Ghost for day-to-day publishing.',
            'detailed_changes' => [
                'Shipped the public newsroom surfaces: home, channels, articles, archives, search, RSS, and sitemap.',
                'Delivered staff publishing with Editor.js, review, scheduling, mailing campaigns, and moderation workspaces.',
                'Completed the guarded cutover onto the existing Cloudron LAMP app while keeping Ghost recoverable.',
            ],
            'affected_areas' => [
                'Website: www.apesnews.org.uk',
                'Page or route: public newsroom and authenticated staff/admin workspaces',
                'User groups affected: public visitors, mailing subscribers, staff editors, admins',
                'Public impact: visitors read Newsroom content on the new Laravel/Inertia site',
                'Internal impact: editorial and campaign workflows move to Newsroom workspaces',
            ],
            'version_decision' => [
                'Previous version: none (initial public release)',
                'New version: v1.0.0',
                'Version type: major beta',
                'Reason for version bump: first public Newsroom release after Ghost cutover',
            ],
            'validation' => [
                'Checks run: CI on main, beta acceptance, deploy health checks',
                'Manual checks completed: public route review and cutover runbook review',
                'Known limitations: apex DNS transition and Ghost retirement remained separate operational decisions',
                'Rollback notes: Cloudron rollback to previous release symlink; Ghost kept recoverable',
            ],
        ]);

        $this->seedRelease([
            'version' => 'v1.1.0',
            'previous_version' => 'v1.0.0',
            'released_at' => '2026-09-18',
            'channel' => ReleaseChannel::Beta,
            'version_type' => 'minor beta',
            'theme' => 'Admin Ghost content import',
            'is_current' => false,
            'is_published' => true,
            'slug' => 'release-v110',
            'change_types' => ['added'],
            'topic_tags' => ['internal-only'],
            'summary' => 'Added the admin Ghost content JSON import path so staff can dry-run and confirm content imports without committing export files to the repository.',
            'detailed_changes' => [
                'Added admin upload, dry-run, and confirm flow for Ghost content JSON (and optional media zip).',
                'Queued import processing with audit logging and report download for review.',
                'Kept member, newsletter, and Stripe data out of this content import path.',
            ],
            'affected_areas' => [
                'Website: APES Newsroom admin workspace',
                'Page or route: /admin/imports/ghost-content',
                'User groups affected: staff and admins performing imports',
                'Public impact: none directly; enables safer content reconciliation',
                'Internal impact: import runs are reviewable before confirm',
            ],
            'version_decision' => [
                'Previous version: v1.0.0',
                'New version: v1.1.0',
                'Version type: minor beta',
                'Reason for version bump: new admin import capability without changing public IA',
            ],
            'validation' => [
                'Checks run: feature tests for admin Ghost content import',
                'Manual checks completed: upload/dry-run/confirm path review',
                'Known limitations: applying a specific export remains an operational step (#61)',
                'Rollback notes: remove import UI/routes and unused import runs if required',
            ],
        ]);

        $this->seedRelease([
            'version' => 'v1.1.1',
            'previous_version' => 'v1.1.0',
            'released_at' => '2026-09-18',
            'channel' => ReleaseChannel::Beta,
            'version_type' => 'patch beta',
            'theme' => 'Change Log Hub',
            'is_current' => true,
            'is_published' => true,
            'slug' => 'release-v111',
            'change_types' => ['added'],
            'topic_tags' => ['public-facing', 'compliance'],
            'summary' => 'Added a public Change Log Hub with searchable, filterable release cards, admin authoring, and footer version discovery matching the apes.org.uk hub UX.',
            'detailed_changes' => [
                'Introduced release records with structured Summary, Detailed changes, Affected areas, Version decision, and Validation sections.',
                'Shipped /change-log-hub with hero, breadcrumbs, search, filter chips, expand/collapse, and deep links.',
                'Added admin CRUD for release notes and seeded Newsroom releases from v1.0.0 forward.',
                'Surfaced the current website version and Change Log Hub link in the public footer.',
            ],
            'affected_areas' => [
                'Website: APES Newsroom',
                'Page or route: /change-log-hub, /admin/releases, public footer, sitemap',
                'Files changed: Release model/migration, hub page, admin pages, shared Inertia props, docs',
                'User groups affected: public visitors, staff/admins maintaining release notes',
                'Public impact: visitors can review Newsroom release history with the same hub behaviours as apes.org.uk',
                'Internal impact: maintainers author releases in admin instead of hardcoding HTML',
            ],
            'version_decision' => [
                'Previous version: v1.1.0',
                'New version: v1.1.1',
                'Version type: patch beta',
                'Reason for version bump: new public transparency surface without changing article publishing architecture',
            ],
            'validation' => [
                'Checks run: PHPUnit feature tests, frontend typecheck/lint, hub filter/search behaviour tests',
                'Manual checks completed: public hub UX review against the reference Change Log Hub',
                'Known limitations: VERSION/CHANGELOG sync remains a manual maintainer process for this pass',
                'Rollback notes: unpublish or remove release records and hub route if the surface must be withdrawn',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function seedRelease(array $attributes): void
    {
        Release::query()->updateOrCreate(
            ['slug' => $attributes['slug']],
            $attributes,
        );
    }
}
