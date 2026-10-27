<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\LaravelPasskeys\Models\Concerns\HasPasskeys;
use Spatie\LaravelPasskeys\Models\Concerns\InteractsWithPasskeys;

#[Fillable([
    'name', 'email', 'password', 'auth_provider', 'external_id',
    'address_line1', 'address_line2', 'address_town', 'address_county', 'address_postcode', 'address_country',
    'mobile_number', 'whatsapp_type', 'whatsapp_value', 'whatsapp_same_as_mobile',
])]
#[Hidden([
    'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_used_step',
    'address_line1', 'address_line2', 'address_town', 'address_county', 'address_postcode', 'address_country',
    'mobile_number', 'whatsapp_type', 'whatsapp_value', 'whatsapp_same_as_mobile',
])]
class User extends Authenticatable implements HasPasskeys, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, InteractsWithPasskeys, Notifiable;

    /**
     * Private contact columns. Only shown to the account holder and Admin+.
     *
     * @var list<string>
     */
    public const CONTACT_FIELDS = [
        'address_line1',
        'address_line2',
        'address_town',
        'address_county',
        'address_postcode',
        'address_country',
        'mobile_number',
        'whatsapp_type',
        'whatsapp_value',
        'whatsapp_same_as_mobile',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'matched_mailing_notice_pending' => 'boolean',
            'password' => 'hashed',
            'role' => Role::class,
            'ldap_group_snapshot' => 'array',
            'auth_epoch' => 'integer',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_used_step' => 'integer',
            'locked_at' => 'datetime',
            'address_line1' => 'encrypted',
            'address_line2' => 'encrypted',
            'address_town' => 'encrypted',
            'address_county' => 'encrypted',
            'address_postcode' => 'encrypted',
            'mobile_number' => 'encrypted',
            'whatsapp_value' => 'encrypted',
            'whatsapp_same_as_mobile' => 'boolean',
        ];
    }

    /**
     * @return array{address_line1: ?string, address_line2: ?string, address_town: ?string, address_county: ?string, address_postcode: ?string, address_country: string, mobile_number: ?string, whatsapp_type: ?string, whatsapp_value: ?string, whatsapp_same_as_mobile: bool}
     */
    public function contactDetails(): array
    {
        return [
            'address_line1' => $this->address_line1,
            'address_line2' => $this->address_line2,
            'address_town' => $this->address_town,
            'address_county' => $this->address_county,
            'address_postcode' => $this->address_postcode,
            'address_country' => $this->address_country ?? 'GB',
            'mobile_number' => $this->mobile_number,
            'whatsapp_type' => $this->whatsapp_type,
            'whatsapp_value' => $this->whatsapp_value,
            'whatsapp_same_as_mobile' => (bool) $this->whatsapp_same_as_mobile,
        ];
    }

    /**
     * Values for clearing every contact column during erasure.
     *
     * @return array<string, mixed>
     */
    public static function clearedContactDetails(): array
    {
        return [
            ...array_fill_keys(self::CONTACT_FIELDS, null),
            'address_country' => 'GB',
            'whatsapp_same_as_mobile' => false,
        ];
    }

    /**
     * A locked account cannot sign in by any method or use API tokens.
     */
    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /**
     * True only once setup has been confirmed with a valid code; a secret
     * alone means setup was started but never finished.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * WebAuthn user handle: opaque, so authenticators never hold the
     * sequential account id.
     */
    public function getPasskeyId(): string
    {
        return hash_hmac('sha256', 'passkey-user:'.$this->getKey(), (string) config('app.key'));
    }

    /**
     * @return HasMany<MagicLinkToken, $this>
     */
    public function magicLinkTokens(): HasMany
    {
        return $this->hasMany(MagicLinkToken::class);
    }

    /**
     * @return HasMany<ApiToken, $this>
     */
    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    /**
     * @return HasMany<EmailChangeRequest, $this>
     */
    public function emailChangeRequests(): HasMany
    {
        return $this->hasMany(EmailChangeRequest::class);
    }

    /**
     * @return HasMany<UserSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    /**
     * @return HasOne<Profile, $this>
     */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /**
     * @return HasOne<Membership, $this>
     */
    public function membership(): HasOne
    {
        return $this->hasOne(Membership::class);
    }

    /**
     * Staff accounts authenticated via Cloudron OIDC are directory-verified.
     */
    public function hasVerifiedEmail(): bool
    {
        if ($this->auth_provider === 'cloudron_oidc') {
            return true;
        }

        return ! is_null($this->email_verified_at);
    }

    /**
     * Staff-level and Cloudron accounts must authenticate through Cloudron
     * SSO, where the live directory group check runs.
     */
    public function requiresSso(): bool
    {
        return $this->auth_provider === 'cloudron_oidc'
            || ($this->role?->atLeast(Role::Staff) ?? false);
    }
}
