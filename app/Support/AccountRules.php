<?php

namespace App\Support;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Shared account validation for team management and each user's own profile. */
class AccountRules
{
    public const MESSAGES = [
        'username.regex' => 'Username 3–30 karakter: huruf kecil, angka, titik, strip, atau underscore.',
        'username.unique' => 'This username is already used by another account.',
        'email.unique' => 'This email is already used by another account.',
    ];

    /** Lowercase and trim before validation; an empty email is stored as null. */
    public static function normalize(array $input): array
    {
        $email = strtolower(trim((string) ($input['email'] ?? '')));

        return ['username' => strtolower(trim((string) ($input['username'] ?? ''))), 'email' => $email === '' ? null : $email];
    }

    public static function username(?int $ignoreId = null): array
    {
        return ['required', 'string', 'regex:/^[a-z0-9._-]{3,30}$/', Rule::unique('users', 'username')->ignore($ignoreId)];
    }

    public static function email(?int $ignoreId = null): array
    {
        return ['nullable', 'email', 'max:254', Rule::unique('users', 'email')->ignore($ignoreId)];
    }

    public static function password(): Password
    {
        return Password::min(12)->letters()->numbers();
    }
}
