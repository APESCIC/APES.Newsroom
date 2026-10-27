<?php

namespace Database\Factories;

use App\Models\MailingContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailingContact>
 */
class MailingContactFactory extends Factory
{
    protected $model = MailingContact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();

        return [
            'email' => $email,
            'email_normalized' => strtolower(trim($email)),
            'name' => null,
            'user_id' => null,
        ];
    }
}
