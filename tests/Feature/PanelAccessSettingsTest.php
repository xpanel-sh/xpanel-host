<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
