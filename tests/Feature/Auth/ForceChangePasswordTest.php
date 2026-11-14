<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

class ForceChangePasswordTest extends TestCase
{
    use CreatesSjiAuthTables, RefreshDatabase;

    public function test_login_redirects_to_change_password_when_flag_is_set(): void
    {
        User::factory()->withRole(Role::Hrd)->mustChangePassword()->create(['username' => 'hrd01']);

        $this->post('/login', ['username' => 'hrd01', 'password' => 'password'])
            ->assertRedirect(route('password.change'));
    }

    public function test_other_menus_are_blocked_until_password_is_changed(): void
    {
        $user = User::factory()->withRole(Role::Hrd)->mustChangePassword()->create();

        $this->actingAs($user)->get(route('users.index'))->assertRedirect(route('password.change'));
        $this->actingAs($user)->get(route('beranda'))->assertRedirect(route('password.change'));
        $this->actingAs($user)->get(route('password.change'))->assertOk();
    }

    public function test_user_can_change_password_and_flag_is_cleared(): void
    {
        $user = User::factory()->withRole(Role::Hrd)->mustChangePassword()->create();

        $this->actingAs($user)
            ->put(route('password.change.update'), [
                'current_password' => 'password',
                'password' => 'RahasiaBaru123',
                'password_confirmation' => 'RahasiaBaru123',
            ])
            ->assertRedirect(route('beranda'))
            ->assertSessionHas('success');

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('RahasiaBaru123', $user->password));

        $this->actingAs($user)->get(route('users.index'))->assertOk();
    }

    public function test_change_password_rejects_wrong_current_password(): void
    {
        $user = User::factory()->withRole(Role::Hrd)->mustChangePassword()->create();

        $this->actingAs($user)
            ->put(route('password.change.update'), [
                'current_password' => 'keliru',
                'password' => 'RahasiaBaru123',
                'password_confirmation' => 'RahasiaBaru123',
            ])
            ->assertSessionHasErrors(['current_password' => 'Password lama tidak sesuai.']);

        $this->assertTrue($user->refresh()->must_change_password);
    }

    public function test_new_password_must_differ_from_current(): void
    {
        $user = User::factory()->withRole(Role::Hrd)->mustChangePassword()->create();

        $this->actingAs($user)
            ->put(route('password.change.update'), [
                'current_password' => 'password',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertSessionHasErrors('password');
    }
}
