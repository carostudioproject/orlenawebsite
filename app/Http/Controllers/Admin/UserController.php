<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Users\SaveUser;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'role' => ['nullable', Rule::enum(Role::class)]]);
        $users = User::select('id', 'name', 'username', 'email', 'role', 'is_active')
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')
                ->orWhere('username', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->orderBy('name')->paginate(10)->withQueryString();

        return Inertia::render('Admin/Users/Index', ['users' => $users, 'filters' => $filters]);
    }

    public function create()
    {
        return Inertia::render('Admin/Users/Form', ['record' => null]);
    }

    public function edit(User $user)
    {
        return Inertia::render('Admin/Users/Form', ['record' => $user->only('id', 'name', 'username', 'email', 'role', 'is_active', 'erzap_sales_user_id')]);
    }

    public function store(UserRequest $request, SaveUser $action)
    {
        $action->handle($request->safe()->except('password_confirmation'));

        return redirect('/admin/users')->with('success', 'Account created.');
    }

    public function update(UserRequest $request, User $user, SaveUser $action)
    {
        $action->handle($request->safe()->except('password_confirmation'), $user->id);

        return redirect('/admin/users')->with('success', 'Account updated.');
    }
}
