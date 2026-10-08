<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\MailAccount;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteDatabase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HostingPlanQuotaTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);
    }

    private function site(string $domain): Site
    {
        return Site::create([
            'domain' => $domain,
            'document_root' => '/var/www/'.$domain,
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'nginx',
            'status' => 'active',
        ]);
    }

    public function test_managed_site_quota_counts_parent_sites_and_subdomains(): void
    {
        config()->set('xpanel.management_mode', 'vps-instance');
        config()->set('xpanel.assigned_max_sites', 1);
        $site = $this->site('example.test');

        $this->actingAs($this->owner())->post('/sites', [
            'domain' => 'another.test', 'php_version' => '8.3', 'type' => 'php', 'status' => 'active',
        ])->assertSessionHasErrors('server');
        $this->assertDatabaseCount('sites', 1);

        $this->actingAs($this->owner())->post(route('sites.subdomains.store', $site), [
            'label' => 'app',
        ])->assertSessionHasErrors('server');
        $this->assertDatabaseCount('sites', 1);
    }

    public function test_managed_database_quota_is_account_wide(): void
    {
        config()->set('xpanel.management_mode', 'vps-instance');
        config()->set('xpanel.assigned_max_databases', 1);
        $first = $this->site('first.test');
        $second = $this->site('second.test');
        SiteDatabase::create(['site_id' => $first->id, 'name' => 'existing', 'username' => 'user', 'status' => 'active']);

        $this->actingAs($this->owner())->post(route('sites.databases.store', $second), [
            'name' => 'another', 'username' => 'another', 'password' => 'Strong-Database_2026!',
        ])->assertSessionHasErrors('server');
        $this->assertDatabaseCount('site_databases', 1);
    }

    public function test_managed_mailbox_quota_is_account_wide(): void
    {
        config()->set('xpanel.management_mode', 'vps-instance');
        config()->set('xpanel.assigned_email_accounts', 1);
        $site = $this->site('mail.test');
        $domain = Domain::create(['domain' => 'mail.test', 'type' => 'primary', 'site_id' => $site->id]);
        MailAccount::create(['domain_id' => $domain->id, 'local_part' => 'first', 'password' => 'long-password-123', 'quota_mb' => 128, 'status' => 'active']);

        $this->actingAs($this->owner())->post(route('mail.store'), [
            'domain_id' => $domain->id, 'local_part' => 'second', 'password' => 'long-password-123', 'quota_mb' => 128,
        ])->assertSessionHasErrors('server');
        $this->assertDatabaseCount('mail_accounts', 1);
    }

    public function test_standalone_host_does_not_inherit_managed_quotas(): void
    {
        config()->set('xpanel.management_mode', 'standalone');
        config()->set('xpanel.assigned_max_sites', 1);
        $this->site('existing.test');
        app(\App\Services\HostingPlanQuota::class)->assertCanCreateSite();
        $this->assertSame(1, Site::count());
    }

    public function test_managed_host_does_not_silently_ignore_unsynchronized_quota(): void
    {
        config()->set('xpanel.management_mode', 'vps-instance');
        config()->set('xpanel.assigned_max_databases', null);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Services\HostingPlanQuota::class)->assertCanCreateDatabase();
    }
}
