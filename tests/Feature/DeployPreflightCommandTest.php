<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

class DeployPreflightCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_preflight_reports_an_up_to_date_database_when_no_migrations_are_pending(): void
    {
        $command = Artisan::all()['deploy:preflight'];

        Artisan::shouldReceive('call')
            ->once()
            ->with('migrate:status', ['--pending' => true])
            ->andReturn(0);
        Artisan::shouldReceive('output')
            ->once()
            ->andReturn("\n   INFO  No pending migrations.  \n");

        $tester = new CommandTester($command);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('up to date', $tester->getDisplay());
        $this->assertStringContainsString('GD supports JPEG and WebP', $tester->getDisplay());
    }

    public function test_preflight_reports_when_migrations_are_pending(): void
    {
        $command = Artisan::all()['deploy:preflight'];

        Artisan::shouldReceive('call')
            ->once()
            ->with('migrate:status', ['--pending' => true])
            ->andReturn(0);
        Artisan::shouldReceive('output')
            ->once()
            ->andReturn("\n  2026_08_09_000000_create_example_table  Pending  \n");

        $tester = new CommandTester($command);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString(
            'pending migrations exist - deploy will run them',
            $tester->getDisplay()
        );
    }
}
