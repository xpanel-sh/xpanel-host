<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);
    }

    public function test_owner_can_create_a_custom_role(): void
    {
        $response = $this->actingAs($this->owner())->post('/roles', [
            'name' => 'Soporte',
            'permissions' => [Permissions::SITES_VIEW],
        ]);

        $response->assertRedirect('/roles');
        $this->assertDatabaseHas('roles', ['name' => 'Soporte', 'is_system' => false]);

        $role = Role::where('name', 'Soporte')->firstOrFail();
        $this->assertTrue($role->hasPermission(Permissions::SITES_VIEW));
        $this->assertFalse($role->hasPermission(Permissions::TEAM_MANAGE));
    }

    public function test_system_roles_cannot_be_edited_or_deleted(): void
    {
        $owner = $this->owner();
        $viewerRole = Role::where('slug', 'viewer')->firstOrFail();

        $this->actingAs($owner)->put("/roles/{$viewerRole->id}", [
            'name' => 'Viewer hackeado',
            'permissions' => [Permissions::TEAM_MANAGE],
        ])->assertSessionHasErrors('role');

        $this->actingAs($owner)->delete("/roles/{$viewerRole->id}")->assertSessionHasErrors('role');
        $this->assertDatabaseHas('roles', ['id' => $viewerRole->id, 'slug' => 'viewer']);
    }
}
