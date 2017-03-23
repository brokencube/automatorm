<?php

namespace Automatorm\Orm;

use Automatorm\Exception;
use Automatorm\Database\Query;
use Automatorm\Database\Connection;
use Automatorm\Cache\CacheInterface;
use HodgePodge\Core\Cache;

class Schema
{
    const CURRENT_VERSION = 7;

    // Cache Bridge
    protected static $cache = '\\Automatorm\\Cache\\HodgePodgeCache';
    public static function registerCache($cache)
    {
        static::$cache = $cache;
    }
    
    // Singleton
    public static $singleton = [];
    public static $namespaces = [];
    public static function get($namespace)
    {
        if (!static::$singleton[$namespace]) {
            throw new Exception\Model('NO_GENERATED_SCHEMA', $namespace);
        }
        
        return static::$singleton[$namespace];
    }

    public static function generate($connection, $namespace = 'models', $cachebust = false)
    {
        if (!$connection instanceof \Automatorm\Interfaces\Connection) {
            $connection = Connection::get($connection);
        }
        
        // Register namespace with connection
        static::$namespaces[$namespace] = $connection;
        $cache = null;
        
        // Get schema from cache
        if ($cachebust || !static::$cache) {
            $model = $connection->getSchemaGenerator()->generate();
        } else {
            $cache = is_object(static::$cache) ? static::$cache : new static::$cache();
            $key = 'schema_' . md5($namespace . static::CURRENT_VERSION);
            $model = $cache->get($key);
            
            // If no cache, generate the schema
            if (!$model) {
                $model = $connection->getSchemaGenerator()->generate();
                $cache->put($key, $model, 60 * 60 * 24 * 7);
            }
        }
        
        $obj = new static($model, $connection, $namespace);
        
        // Return schema object
        return static::$singleton[$namespace] = $obj;
    }
    
    ///////////////////////////////////////////////////////////////////////////
    
    protected $model;
    protected $connection;
    protected $namespace;
    protected $version;
    
    protected function __construct($model, \Automatorm\Interfaces\Connection $connection, $namespace)
    {
        $this->model = $model;
        $this->namespace = $namespace;
        $this->version = static::CURRENT_VERSION;
        $this->connection = $connection;
    }
    
    public function getTable($table)
    {
        $normalised = self::normaliseCase($table);
        
        return $this->model[$normalised];
    }

    public function __get($var)
    {
        return $this->$var;
    }
    
    ///////////////////////////////////////////////////////////////////////////

    // Normalised an under_scored or CamelCased phrase to "under scored" or "camel cased"
    private static $stringCacheN = [];
    public static function normaliseCase($string)
    {
        if (isset(static::$stringCacheN[$string])) {
            return static::$stringCacheN[$string];
        }
        return static::$stringCacheN[$string] = trim(strtolower(preg_replace('/([A-Z])|_/', ' $1', $string)));
    }
    
    private static $stringCacheC = [];
    public static function camelCase($string)
    {
        if (isset(static::$stringCacheC[$string])) {
            return static::$stringCacheC[$string];
        }
        return static::$stringCacheC[$string] = str_replace(' ', '', ucwords(self::normaliseCase($string)));
    }
    
    private static $stringCacheU = [];
    public static function underscoreCase($string)
    {
        if (isset(static::$stringCacheU[$string])) {
            return static::$stringCacheU[$string];
        }
        return static::$stringCacheU[$string] = str_replace(' ', '_', self::normaliseCase($string));
    }
    
    protected static $contextCache = [];
    // Based on supplied data, try and guess the appropriate class and tablename
    public function guessContext($classOrTable)
    {
        // Return 'cached' result
        if (isset(static::$contextCache[$classOrTable])) {
            return static::$contextCache[$classOrTable];
        }
        
        // Namespace classname? Remove that namespace before continuing
        if (strrpos($classOrTable, '\\') !== false) {
            $classOrTable = substr($classOrTable, strrpos($classOrTable, '\\') + 1);
        }
        
        // Normalise the (namespace stripped) class or table name so we don't have to worry about under_score or CamelCase
        $normalised = self::normaliseCase($classOrTable);
        
        // First guesses as to table and class names
        $class = $this->namespace . '\\' . self::camelCase($normalised);
        $table = $this->model[$normalised] ? $this->model[$normalised]['table_name'] : '';
        
        // If the guessed classname exists, then we are making one of those objects.
        if (class_exists($class)) {
            // Does this class have a different table, otherwise use guess from above.
            if ($class::$tablename) {
                $normalisedTable = self::normaliseCase($class::$tablename);
                $table = $this->model[$normalisedTable] ? $this->model[$normalisedTable]['table_name'] : '';
            }
        } else {
            // We didn't find an appropriate class - make a Model object using the guessed table name.
            $class = '\\Automatorm\\Orm\\Model';
        }
        
        // We haven't found an entry in the schema for our best guess table name? Boom!
        if (!$table) {
            throw new Exception\Model('NO_SCHEMA', [$classOrTable, $normalised, $class]);
        }
        
        return static::$contextCache[$classOrTable] = [$class, $table];
    }
}
