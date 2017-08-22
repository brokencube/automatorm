<?php

namespace Automatorm\Orm;

use Automatorm\Exception;
use Automatorm\Interfaces\Connection;
use Psr\SimpleCache\CacheInterface as Psr16;
use Psr\Cache\CacheItemPoolInterface as Psr6;

class Schema
{
    const CURRENT_VERSION = 9;

    // Singleton
    public static $schemaname = [];
    public static $singleton = [];
    public static function get($namespace)
    {
        if (!static::$singleton[$namespace]) {
            throw new Exception\Model('NO_GENERATED_SCHEMA', $namespace);
        }
        
        return static::$singleton[$namespace];
    }

    public static function generate(Connection $connection, $namespace = 'models', $cache = null)
    {
        // Get schema from cache
        if (!$cache) {
            list($model, $schema) = $connection->getSchemaGenerator()->generate();
        } else {
            $key = 'schema_' . md5($namespace . static::CURRENT_VERSION);
            
            if (!$cache instanceof Psr16 && !$cache instanceof Psr6) {
                throw new \InvalidArgumentException('Supplied $cache does not implement PSR6/16 interface');
            }
            
            if ($cache instanceof Psr16) {
                list($model, $schema) = $cache->get($key);
            }
                
            if ($cache instanceof Psr6) {
                $item = $cache->getItem($key);
                if ($item->isHit()) {
                    list($model, $schema) = $item->get();
                }
            }
            
            // If no cache, generate the schema
            if (!$model || !$schema) {
                list($model, $schema) = $connection->getSchemaGenerator()->generate();
                
                if ($cache instanceof Psr16) {
                    $cache->set($key, [$model, $schema], 3600);
                }
                    
                if ($cache instanceof Psr6) {
                    $item = $cache->getItem($key);
                    $item->set([$model, $schema]);
                    $item->expiresAt(new \DateTime('now + 3600 seconds'));
                    $cache->save($item);
                }
            }
        }
        
        $obj = new static($model, $connection, $namespace, $schema);
        
        // Register namespace with connection
        static::$schemaname[$schema] = $obj;
        static::$singleton[$namespace] = $obj;
        
        // Return schema object
        return $obj;
    }
    
    ///////////////////////////////////////////////////////////////////////////
    
    protected $model;
    protected $connection;
    protected $namespace;
    protected $version;
    protected $_serviceContainer = [];
    protected $schema;
    
    protected function __construct($model, \Automatorm\Interfaces\Connection $connection, $namespace, $schema)
    {
        $this->model = $model;
        $this->schema = $schema;
        $this->namespace = $namespace;
        $this->version = static::CURRENT_VERSION;
        $this->connection = $connection;
    }
    
    public function getTable($table)
    {
        $normalised = self::normaliseCase($table);
        
        return $this->model[$normalised];
    }

    public function getConnection()
    {
        return $this->connection;
    }
    
    public function __get($var)
    {
        return $this->$var;
    }
    
    public function setService($name, $container)
    {
        $this->_serviceContainer[$name] = $container;
        return $this;
    }
    
    public function getService($name)
    {
        if (!array_key_exists($name, $this->_serviceContainer)) {
            throw new Exception\Model('NO_SUCH_SERVICE', $name);
        }
        
        return $this->_serviceContainer[$name];
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

    public static function getSchemaByName($schemaname)
    {
        return static::$schemaname[$schemaname];
    }
}
