<?php

namespace App\Http\Requests;

use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'regex:/^[A-Za-z0-9._-]+$/', 'min:4', 'max:50', Rule::unique('tbl_users', 'username')],
            'id_role' => ['required', 'integer', Rule::in($this->assignableRoleIds())],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }

    /**
     * @return list<int>
     */
    protected function assignableRoleIds(): array
    {
        /** @var User $actor */
        $actor = $this->user();

        return Role::query()->assignableBy($actor)->pluck('id')->all();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nama.required' => 'Nama wajib diisi.',
            'nama.max' => 'Nama maksimal :max karakter.',
            'username.required' => 'Username wajib diisi.',
            'username.regex' => 'Username hanya boleh berisi huruf, angka, titik (.), tanda hubung (-), dan garis bawah (_).',
            'username.min' => 'Username minimal :min karakter.',
            'username.max' => 'Username maksimal :max karakter.',
            'username.unique' => 'Username sudah dipakai. Gunakan username lain.',
            'id_role.required' => 'Role wajib dipilih.',
            'id_role.integer' => 'Role yang dipilih tidak valid.',
            'id_role.in' => 'Role yang dipilih tidak valid atau tidak boleh Anda berikan.',
            'password.required' => 'Password wajib diisi.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
            'password.min' => 'Password minimal :min karakter.',
            'password.letters' => 'Password harus mengandung huruf.',
            'password.numbers' => 'Password harus mengandung angka.',
            'status.required' => 'Status wajib dipilih.',
            'status.in' => 'Status tidak valid.',
        ];
    }
}
