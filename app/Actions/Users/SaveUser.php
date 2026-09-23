<?php

namespace App\Actions\Users;

use App\Enums\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveUser
{
    public function handle(array $data, ?int $id = null): User
    {
        return DB::transaction(function () use ($data, $id) {
            // Lock in stable order so concurrent admin changes cannot remove all administrators.
            $admins = User::where('role', Role::Admin->value)->where('is_active', true)->orderBy('id')->lockForUpdate()->get();
            $user = $id ? User::lockForUpdate()->findOrFail($id) : new User;
            if ($id && $admins->contains('id', $id) && $admins->count() === 1 && ($data['role'] !== Role::Admin->value || ! $data['is_active'])) {
                throw ValidationException::withMessages(['role' => 'At least one active Admin must remain.']);
            }
            if (empty($data['password'])) {
                unset($data['password']);
            }
            $user->forceFill($data);
            $changes = $user->getDirty();
            $user->save();
            if ($id && (isset($data['password']) || ! $data['is_active'])) {
                DB::table('sessions')->where('user_id', $id)->delete();
            }
            Audit::record($id ? 'user.updated' : 'user.created', $user, array_intersect_key($changes, array_flip(['role', 'is_active', 'username'])));

            return $user;
        });
    }
}
