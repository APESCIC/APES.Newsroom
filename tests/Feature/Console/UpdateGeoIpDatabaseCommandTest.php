<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UpdateGeoIpDatabaseCommandTest extends TestCase
{
    private string $database;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = sys_get_temp_dir().'/geoip-test-'.uniqid().'/dbip-city-lite.mmdb';
        config([
            'security.geoip.database' => $this->database,
            'security.geoip.download_url' => 'https://download.example.test/dbip-city-lite-{month}.mmdb.gz',
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->database));

        parent::tearDown();
    }

    public function test_if_missing_skips_the_download_when_a_database_exists(): void
    {
        File::ensureDirectoryExists(dirname($this->database));
        file_put_contents($this->database, 'existing');
        Http::fake();

        $this->artisan('newsroom:geoip-update', ['--if-missing' => true])->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame('existing', file_get_contents($this->database));
    }

    public function test_an_invalid_download_keeps_the_current_database(): void
    {
        File::ensureDirectoryExists(dirname($this->database));
        file_put_contents($this->database, 'existing');
        Http::fake(['download.example.test/*' => Http::response(gzencode('not an mmdb file'))]);

        $this->artisan('newsroom:geoip-update')->assertFailed();

        $this->assertSame('existing', file_get_contents($this->database));
        $this->assertSame(['dbip-city-lite.mmdb'], array_map('basename', File::files(dirname($this->database))));
    }

    public function test_it_falls_back_to_last_month_and_fails_when_neither_file_exists(): void
    {
        $this->travelTo('2026-11-02 04:30:00');
        Http::fake(['download.example.test/*' => Http::response('', 404)]);

        $this->artisan('newsroom:geoip-update')->assertFailed();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), 'dbip-city-lite-2026-11.mmdb.gz'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), 'dbip-city-lite-2026-10.mmdb.gz'));
        $this->assertFileDoesNotExist($this->database);
    }
}
