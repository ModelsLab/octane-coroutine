<?php

namespace Tests\Unit;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;
use Laravel\Octane\Swoole\Coroutine\RequestScope;
use PHPUnit\Framework\TestCase;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Tenancy;

class RequestScopeTenancyIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(Tenancy::class)) {
            $this->markTestSkipped('stancl/tenancy is not installed.');
        }
    }

    public function test_tenant_contract_reads_from_request_scoped_tenancy(): void
    {
        $base = new Application(__DIR__);
        $baseTenancy = new Tenancy;
        $base->singleton(Tenancy::class, fn () => $baseTenancy);
        $base->bind(Tenant::class, fn ($app) => $app[Tenancy::class]->tenant);

        $sandbox = new CoroutineApplication($base);
        $scope = new RequestScope($base, Request::create('/'));
        Context::set('octane.request_scope', $scope);

        try {
            $scopedTenancy = $sandbox->make(Tenancy::class);
            $this->assertNotSame($baseTenancy, $scopedTenancy);
            $this->assertFalse($scopedTenancy->initialized);
            $this->assertNull($sandbox->make(Tenant::class));

            $tenant = new class implements Tenant
            {
                public function getTenantKeyName(): string
                {
                    return 'id';
                }

                public function getTenantKey()
                {
                    return 7;
                }

                public function getInternal(string $key)
                {
                    return null;
                }

                public function setInternal(string $key, $value)
                {
                    return $this;
                }

                public function run(callable $callback)
                {
                    return $callback($this);
                }
            };

            $scopedTenancy->tenant = $tenant;
            $scopedTenancy->initialized = true;

            $this->assertSame($tenant, $sandbox->make(Tenant::class));
            $this->assertNull($baseTenancy->tenant);
        } finally {
            Context::clear();
        }
    }
}
