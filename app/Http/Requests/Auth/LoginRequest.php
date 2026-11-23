<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class LoginRequest extends FormRequest
{
    private const int MAX_ATTEMPTS = 5;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.required' => 'Username wajib diisi.',
            'username.max' => 'Username maksimal 50 karakter.',
            'username.regex' => 'Username hanya boleh berisi huruf, angka, titik, garis bawah, dan tanda hubung.',
            'password.required' => 'Password wajib diisi.',
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $user = User::query()->with('role')->where('username', $this->string('username')->trim()->toString())->first();

        if ($user === null || ! $this->passwordMatches($user, $this->string('password')->toString())) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'username' => 'Username atau password salah.',
            ]);
        }

        if (! $user->isAktif()) {
            throw ValidationException::withMessages([
                'username' => 'Akun Anda sudah dinonaktifkan. Hubungi HRD.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        Auth::login($user);
    }

    /**
     * Cocokkan password dengan hash bcrypt. Akun lapangan yang dibuat trigger trg_auto_akun_karyawan
     * masih memakai SHA2(nip); jika cocok, hash langsung diganti ke bcrypt dan user wajib ganti password.
     */
    private function passwordMatches(User $user, string $plainPassword): bool
    {
        $storedHash = $user->getAuthPassword();

        if ($this->isLegacySha256Hash($storedHash)) {
            if (! hash_equals(strtolower($storedHash), hash('sha256', $plainPassword))) {
                return false;
            }

            $user->forceFill([
                'password' => Hash::make($plainPassword),
                'must_change_password' => true,
            ])->save();

            return true;
        }

        try {
            return Hash::check($plainPassword, $storedHash);
        } catch (RuntimeException) {
            return false;
        }
    }

    private function isLegacySha256Hash(string $hash): bool
    {
        return preg_match('/^[a-f0-9]{64}$/i', $hash) === 1;
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'username' => "Terlalu banyak percobaan login. Coba lagi dalam {$seconds} detik.",
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('username')).'|'.$this->ip());
    }
}
