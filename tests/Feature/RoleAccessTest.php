<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PDOException;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use CreatesSjiAuthTables, RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('ceo.dashboard'))->assertRedirect(route('login'));
        $this->get(route('users.index'))->assertRedirect(route('login'));
    }

    public function test_non_ceo_cannot_open_ceo_dashboard(): void
    {
        $hrd = User::factory()->withRole(Role::Hrd)->create();

        $this->actingAs($hrd)->get(route('ceo.dashboard'))->assertForbidden();
    }

    public function test_operasional_and_keuangan_cannot_open_user_management(): void
    {
        foreach ([Role::Operasional, Role::Keuangan, Role::KaryawanLapangan] as $namaRole) {
            $user = User::factory()->withRole($namaRole)->create();

            $this->actingAs($user)->get(route('users.index'))->assertForbidden();
        }
    }

    public function test_deactivated_user_is_logged_out_on_next_request(): void
    {
        $ceo = User::factory()->withRole(Role::Ceo)->nonaktif()->create();

        $this->actingAs($ceo)->get(route('ceo.dashboard'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    /**
     * Pada SQLite tes, view/function MySQL tidak ada; dashboard tetap tampil dengan pesan ramah, bukan error 500.
     */
    public function test_ceo_dashboard_shows_friendly_message_when_database_fails(): void
    {
        $ceo = User::factory()->withRole(Role::Ceo)->create();

        $this->actingAs($ceo)
            ->get(route('ceo.dashboard'))
            ->assertOk()
            ->assertSee('Dashboard Eksekutif')
            ->assertSee('Terjadi kesalahan pada database');
    }

    public function test_sqlstate_45000_is_shown_as_flash_message(): void
    {
        Route::middleware('web')->post('/_tes-signal', function () {
            $pdoException = new PDOException('SQLSTATE[45000]: <<Unknown error>>: 1644 Invoice periode ini sudah ada');
            $pdoException->errorInfo = ['45000', 1644, 'Invoice periode ini sudah ada'];

            throw new QueryException('mysql', 'CALL sp_generate_invoice_klien(?, ?, ?)', [1, 10, 2026], $pdoException);
        });

        $user = User::factory()->withRole(Role::Keuangan)->create();

        $this->actingAs($user)
            ->from(route('beranda'))
            ->post('/_tes-signal')
            ->assertRedirect(route('beranda'))
            ->assertSessionHas('error', 'Invoice periode ini sudah ada');
    }
}
