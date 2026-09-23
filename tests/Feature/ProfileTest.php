<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.connections.mysql.database') !== 'orlena_test') {
            throw new \RuntimeException('Only orlena_test is allowed.');
        }
    }

    public function test_every_role_can_edit_their_own_profile_but_not_role_or_status(): void
    {
        $this->get('/admin/profile')->assertRedirect('/admin/login');
        foreach (['admin', 'staff', 'finance', 'content_editor'] as $role) {
            $user = User::factory()->create(['role' => $role, 'username' => 'user.'.$role]);
            $this->actingAs($user)->get('/admin/profile')->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Admin/Profile')->where('profile.username', 'user.'.$role)->missing('profile.password'));
        }

        $other = User::factory()->create(['username' => 'taken']);
        $user = User::factory()->create(['role' => 'staff', 'username' => 'kasir']);
        $this->actingAs($user);
        $this->put('/admin/profile', ['name' => 'Kasir', 'username' => 'taken', 'email' => ''])->assertSessionHasErrors('username');
        $this->put('/admin/profile', ['name' => 'Kasir', 'username' => 'ab', 'email' => ''])->assertSessionHasErrors('username');
        $this->put('/admin/profile', ['name' => 'Kasir Baru', 'username' => ' Kasir.Baru ', 'email' => 'KASIR@example.test', 'role' => 'admin', 'is_active' => false])->assertSessionHasNoErrors();
        $fresh = $user->fresh();
        $this->assertSame(['Kasir Baru', 'kasir.baru', 'kasir@example.test', 'staff', true], [$fresh->name, $fresh->username, $fresh->email, $fresh->role->value, $fresh->is_active]);
        $this->assertStringNotContainsString('kasir@example.test', DB::table('audit_logs')->where('action', 'profile.updated')->value('changes'));
        $this->assertSame('taken', $other->fresh()->username);

        // The new username signs in; the old one no longer does.
        $this->post('/admin/logout');
        $this->post('/admin/login', ['username' => 'kasir', 'password' => 'password'])->assertSessionHasErrors('username');
        $this->post('/admin/login', ['username' => 'kasir.baru', 'password' => 'password'])->assertRedirect('/admin');
    }

    public function test_password_change_needs_the_current_password_and_signs_out_other_devices(): void
    {
        $user = User::factory()->create(['username' => 'staff']);
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($user);
        $this->put('/admin/profile/password', ['current_password' => 'wrong', 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123'])->assertSessionHasErrors('current_password');
        $this->put('/admin/profile/password', ['current_password' => 'password', 'password' => 'short1', 'password_confirmation' => 'short1'])->assertSessionHasErrors('password');
        $this->put('/admin/profile/password', ['current_password' => 'password', 'password' => 'NewPassword123', 'password_confirmation' => 'Mismatch123456'])->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));

        $this->put('/admin/profile/password', ['current_password' => 'password', 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123'])
            ->assertRedirect('/admin/profile')->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('NewPassword123', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        $this->assertAuthenticatedAs($user);
        $this->assertStringNotContainsString('NewPassword123', json_encode(DB::table('audit_logs')->get()));
    }
}
