<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Support\Account\ContactDetails;
use App\Support\EmailAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->target()) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            ...ContactDetails::normalize($this->all()),
            'name' => trim((string) $this->input('name')),
            'email' => EmailAddress::normalize((string) $this->input('email')),
            'reason' => trim((string) $this->input('reason')),
        ]);
    }

    /**
     * Cloudron SSO sets the name and email of directory accounts on every
     * sign-in, so they cannot be changed here.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $target = $this->target();
        $sso = $target->auth_provider === 'cloudron_oidc';

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::prohibitedIf(fn () => $sso && $this->input('name') !== $target->name),
            ],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::prohibitedIf(fn () => $sso && $this->input('email') !== EmailAddress::normalize((string) $target->email)),
                Rule::unique('users')->ignore($target->id),
            ],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            ...ContactDetails::rules($this->all()),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...ContactDetails::messages(),
            'name.prohibited' => 'This name comes from Cloudron SSO and is updated when the user signs in.',
            'email.prohibited' => 'This email address is managed by Cloudron SSO.',
        ];
    }

    public function target(): User
    {
        /** @var User $user */
        $user = $this->route('user');

        return $user;
    }
}
