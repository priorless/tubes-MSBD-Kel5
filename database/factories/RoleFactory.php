<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Hanya dipakai di pengujian; data role produksi berasal dari sql/schema_sji.sql.
 *
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nama_role' => fake()->unique()->jobTitle(),
            'deskripsi' => fake()->sentence(),
        ];
    }

    public function named(string $namaRole): static
    {
        return $this->state(fn (array $attributes) => [
            'nama_role' => $namaRole,
        ]);
    }
}
