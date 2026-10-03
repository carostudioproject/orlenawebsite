<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Support\AccountRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-users') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(AccountRules::normalize($this->all()));
    }

    public function rules(): array
    {
        $id = $this->route('user')?->id;

        return [
            'name' => ['required', 'string', 'max:160'],
            'username' => AccountRules::username($id),
            'email' => AccountRules::email($id),
            'role' => ['required', Rule::enum(Role::class)], 'is_active' => ['required', 'boolean'],
            // Optional: this person's user ID in Erzap (orders they confirm are sent under it).
            'erzap_sales_user_id' => ['nullable', 'integer', 'min:1', 'max:99999999'],
            'password' => [$this->route('user') ? 'nullable' : 'required', 'confirmed', 'max:128', AccountRules::password()],
        ];
    }

    public function messages(): array
    {
        return AccountRules::MESSAGES;
    }
}
