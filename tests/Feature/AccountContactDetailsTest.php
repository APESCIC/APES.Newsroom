<?php

namespace Tests\Feature;

use App\Enums\ContentVisibility;
use App\Enums\ModerationStatus;
use App\Enums\PostStatus;
use App\Models\AuditLog;
use App\Models\Post;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountContactDetailsTest extends TestCase
{
    use RefreshDatabase;

    private const MOBILE = '+447700900123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_user_saves_normalised_contact_details(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('account.contact-details.update'), [
                'address_line1' => '10 Example Street',
                'address_town' => 'Leeds',
                'address_postcode' => 'ls14ap',
                'address_country' => 'gb',
                'mobile_number' => '07700 900123',
                'whatsapp_same_as_mobile' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'contact-details-updated');

        $details = $user->fresh()->contactDetails();
        $this->assertSame('LS1 4AP', $details['address_postcode']);
        $this->assertSame('GB', $details['address_country']);
        $this->assertSame(self::MOBILE, $details['mobile_number']);
        $this->assertSame('number', $details['whatsapp_type']);
        $this->assertSame(self::MOBILE, $details['whatsapp_value']);
        $this->assertTrue($details['whatsapp_same_as_mobile']);
    }

    public function test_contact_details_are_encrypted_at_rest(): void
    {
        $user = User::factory()->withContactDetails()->create();

        $raw = DB::table('users')->where('id', $user->id)->first();
        $this->assertNotSame(self::MOBILE, $raw->mobile_number);
        $this->assertStringNotContainsString('LS1', (string) $raw->address_postcode);
        $this->assertSame(self::MOBILE, $user->fresh()->mobile_number);
    }

    public function test_invalid_values_are_rejected_with_clear_messages(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('account.show'))
            ->patch(route('account.contact-details.update'), [
                'address_postcode' => '12345',
                'mobile_number' => '0770',
                'whatsapp_type' => 'username',
                'whatsapp_value' => '!!',
            ])
            ->assertRedirect(route('account.show'))
            ->assertSessionHasErrors([
                'address_postcode' => 'Enter a valid UK postcode, for example LS1 4AP.',
                'mobile_number' => 'Enter a valid phone number, for example 07700 900123 or +44 7700 900123.',
                'whatsapp_value',
            ]);

        $this->assertNull($user->fresh()->mobile_number);
    }

    public function test_postcode_format_is_only_enforced_for_uk_addresses(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('account.contact-details.update'), ['address_postcode' => '75001', 'address_country' => 'FR'])
            ->assertSessionHasNoErrors();

        $this->assertSame('75001', $user->fresh()->address_postcode);
    }

    public function test_same_as_mobile_needs_a_mobile_number(): void
    {
        $this->actingAs(User::factory()->create())
            ->patch(route('account.contact-details.update'), ['whatsapp_same_as_mobile' => true])
            ->assertSessionHasErrors('mobile_number');
    }

    public function test_audit_entry_names_changed_groups_without_values(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('account.contact-details.update'), ['mobile_number' => '07700 900123']);

        $log = AuditLog::query()->where('action', 'account.contact_details_updated')->sole();
        $this->assertSame(['changed' => ['mobile_number']], $log->payload);
        $this->assertStringNotContainsString('7700', json_encode($log->payload));

        $this->actingAs($user)->patch(route('account.contact-details.update'), ['mobile_number' => '+447700900123']);
        $this->assertSame(1, AuditLog::query()->where('action', 'account.contact_details_updated')->count());
    }

    public function test_account_page_and_export_include_contact_details(): void
    {
        $user = User::factory()->withContactDetails()->create();

        $this->actingAs($user)->get(route('account.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Account/Profile')
                ->where('contact_details.mobile_number', self::MOBILE)
                ->where('contact_details.address_postcode', 'LS1 4AP')
                ->missing('auth.user.mobile_number'));

        $export = json_decode($this->actingAs($user)->withRecentAuthentication()->get(route('account.export'))->streamedContent(), true);
        $this->assertSame(self::MOBILE, $export['contact_details']['mobile_number']);
        $this->assertSame('10 Example Street', $export['contact_details']['address_line1']);
    }

    public function test_contact_details_never_reach_public_pages_or_apis(): void
    {
        config(['newsroom.content_api.key' => 'test-content-key']);
        $author = User::factory()->staff()->withContactDetails()->create();
        $post = Post::factory()->create([
            'author_id' => $author->id,
            'status' => PostStatus::Published,
            'published_at' => now()->subMinute(),
            'visibility' => ContentVisibility::Public,
        ]);
        $reader = User::factory()->withContactDetails()->create();
        $profile = Profile::query()->create([
            'user_id' => $reader->id,
            'display_name' => 'Reader',
            'public_opt_in' => true,
            'moderation_status' => ModerationStatus::Approved,
        ]);

        $this->assertArrayNotHasKey('mobile_number', $reader->toArray());
        $this->get(route('profiles.show', $profile))->assertOk()->assertDontSee('7700900123')->assertDontSee('Example Street');
        $this->withHeader('X-Newsroom-Content-Key', 'test-content-key')
            ->getJson('/api/content/v1/posts/'.$post->slug)
            ->assertOk()
            ->assertDontSee('7700900123');
        $this->actingAs($reader)->get('/')->assertDontSee('7700900123');
    }

    public function test_self_service_deletion_removes_contact_details(): void
    {
        $user = User::factory()->withContactDetails()->create();

        $this->actingAs($user)->withRecentAuthentication()->delete(route('account.destroy'))->assertRedirect(route('home'));

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }
}
