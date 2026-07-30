<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PanelAccessSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_server_managers_can_open_panel_access_settings(): void
    {
        $owner = User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);
        $developer = User::factory()->create(['role_id' => Role::where('slug', 'developer')->firstOrFail()->id]);

        $this->actingAs($owner)->get(route('settings.panel-access.index'))->assertOk();
        $this->actingAs($developer)->get(route('settings.panel-access.index'))->assertForbidden();
    }

    public function test_bootstrap_status_reports_when_the_initial_owner_is_missing(): void
    {
        $this->artisan('xpanel:admin-bootstrap', ['--status-only' => true])
            ->expectsOutput('missing')
            ->assertSuccessful();
    }

    public function test_owner_password_can_be_recovered_from_the_server_console(): void
    {
        $owner = User::factory()->create([
            'role_id' => Role::where('slug', 'owner')->firstOrFail()->id,
            'password' => 'previous-password-value',
        ]);

        $this->artisan('xpanel:admin-password', ['--generate' => true])->assertSuccessful();

        $this->assertFalse(Hash::check('previous-password-value', $owner->fresh()->password));
    }
}
