<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Laravel\Octane\Swoole\Database\MySqlStringBindingConnection;
use Tests\TestCase;

class MySqlStringBindingResolverTest extends TestCase
{
    public function test_mysql_connections_resolve_to_the_string_binding_class(): void
    {
        $resolver = Connection::getResolver('mysql');

        $this->assertNotNull($resolver, 'The provider must register a mysql connection resolver.');

        $connection = $resolver(new \stdClass(), 'db', '', ['driver' => 'mysql']);

        $this->assertInstanceOf(MySqlStringBindingConnection::class, $connection);
    }
}
