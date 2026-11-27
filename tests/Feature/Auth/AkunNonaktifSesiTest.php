<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

/**
 * Akun yang dinonaktifkan saat masih login diblokir di semua route setelah login (middleware password.changed),
 * termasuk beranda dan berkas bersama, tanpa mengganggu login, logout, dan ganti password.
 */
class AkunNonaktifSesiTest extends TestCase
{
    use CreatesSjiAuthTables;

    public function test_active_user_can_still_open_beranda(): void
    {
        $this->actingAs(User::factory()->withRole(Role::Hrd)->create())
            ->get(route('beranda'))
            ->assertOk();
    }

    public function test_user_deactivated_during_a_session_is_logged_out_from_beranda(): void
    {
        $user = User::factory()->withRole(Role::Hrd)->create();
        $this->actingAs($user)->get(route('beranda'))->assertOk();

        $user->update(['status' => User::StatusNonaktif]);

        $this->get(route('beranda'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Akun Anda sudah dinonaktifkan. Hubungi HRD.');
        $this->assertGuest();
    }

    public function test_deactivated_session_cannot_open_shared_files(): void
    {
        Schema::create('tbl_karyawan', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('foto')->nullable();
        });
        $idKaryawan = (int) DB::table('tbl_karyawan')->insertGetId(['foto' => 'foto-karyawan/andi.jpg']);
        $user = User::factory()->withRole(Role::KaryawanLapangan)->create(['id_karyawan' => $idKaryawan, 'status' => User::StatusNonaktif]);

        $this->actingAs($user)
            ->get(route('karyawan.foto', $idKaryawan))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_deactivated_session_can_still_log_out(): void
    {
        $user = User::factory()->withRole(Role::Hrd)->create(['status' => User::StatusNonaktif]);

        $this->actingAs($user)->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_forced_password_change_flow_is_unchanged_for_active_users(): void
    {
        $user = User::factory()->withRole(Role::Hrd)->create(['must_change_password' => true]);

        $this->actingAs($user)->get(route('beranda'))->assertRedirect(route('password.change'));
        $this->actingAs($user)->get(route('password.change'))->assertOk();
    }
}
