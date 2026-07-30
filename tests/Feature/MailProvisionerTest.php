<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\MailAccount;
use App\Services\MailProvisioner;
use App\Services\ServerCommandRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class MailProvisionerTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_mode_syncs_postfix_and_dovecot_maps_without_plaintext_passwords(): void
    {
        config()->set('xpanel.apply_system_changes', true);
        config()->set('xpanel.site_helper', '/opt/xpanel-host/scripts/xpanel-site-helper.sh');
        $domain = Domain::create(['domain' => 'mail.example.com']);
        $account = MailAccount::create([
            'domain_id' => $domain->id,
            'local_part' => 'ventas',
            'password' => 'Mail-Password-That-Must-Not-Leak',
            'quota_mb' => 2048,
            'status' => 'provisioning',
        ]);

        $runner = Mockery::mock(ServerCommandRunner::class);
        $runner->shouldReceive('run')->once()->with([
            'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'mail-sync',
        ])->andReturn('');

        (new MailProvisioner($runner))->apply($account);

        $users = file_get_contents(storage_path('app/mail/users'));
        $this->assertStringContainsString('ventas@mail.example.com:{BLF-CRYPT}$2y$', $users);
        $this->assertStringContainsString('userdb_quota_rule=*:storage=2048M', $users);
        $this->assertStringNotContainsString('Mail-Password-That-Must-Not-Leak', $users);
        $this->assertStringContainsString('postmaster@mail.example.com ventas@mail.example.com', file_get_contents(storage_path('app/mail/aliases')));
        $privateKey = file_get_contents(storage_path('app/mail/dkim/mail.example.com.private'));
        $publicRecord = file_get_contents(storage_path('app/mail/dkim/mail.example.com.txt'));
        $this->assertStringContainsString('BEGIN PRIVATE KEY', $privateKey);
        $this->assertMatchesRegularExpression('/^v=DKIM1; k=rsa; p=[A-Za-z0-9+\/=]+\n$/', $publicRecord);
        $this->assertStringNotContainsString('PRIVATE KEY', $publicRecord);
        $this->assertSame("xpanel\n", file_get_contents(storage_path('app/mail/dkim-selector')));
        $this->assertSame('active', $account->fresh()->status);
    }

    public function test_mail_sync_command_rebuilds_maps_and_updates_account_statuses(): void
    {
        config()->set('xpanel.apply_system_changes', false);
        $domain = Domain::create(['domain' => 'restore.example.com']);
        $account = MailAccount::create([
            'domain_id' => $domain->id,
            'local_part' => 'admin',
            'password' => 'A-Restored-Mail-Password',
            'quota_mb' => 1024,
            'status' => 'provisioning',
        ]);

        $this->artisan('xpanel:mail-sync')
            ->expectsOutput('Mail configuration synchronized.')
            ->assertSuccessful();

        $this->assertSame('staged', $account->fresh()->status);
        $this->assertStringContainsString(
            'admin@restore.example.com',
            file_get_contents(storage_path('app/mail/users')),
        );
    }
}
