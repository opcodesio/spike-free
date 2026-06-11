<?php

namespace Spatie\PestPluginTestTime;

use Carbon\Carbon;
use Spatie\TestTime\TestTime as BaseTestTime;

if (! class_exists(TestTime::class)) {
    /** @mixin BaseTestTime|\Carbon\Carbon */
    class TestTime
    {
        public function freeze(Carbon|string|null $time = null, string $format = 'Y-m-d H:i:s'): Carbon
        {
            if ($time instanceof Carbon) {
                return BaseTestTime::freeze($time);
            }

            if ($time === null) {
                return BaseTestTime::freeze();
            }

            return BaseTestTime::freeze($format, $time);
        }

        public function __call(string $name, array $arguments): mixed
        {
            return BaseTestTime::$name(...$arguments);
        }
    }
}

if (! function_exists(__NAMESPACE__.'\\testTime')) {
    function testTime(): TestTime
    {
        return new TestTime();
    }
}
