<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlPlaneSsoTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_control_plane_token_logs_the_existing_owner_in_once(): void
    {
        $ownerRole = Role::firstOrCreate(['slug' => 'owner'], ['name' => 'Propietario', 'permissions' => ['*']]);
        $owner = User::factory()->create(['email' => 'owner@example.com', 'role_id' => $ownerRole->id]);
        config()->set('xpanel.management_mode', 'vps-instance');
        config()->set('xpanel.sso_enabled', true);
        config()->set('xpanel.instance_id', '550e8400-e29b-41d4-a716-446655440000');
        config()->set('xpanel.broker_secret', str_repeat('a', 64));
        $token = $this->token($owner->email);

        $this->get('/auth/control-plane?token='.$token)->assertRedirect('/');
        $this->assertAuthenticatedAs($owner);

        auth()->logout();
        $this->get('/auth/control-plane?token='.$token)->assertForbidden();
    }

    public function test_token_for_another_instance_is_rejected(): void
    {
        $role = Role::firstOrCreate(['slug' => 'owner'], ['name' => 'Propietario', 'permissions' => ['*']]);
        $owner = User::factory()->create(['role_id' => $role->id]);
        config()->set('xpanel.management_mode', 'vps-instance');
        config()->set('xpanel.sso_enabled', true);
        config()->set('xpanel.instance_id', '550e8400-e29b-41d4-a716-446655440000');
        config()->set('xpanel.broker_secret', str_repeat('a', 64));

        $this->get('/auth/control-plane?token='.$this->token($owner->email, 'wrong-instance'))->assertForbidden();
    }

    private function token(string $email, string $audience = '550e8400-e29b-41d4-a716-446655440000'): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode([
            'iss' => 'https://cloud.example.com', 'aud' => $audience, 'sub' => '1',
            'email' => $email, 'name' => 'Owner', 'iat' => time(), 'exp' => time() + 60,
            'jti' => bin2hex(random_bytes(16)),
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, str_repeat('a', 64), true)), '+/', '-_'), '=');

        return $payload.'.'.$signature;
    }
}
