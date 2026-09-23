<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AccountRules;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** Every signed-in user manages their own name, username, email, and password; role and status stay with Admin. */
class ProfileController extends Controller
{
    public function edit(Request $request)
    {
        return Inertia::render('Admin/Profile', ['profile' => $request->user()->only('name', 'username', 'email', 'role')]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $request->merge(AccountRules::normalize($request->all()));
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'], 'username' => AccountRules::username($user->id), 'email' => AccountRules::email($user->id),
        ], AccountRules::MESSAGES);
        $user->fill($data);
        $changes = array_keys($user->getDirty());
        $user->save();
        // Field names only; values such as email stay out of the audit log.
        if ($changes) {
            Audit::record('profile.updated', $user, ['fields' => $changes], $user->id);
        }

        return redirect('/admin/profile')->with('success', 'Profile updated.');
    }

    public function updatePassword(Request $request)
    {
        $user = $request->user();
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'max:128', 'different:current_password', AccountRules::password()],
        ], [
            'current_password.current_password' => 'The current password is incorrect.', 'current_password.required' => 'Enter your current password.',
            'password.different' => 'The new password must differ from the current one.', 'password.confirmed' => 'The password confirmation does not match.',
        ]);
        $user->forceFill(['password' => $request->input('password')])->save();
        // Sign out every other device; this browser stays signed in with a fresh session id.
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        $request->session()->regenerate();
        Audit::record('profile.password_changed', $user, [], $user->id);

        return redirect('/admin/profile')->with('success', 'Password updated. Sessions on other devices were logged out.');
    }
}
