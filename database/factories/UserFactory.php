<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * Hanya dipakai di pengujian; akun produksi dibuat lewat modul Manajemen User.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nama' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'password' => static::$password ??= Hash::make('password'),
            'id_role' => Role::factory(),
            'status' => User::StatusAktif,
            'must_change_password' => false,
        ];
    }

    public function withRole(string $namaRole): static
    {
        return $this->state(fn (array $attributes) => [
            'id_role' => Role::query()->firstOrCreate(['nama_role' => $namaRole])->id,
        ]);
    }

    public function mustChangePassword(): static
    {
        return $this->state(fn (array $attributes) => [
            'must_change_password' => true,
        ]);
    }

    public function nonaktif(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => User::StatusNonaktif,
        ]);
    }
}
