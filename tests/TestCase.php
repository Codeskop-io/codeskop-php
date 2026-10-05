<?php

declare(strict_types=1);

namespace Codeskop\Tests;

use Codeskop\Client;
use Codeskop\Codeskop;
use Codeskop\Tests\Support\FakeTransport;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected FakeTransport $t;
    protected string $cacheDir;

    protected function setUp(): void
    {
        $this->t = new FakeTransport();
        $this->cacheDir = sys_get_temp_dir() . '/codeskop-test-' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir);
    }

    protected function tearDown(): void
    {
        restore_exception_handler();
        array_map('unlink', glob($this->cacheDir . '/*') ?: []);
        @rmdir($this->cacheDir);
    }

    protected function client(array $opts = []): Client
    {
        return Codeskop::init($opts + ['api_key' => 'cs_test_pk_testtesttest', 'endpoint' => 'https://ingest.test', 'transport' => $this->t, 'cache_dir' => $this->cacheDir, 'auto_request' => false]);
    }
}
