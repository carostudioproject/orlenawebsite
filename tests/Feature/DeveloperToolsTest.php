<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DeveloperToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.connections.mysql.database') !== 'orlena_test') {
            throw new \RuntimeException('Only orlena_test is allowed.');
        }
    }

    public function test_only_developers_open_developer_tools_and_secrets_stay_hidden(): void
    {
        config(['services.erzap.token' => 'erzap-secret-token', 'services.doku.secret_key' => 'doku-secret-key', 'services.doku.client_id' => 'BRN-1',
            'services.doku.snap_private_key_path' => base_path('tests/fixtures/doku-test-private.pem')]);
        foreach (['admin', 'staff', 'finance', 'content_editor'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/admin/system')->assertForbidden();
        }

        $developer = User::factory()->create(['role' => 'developer']);
        $this->actingAs($developer)->get('/admin/system')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/System')
                ->where('auth.can.system', true)->where('auth.can.users', true)->where('auth.can.integrations', true)
                ->where('erzap.token', true)->where('payments.secret', true)->where('payments.privateKey', true)
                ->where('payments.publicKey', fn ($key) => str_starts_with($key, '-----BEGIN PUBLIC KEY-----'))
                ->where('payments.webhooks.qris', url('/webhooks/doku-qris')))
            ->assertDontSee('erzap-secret-token')->assertDontSee('doku-secret-key')->assertDontSee('PRIVATE KEY');
    }

    public function test_developers_have_full_dashboard_access_and_can_run_tasks(): void
    {
        $developer = User::factory()->create(['role' => 'developer']);
        $this->actingAs($developer);
        foreach (['/admin/orders', '/admin/products', '/admin/content', '/admin/users', '/admin/reports', '/admin/integrations', '/admin/schedule'] as $path) {
            $this->get($path)->assertOk();
        }
        $this->post('/admin/system/run', ['task' => 'erzap-sync'])->assertSessionHas('success');
        $this->post('/admin/system/run', ['task' => 'rm -rf'])->assertSessionHasErrors('task');
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.task_run', 'actor_id' => $developer->id]);

        // Admins (owner) can create developer accounts.
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/admin/users', [
            'name' => 'Dev Two', 'username' => 'devtwo', 'email' => 'dev2@example.test', 'role' => 'developer', 'is_active' => true,
            'password' => 'Strong-Password-123', 'password_confirmation' => 'Strong-Password-123',
        ])->assertSessionHasNoErrors();
        $this->assertSame('developer', User::where('username', 'devtwo')->sole()->role->value);
    }
}
