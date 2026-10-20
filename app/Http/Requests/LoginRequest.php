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
        // Also per username from any IP: password guessing spread over many addresses locks the account for 15 minutes.
        $account = 'login-account:'.hash('sha256', mb_strtolower((string) $this->string('username')));
        foreach ([[$key, 5], [$account, 10]] as [$limit, $max]) {
            if (RateLimiter::tooManyAttempts($limit, $max)) {
                throw ValidationException::withMessages(['username' => 'Too many attempts. Try again in '.(int) ceil(RateLimiter::availableIn($limit) / 60).' minute(s).']);
            }
        }
        if (! Auth::attempt([...$this->only('username', 'password'), 'is_active' => true])) {
            RateLimiter::hit($key, 60);
            RateLimiter::hit($account, 900);
            throw ValidationException::withMessages(['username' => 'Incorrect username or password, or the account is inactive.']);
        }
        RateLimiter::clear($key);
        RateLimiter::clear($account);
    }
}
