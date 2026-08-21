<?php

namespace Tests\Unit;

use App\Support\InstanceContext;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class InstanceContextTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('XPANEL_INSTANCE_ROOT');
        parent::tearDown();
    }

    public function test_it_keeps_standalone_installations_on_the_default_storage_path(): void
    {
        putenv('XPANEL_INSTANCE_ROOT');

        $this->assertNull(InstanceContext::storagePathFromEnvironment());
    }

    public function test_it_resolves_a_managed_instance_storage_path(): void
    {
        putenv('XPANEL_INSTANCE_ROOT=/var/lib/xpanel-vps/instances/01234567-89ab-cdef-0123-456789abcdef');

        $this->assertSame(
            '/var/lib/xpanel-vps/instances/01234567-89ab-cdef-0123-456789abcdef/storage',
            InstanceContext::storagePathFromEnvironment(),
        );
    }

    public function test_it_rejects_roots_outside_the_control_plane_directory(): void
    {
        putenv('XPANEL_INSTANCE_ROOT=/var/www/another-customer');
        $this->expectException(InvalidArgumentException::class);

        InstanceContext::storagePathFromEnvironment();
    }
}
