<?php

namespace Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private ?string $isolatedStorage = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolatedStorage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'xpanel-host-tests'.DIRECTORY_SEPARATOR.bin2hex(random_bytes(8));
        mkdir($this->isolatedStorage, 0700, true);
        $this->app->useStoragePath($this->isolatedStorage);
    }

    protected function tearDown(): void
    {
        $storage = $this->isolatedStorage;
        parent::tearDown();

        if ($storage !== null && is_dir($storage)) {
            (new Filesystem)->deleteDirectory($storage);
        }
    }
}
