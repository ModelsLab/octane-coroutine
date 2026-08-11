<?php

namespace Laravel\Octane\Swoole\Coroutine;

use Illuminate\Support\Facades\Facade;
use ReflectionClass;

class FacadeCache
{
    protected static bool $disabled = false;

    public static function disable(): void
    {
        if (static::$disabled) {
            return;
        }

        $reflection = new ReflectionClass(Facade::class);

        if (! $reflection->hasProperty('cached')) {
            return;
        }

        $property = $reflection->getProperty('cached');
        $property->setValue(null, false);

        static::$disabled = true;
    }
}
