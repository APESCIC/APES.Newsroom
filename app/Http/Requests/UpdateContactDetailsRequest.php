<?php

namespace App\Http\Requests;

use App\Support\Account\ContactDetails;
use Illuminate\Foundation\Http\FormRequest;

class UpdateContactDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(ContactDetails::normalize($this->all()));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ContactDetails::rules($this->all());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ContactDetails::messages();
    }
}
