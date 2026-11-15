<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use CreatesSjiAuthTables, RefreshDatabase;

    private User $ceo;

    private User $hrd;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([Role::Hrd, Role::Operasional, Role::Keuangan, Role::KaryawanLapangan, Role::Ceo] as $namaRole) {
            Role::factory()->named($namaRole)->create();
        }

        $this->ceo = User::factory()->withRole(Role::Ceo)->create(['nama' => 'Bu Direktur']);
        $this->hrd = User::factory()->withRole(Role::Hrd)->create(['nama' => 'Pak HRD']);
    }

    private function roleId(string $namaRole): int
    {
        return Role::query()->where('nama_role', $namaRole)->value('id');
    }

    public function test_index_lists_internal_users_only(): void
    {
        User::factory()->withRole(Role::KaryawanLapangan)->create(['nama' => 'Satpam Lapangan']);

        $this->actingAs($this->hrd)
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('Bu Direktur')
            ->assertDontSee('Satpam Lapangan');
    }

    public function test_store_creates_user_with_hashed_password_and_force_change_flag(): void
    {
        $this->actingAs($this->hrd)
            ->post(route('users.store'), [
                'nama' => 'Staf Keuangan',
                'username' => 'keuangan01',
                'id_role' => $this->roleId(Role::Keuangan),
                'password' => 'Awal12345',
                'password_confirmation' => 'Awal12345',
            ])
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('success');

        $user = User::query()->where('username', 'keuangan01')->firstOrFail();
        $this->assertSame('Staf Keuangan', $user->nama);
        $this->assertNotSame('Awal12345', $user->password);
        $this->assertTrue(Hash::check('Awal12345', $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertSame(User::StatusAktif, $user->status);
    }

    public function test_store_rejects_duplicate_username(): void
    {
        User::factory()->withRole(Role::Keuangan)->create(['username' => 'dipakai']);

        $this->actingAs($this->hrd)
            ->post(route('users.store'), [
                'nama' => 'Orang Lain',
                'username' => 'dipakai',
                'id_role' => $this->roleId(Role::Keuangan),
                'password' => 'Awal12345',
                'password_confirmation' => 'Awal12345',
            ])
            ->assertSessionHasErrors(['username' => 'Username sudah dipakai. Gunakan username lain.']);
    }

    public function test_store_rejects_unknown_and_karyawan_lapangan_role(): void
    {
        foreach ([999, $this->roleId(Role::KaryawanLapangan)] as $idRole) {
            $this->actingAs($this->ceo)
                ->post(route('users.store'), [
                    'nama' => 'Staf',
                    'username' => 'staf'.$idRole,
                    'id_role' => $idRole,
                    'password' => 'Awal12345',
                    'password_confirmation' => 'Awal12345',
                ])
                ->assertSessionHasErrors('id_role');
        }

        $this->assertDatabaseMissing('tbl_users', ['nama' => 'Staf']);
    }

    public function test_hrd_cannot_create_or_manage_ceo_accounts(): void
    {
        $this->actingAs($this->hrd)
            ->post(route('users.store'), [
                'nama' => 'CEO Palsu',
                'username' => 'ceopalsu',
                'id_role' => $this->roleId(Role::Ceo),
                'password' => 'Awal12345',
                'password_confirmation' => 'Awal12345',
            ])
            ->assertSessionHasErrors('id_role');

        $this->actingAs($this->hrd)->get(route('users.edit', $this->ceo))->assertForbidden();
        $this->actingAs($this->hrd)->delete(route('users.destroy', $this->ceo))->assertForbidden();
    }

    public function test_update_resets_password_and_sets_force_change_flag(): void
    {
        $target = User::factory()->withRole(Role::Operasional)->create();

        $this->actingAs($this->ceo)
            ->put(route('users.update', $target), [
                'nama' => 'Nama Baru',
                'username' => $target->username,
                'id_role' => $this->roleId(Role::Keuangan),
                'status' => User::StatusNonaktif,
                'password' => 'Reset12345',
                'password_confirmation' => 'Reset12345',
            ])
            ->assertRedirect(route('users.index'));

        $target->refresh();
        $this->assertSame('Nama Baru', $target->nama);
        $this->assertSame($this->roleId(Role::Keuangan), $target->id_role);
        $this->assertSame(User::StatusNonaktif, $target->status);
        $this->assertTrue($target->must_change_password);
        $this->assertTrue(Hash::check('Reset12345', $target->password));
    }

    public function test_update_without_password_keeps_existing_password(): void
    {
        $target = User::factory()->withRole(Role::Operasional)->create();
        $hashLama = $target->password;

        $this->actingAs($this->hrd)
            ->put(route('users.update', $target), [
                'nama' => 'Tetap',
                'username' => $target->username,
                'id_role' => $target->id_role,
                'status' => User::StatusAktif,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($hashLama, $target->refresh()->password);
        $this->assertFalse($target->must_change_password);
    }

    public function test_user_cannot_deactivate_self_or_change_own_role(): void
    {
        $this->actingAs($this->hrd)
            ->put(route('users.update', $this->hrd), [
                'nama' => 'Pak HRD',
                'username' => $this->hrd->username,
                'id_role' => $this->roleId(Role::Keuangan),
                'status' => User::StatusNonaktif,
            ])
            ->assertSessionHasErrors(['id_role', 'status']);
    }

    public function test_user_cannot_delete_self(): void
    {
        $this->actingAs($this->ceo)->delete(route('users.destroy', $this->ceo))->assertForbidden();
    }

    public function test_karyawan_lapangan_account_cannot_be_edited_here(): void
    {
        $lapangan = User::factory()->withRole(Role::KaryawanLapangan)->create();

        $this->actingAs($this->ceo)->get(route('users.edit', $lapangan))->assertForbidden();
    }

    public function test_destroy_deletes_user(): void
    {
        $target = User::factory()->withRole(Role::Keuangan)->create();

        $this->actingAs($this->ceo)
            ->delete(route('users.destroy', $target))
            ->assertRedirect(route('users.index'));

        $this->assertDatabaseMissing('tbl_users', ['id' => $target->id]);
    }
}
