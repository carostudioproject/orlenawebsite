<?php

namespace App\Console\Commands;

use App\Actions\Users\SaveUser;
use App\Models\User;
use App\Support\AccountRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'orlena:create-admin {--local-preview : Generate a first local-only account and save credentials in private storage}';

    protected $description = 'Create an administrator using a hidden password prompt; never seeds a default password';

    public function handle(SaveUser $action): int
    {
        if ($this->option('local-preview')) {
            if (! app()->isLocal() || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true) || User::exists()) {
                $this->error('Local preview provisioning requires a local environment and no existing users.');

                return self::FAILURE;
            }
            $path = storage_path('app/private/admin-initial-access.json');
            if (file_exists($path)) {
                $this->error('Credential file already exists; it will not be overwritten.');

                return self::FAILURE;
            }
            $data = ['name' => 'Admin Orlena', 'username' => 'admin', 'email' => 'admin@orlena.test', 'password' => bin2hex(random_bytes(16)).'A9'];
            // Write before creating the account so a filesystem failure cannot lose the generated password.
            if (file_put_contents($path, json_encode([...$data, 'url' => url('/admin/login')], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) {
                $this->error('Cannot write private credentials.');

                return self::FAILURE;
            }
            $action->handle([...$data, 'role' => 'admin', 'is_active' => true]);
            $this->info('Local admin created. Credentials: storage/app/private/admin-initial-access.json');

            return self::SUCCESS;
        }
        $data = [
            'name' => $this->ask('Nama admin'), ...AccountRules::normalize(['username' => $this->ask('Username untuk login'), 'email' => $this->ask('Email admin (opsional)')]),
            'password' => $this->secret('Kata sandi (minimal 12 karakter, huruf dan angka)'),
        ];
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:160'], 'username' => AccountRules::username(), 'email' => AccountRules::email(),
            'password' => ['required', 'max:128', AccountRules::password()],
        ], AccountRules::MESSAGES);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $action->handle([...$data, 'role' => 'admin', 'is_active' => true]);
        $this->info('Admin dibuat. Login melalui /admin/login dengan username '.$data['username'].'.');

        return self::SUCCESS;
    }
}
