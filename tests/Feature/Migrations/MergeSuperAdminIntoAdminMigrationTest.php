<?php

namespace Tests\Feature\Migrations;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MergeSuperAdminIntoAdminMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const ACTION = 'roles.merged_into_admin';

    private Migration $migration;

    private int $ownerId;

    private int $staffId;

    private int $roleChangeLogId;

    private int $otherLogId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require database_path('migrations/2026_11_05_100000_merge_super_admin_into_admin.php');
        $this->migration->down();

        $this->ownerId = User::factory()->admin()->create()->id;
        $this->staffId = User::factory()->staff()->create()->id;
        DB::table('users')->where('id', $this->ownerId)->update(['role' => 'super_admin']);

        $this->roleChangeLogId = DB::table('audit_logs')->insertGetId([
            'action' => 'staff.role_changed',
            'payload' => json_encode(['from' => 'super_admin', 'to' => 'staff', 'groups' => ['newsroom.staff']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->otherLogId = DB::table('audit_logs')->insertGetId([
            'action' => 'user.locked',
            'payload' => json_encode(['reason' => 'Lost laptop']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_up_moves_super_admins_and_audit_entries_to_admin(): void
    {
        $this->migration->up();

        $this->assertSame(0, DB::table('users')->where('role', 'super_admin')->count());
        $this->assertSame('admin', DB::table('users')->where('id', $this->ownerId)->value('role'));
        $this->assertSame('staff', DB::table('users')->where('id', $this->staffId)->value('role'));

        $this->assertSame(0, DB::table('audit_logs')->where('payload', 'like', '%super_admin%')->count());
        $this->assertSame(
            ['from' => 'admin', 'to' => 'staff', 'groups' => ['newsroom.staff']],
            json_decode((string) DB::table('audit_logs')->where('id', $this->roleChangeLogId)->value('payload'), true),
        );

        $this->assertSame(2, DB::table('role_merge_snapshots')->count());
    }

    public function test_up_records_one_audit_entry_for_the_migration(): void
    {
        $this->migration->up();

        $entries = DB::table('audit_logs')->where('action', self::ACTION)->get();

        $this->assertCount(1, $entries);
        $this->assertSame(['users' => 1, 'audit_entries' => 1], json_decode((string) $entries->first()->payload, true));
    }

    public function test_up_records_nothing_when_there_is_nothing_to_merge(): void
    {
        DB::table('users')->where('id', $this->ownerId)->update(['role' => 'admin']);
        DB::table('audit_logs')->where('id', $this->roleChangeLogId)->delete();

        $this->migration->up();

        $this->assertSame(0, DB::table('audit_logs')->where('action', self::ACTION)->count());
        $this->assertSame(0, DB::table('role_merge_snapshots')->count());
    }

    public function test_down_restores_users_and_audit_entries_from_the_snapshot(): void
    {
        $originalPayload = DB::table('audit_logs')->where('id', $this->roleChangeLogId)->value('payload');
        $untouchedPayload = DB::table('audit_logs')->where('id', $this->otherLogId)->value('payload');

        $this->migration->up();
        $this->migration->down();

        $this->assertSame('super_admin', DB::table('users')->where('id', $this->ownerId)->value('role'));
        $this->assertSame('staff', DB::table('users')->where('id', $this->staffId)->value('role'));
        $this->assertSame($originalPayload, DB::table('audit_logs')->where('id', $this->roleChangeLogId)->value('payload'));
        $this->assertSame($untouchedPayload, DB::table('audit_logs')->where('id', $this->otherLogId)->value('payload'));
        $this->assertSame(0, DB::table('audit_logs')->where('action', self::ACTION)->count());
        $this->assertFalse(Schema::hasTable('role_merge_snapshots'));
    }
}
