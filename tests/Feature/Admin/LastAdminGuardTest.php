<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\Admin\AccountLockService;
use App\Services\Admin\UserErasureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The services refuse the last unlocked admin even when the policy check has
 * already passed, so concurrent admin actions cannot leave no admin.
 */
class LastAdminGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_last_unlocked_admin_cannot_be_locked(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->admin()->create(['locked_at' => now()]);

        $this->expectException(ValidationException::class);

        try {
            app(AccountLockService::class)->lock($admin, User::factory()->staff()->create(), 'Race', Request::create('/'));
        } finally {
            $this->assertNull($admin->fresh()->locked_at);
        }
    }

    public function test_the_last_unlocked_admin_cannot_be_erased(): void
    {
        $admin = User::factory()->admin()->create();

        $this->expectException(ValidationException::class);

        try {
            app(UserErasureService::class)->erase($admin, User::factory()->staff()->create(), 'Race', Request::create('/'));
        } finally {
            $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => 'admin']);
        }
    }

    public function test_an_admin_can_be_locked_while_another_unlocked_admin_remains(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();

        app(AccountLockService::class)->lock($admin, $other, 'Lost laptop', Request::create('/'));

        $this->assertNotNull($admin->fresh()->locked_at);
    }
}
