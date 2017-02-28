<?php

namespace Automatorm\Cache;

use HodgePodge\Core\Cache as HPCache;

class HodgePodgeCache implements CacheInterface
{
    public function get($key)
    {
        $cache = new HPCache($key, 'cache');
        return $cache->get();
    }
    
    public function put($key, $value, $timeout = 60 * 60 * 24 * 7)
    {
        HPCache::lifetime($timeout, 'cache');
        $cache = new HPCache($key, 'cache');
        return $cache->save($value);
    }
}
