<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $slug)->firstOrFail()->id]);
    }

    public function test_owner_can_invite_a_user(): void
    {
        $owner = $this->userWithRole('owner');
        $developer = Role::where('slug', 'developer')->firstOrFail();

        $response = $this->actingAs($owner)->post('/team', [
            'name' => 'Colaborador',
            'email' => 'colaborador@example.com',
            'password' => 'supersecreta',
            'role_id' => $developer->id,
        ]);

        $response->assertRedirect('/team');
        $this->assertDatabaseHas('users', ['email' => 'colaborador@example.com', 'role_id' => $developer->id]);
    }

    public function test_developer_cannot_manage_team(): void
    {
        $developer = $this->userWithRole('developer');

        $this->actingAs($developer)->get('/team')->assertForbidden();
    }

    public function test_the_last_owner_cannot_be_deleted(): void
    {
        $owner = $this->userWithRole('owner');

        $this->actingAs($owner)->delete("/team/{$owner->id}")->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
    }
}
