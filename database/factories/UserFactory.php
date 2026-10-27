<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => Role::Public,
            'auth_provider' => 'password',
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function withContactDetails(): static
    {
        return $this->state(fn (array $attributes) => [
            'address_line1' => '10 Example Street',
            'address_line2' => 'Flat 2',
            'address_town' => 'Leeds',
            'address_county' => 'West Yorkshire',
            'address_postcode' => 'LS1 4AP',
            'address_country' => 'GB',
            'mobile_number' => '+447700900123',
            'whatsapp_type' => 'number',
            'whatsapp_value' => '+447700900123',
            'whatsapp_same_as_mobile' => true,
        ]);
    }

    public function staff(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::Staff,
            'auth_provider' => 'cloudron_oidc',
            'password' => null,
            'external_id' => fake()->uuid(),
        ]);
    }

    public function admin(): static
    {
        return $this->staff()->state(fn (array $attributes) => [
            'role' => Role::Admin,
        ]);
    }

    public function superAdmin(): static
    {
        return $this->staff()->state(fn (array $attributes) => [
            'role' => Role::SuperAdmin,
        ]);
    }
}
