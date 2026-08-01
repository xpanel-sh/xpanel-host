<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Role;
use App\Models\ServerIpAddress;
use App\Models\User;
use App\Services\MailDnsService;
use App\Services\ServerCommandRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DomainMailOutboundTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $slug)->firstOrFail()->id]);
    }

    private function domain(): Domain
    {
        return Domain::create(['domain' => 'outbound-test-'.uniqid().'.example.com', 'type' => 'primary']);
    }

    public function test_developer_can_register_and_remove_a_dedicated_ip(): void
    {
        $developer = $this->userWithRole('developer');

        $this->actingAs($developer)->post('/server-ip-addresses', [
            'ip_address' => '203.0.113.10',
            'ptr_hostname' => 'mail.cliente.com',
            'label' => 'Cliente X',
        ])->assertRedirect();
        $this->assertDatabaseHas('server_ip_addresses', ['ip_address' => '203.0.113.10']);

        $ip = ServerIpAddress::where('ip_address', '203.0.113.10')->firstOrFail();
        $this->actingAs($developer)->delete("/server-ip-addresses/{$ip->id}")->assertRedirect();
        $this->assertDatabaseMissing('server_ip_addresses', ['id' => $ip->id]);
    }

    public function test_dedicated_ip_cannot_be_deleted_while_a_domain_uses_it(): void
    {
        $domain = $this->domain();
        $ip = ServerIpAddress::create(['ip_address' => '203.0.113.20', 'ptr_hostname' => 'mail.otro.com']);
        $domain->mailSettings()->create(['outbound_mode' => 'dedicated', 'server_ip_address_id' => $ip->id]);

        $this->actingAs($this->userWithRole('developer'))
            ->delete("/server-ip-addresses/{$ip->id}")
            ->assertSessionHasErrors('server');
        $this->assertDatabaseHas('server_ip_addresses', ['id' => $ip->id]);
    }

    public function test_setting_a_domain_to_dedicated_mode_stages_sender_transport_and_syncs(): void
    {
        config()->set('xpanel.apply_system_changes', true);
        config()->set('xpanel.site_helper', '/opt/xpanel-host/scripts/xpanel-site-helper.sh');
        $domain = $this->domain();
        $ip = ServerIpAddress::create(['ip_address' => '203.0.113.30', 'ptr_hostname' => 'mail.dedicada.com']);

        $runner = Mockery::mock(ServerCommandRunner::class);
        $runner->shouldReceive('run')->once()->with([
            'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'mail-outbound-sync',
        ])->andReturn('');
        $this->app->instance(ServerCommandRunner::class, $runner);

        $this->actingAs($this->userWithRole('developer'))
            ->put("/mail/domains/{$domain->domain}/settings", [
                'outbound_mode' => 'dedicated',
                'server_ip_address_id' => $ip->id,
            ])->assertRedirect();

        $this->assertDatabaseHas('domain_mail_settings', [
            'domain_id' => $domain->id,
            'outbound_mode' => 'dedicated',
            'server_ip_address_id' => $ip->id,
        ]);
        $senderTransport = file_get_contents(storage_path('app/mail/sender-transport'));
        $this->assertStringContainsString('@'.$domain->domain.' xpanelout-203-0-113-30:', $senderTransport);
        $dedicatedIps = file_get_contents(storage_path('app/mail/dedicated-ips'));
        $this->assertStringContainsString('xpanelout-203-0-113-30 203.0.113.30 mail.dedicada.com', $dedicatedIps);
    }

    public function test_mail_dns_service_uses_dedicated_ip_when_domain_is_in_dedicated_mode(): void
    {
        config()->set('xpanel.mail_hostname', 'mail.shared.example.com');
        config()->set('xpanel.server_ipv4', '198.51.100.1');
        $domain = $this->domain();
        $ip = ServerIpAddress::create(['ip_address' => '203.0.113.40', 'ptr_hostname' => 'mail.propia.com']);
        $domain->mailSettings()->create(['outbound_mode' => 'dedicated', 'server_ip_address_id' => $ip->id]);

        $expected = app(MailDnsService::class)->expected($domain->fresh());

        $this->assertSame('mail.propia.com', $expected['mail_hostname']);
        $this->assertSame('203.0.113.40', $expected['server_ipv4']);
    }

    public function test_mail_dns_service_uses_shared_settings_when_domain_has_no_dedicated_ip(): void
    {
        config()->set('xpanel.mail_hostname', 'mail.shared.example.com');
        config()->set('xpanel.server_ipv4', '198.51.100.1');
        $domain = $this->domain();

        $expected = app(MailDnsService::class)->expected($domain);

        $this->assertSame('mail.shared.example.com', $expected['mail_hostname']);
        $this->assertSame('198.51.100.1', $expected['server_ipv4']);
    }
}
