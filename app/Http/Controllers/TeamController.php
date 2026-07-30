<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TeamController extends Controller
{
    public function index(): View
    {
        return view('team.index', [
            'users' => User::with('role')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('team.create', [
            'roles' => Role::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            'role_id' => 'required|exists:roles,id',
        ]);

        User::create($data);

        return redirect()->route('team.index')->with('status', 'Usuario invitado.');
    }

    public function edit(User $user): View
    {
        return view('team.edit', [
            'member' => $user,
            'roles' => Role::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role_id' => 'required|exists:roles,id',
            'password' => 'nullable|string|min:8',
        ]);

        if ($user->id === $request->user()->id && Role::findOrFail($data['role_id'])->slug !== 'owner') {
            return back()->withErrors(['role_id' => 'No puedes quitarte tu propio rol de Owner.']);
        }

        $user->role_id = $data['role_id'];
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();

        return redirect()->route('team.index')->with('status', 'Usuario actualizado.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['user' => 'No puedes eliminar tu propia cuenta.']);
        }

        if ($user->role?->slug === 'owner' && User::whereHas('role', fn ($q) => $q->where('slug', 'owner'))->count() <= 1) {
            return back()->withErrors(['user' => 'Debe quedar al menos un Owner.']);
        }

        $user->delete();

        return redirect()->route('team.index')->with('status', 'Usuario eliminado.');
    }
}
