<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeneralSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_override_the_managed_host_identity(): void
    {
        config()->set('xpanel.management_mode', 'vps-instance');
        config()->set('xpanel.server_ipv4', '203.0.113.10');
        config()->set('app.name', 'Cloud Hosting');
        $owner = User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);

        $this->actingAs($owner)->get(route('settings.general.index'))
            ->assertOk()->assertSee('Cloud Hosting')->assertSee('203.0.113.10');

        $this->actingAs($owner)->put(route('settings.general.update'), ['display_name' => 'DoolPool Hosting'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('DoolPool Hosting', SystemSetting::get('display_name'));
        $this->actingAs($owner)->get(route('settings.general.index'))->assertSee('DoolPool Hosting');
    }

    public function test_non_server_manager_cannot_change_identity(): void
    {
        $developer = User::factory()->create(['role_id' => Role::where('slug', 'developer')->firstOrFail()->id]);
        $this->actingAs($developer)->put(route('settings.general.update'), ['display_name' => 'No permitido'])->assertForbidden();
    }
}
