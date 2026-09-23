<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Usernames are stored lowercase, so login is not case-sensitive.
        $this->merge(['username' => strtolower(trim((string) $this->input('username')))]);
    }

    public function rules(): array
    {
        return ['username' => ['required', 'string', 'max:30'], 'password' => ['required', 'string', 'max:1024']];
    }

    public function messages(): array
    {
        return ['username.required' => 'Enter your username.', 'password.required' => 'Enter your password.'];
    }

    public function authenticate(): void
    {
        $key = hash('sha256', $this->string('username').'|'.$this->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['username' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }
        if (! Auth::attempt([...$this->only('username', 'password'), 'is_active' => true])) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['username' => 'Incorrect username or password, or the account is inactive.']);
        }
        RateLimiter::clear($key);
    }
}
