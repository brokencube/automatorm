<?php

namespace Automatorm\Cache;

interface CacheInterface
{
    public function get($key);
    public function put($key, $value, $timeout = 60 * 60 * 24 * 7);
}
