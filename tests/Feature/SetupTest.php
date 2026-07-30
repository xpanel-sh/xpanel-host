<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_creates_the_owner_account(): void
    {
        $response = $this->post('/setup', [
            'name' => 'Owner Demo',
            'email' => 'owner@example.com',
            'password' => 'supersecreta',
            'password_confirmation' => 'supersecreta',
        ]);

        $response->assertRedirect('/');
        $this->assertDatabaseHas('users', ['email' => 'owner@example.com']);

        $owner = User::where('email', 'owner@example.com')->firstOrFail();
        $this->assertSame('owner', $owner->role->slug);
    }

    public function test_setup_is_blocked_once_a_user_exists(): void
    {
        User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);

        $this->get('/setup')->assertRedirect('/login');

        $response = $this->post('/setup', [
            'name' => 'Otro',
            'email' => 'otro@example.com',
            'password' => 'supersecreta',
            'password_confirmation' => 'supersecreta',
        ]);

        $response->assertRedirect('/login');
        $this->assertDatabaseMissing('users', ['email' => 'otro@example.com']);
    }
}
