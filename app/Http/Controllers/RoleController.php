<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function index(): View
    {
        return view('roles.index', [
            'roles' => Role::withCount('users')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('roles.create', [
            'permissions' => Permissions::all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        Role::create([
            'name' => $data['name'],
            'slug' => Role::makeSlug($data['name']).'-'.uniqid(),
            'permissions' => $data['permissions'],
        ]);

        return redirect()->route('roles.index')->with('status', 'Rol creado.');
    }

    public function edit(Role $role): View
    {
        return view('roles.edit', [
            'role' => $role,
            'permissions' => Permissions::all(),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        if ($role->is_system) {
            return back()->withErrors(['role' => 'Los roles del sistema no se pueden editar.']);
        }

        $data = $this->validated($request);
        $role->update([
            'name' => $data['name'],
            'permissions' => $data['permissions'],
        ]);

        return redirect()->route('roles.index')->with('status', 'Rol actualizado.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->is_system) {
            return back()->withErrors(['role' => 'Los roles del sistema no se pueden eliminar.']);
        }
        if ($role->users()->count() > 0) {
            return back()->withErrors(['role' => 'Reasigna a los usuarios de este rol antes de eliminarlo.']);
        }

        $role->delete();

        return redirect()->route('roles.index')->with('status', 'Rol eliminado.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'permissions' => 'array',
            'permissions.*' => 'string|in:'.implode(',', array_keys(Permissions::all())),
        ]);

        $data['permissions'] = array_values($data['permissions'] ?? []);

        return $data;
    }
}
