<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends StoreUserRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->targetUser()) ?? false;
    }

    /**
     * Get the validation rules that apply to the request. Password opsional: diisi hanya untuk reset.
     *
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        $target = $this->targetUser();
        $isEditingSelf = $this->user()?->is($target) ?? false;

        // Akun sendiri tidak boleh dinonaktifkan atau diganti role-nya agar tidak terkunci dari sistem.
        $allowedRoleIds = $isEditingSelf ? [$target->id_role] : $this->assignableRoleIds();
        $allowedStatuses = $isEditingSelf ? [User::StatusAktif] : [User::StatusAktif, User::StatusNonaktif];

        return [
            'nama' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'regex:/^[A-Za-z0-9._-]+$/', 'min:4', 'max:50', Rule::unique('tbl_users', 'username')->ignore($target->id)],
            'id_role' => ['required', 'integer', Rule::in($allowedRoleIds)],
            'status' => ['required', Rule::in($allowedStatuses)],
            'password' => ['nullable', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'id_role.in' => 'Role yang dipilih tidak valid atau tidak boleh Anda berikan (role akun sendiri tidak dapat diubah).',
            'status.in' => 'Status tidak valid (akun sendiri tidak dapat dinonaktifkan).',
        ];
    }

    private function targetUser(): User
    {
        /** @var User $target */
        $target = $this->route('user');

        return $target;
    }
}
