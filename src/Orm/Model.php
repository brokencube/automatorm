<?php
namespace Automatorm\Orm;

use Automatorm\Exception;

/* MVC Model Class giving a lightweight ORM interface with an indirect active record pattern.
 * The rationale for this superclass is to make it trivial to create an object representing
 * a single row in a database table (and a class representing a database table).
 *
 * Features:
 * * Auto generation of object properties - TableName::get($id)->column_name syntax
 * * Foreign key support
 * *   - Can create other Model objects of appropriate types based on foreign keys specified.
 * * Many to Many support - Can understand pivot tables for many to many relationships
 *
 * Database Design Caveats:
 * * Pivot tables must only contain 2 columns (the two foreign keys).
 * * All tables (except pivots) must have an "id int primary key auto_increment" column
 * * Foreign key columns must end in '_id'
 */

class Model implements \JsonSerializable
{
    // Flags
    const COUNT_ONLY = 1;
    
    public static $tablename;   // Override table associated with this class
    protected static $instance; // An internal store of created objects so that objects for each row only get created once
    
    /* PUBLIC CONSTRUCTION METHODS */
    /**
     * Get an object for a single row in the database, based on id
     *
     * @param int $id Id of row
     * @param bool $forceRefresh Get a fresh copy of data from the database
     * @return self
     */
    public static function get($id, $forceRefresh = false)
    {
        return static::factoryObjectCache($id, null, null, $forceRefresh);
    }

    /**
     * Get objects from the the database, based on list of ids
     *
     * @param int[] $ids Ids of rows
     * @param bool $forceRefresh Get a fresh copy of data from the database
     * @return \Automatorm\Orm\Collection
     */
    public static function getAll(array $ids = null, $forceRefresh = false)
    {
        // Shortcut if no data is passed
        if (is_null($ids) or !count($ids)) {
            return new Collection();
        }
        return static::factoryObjectCache($ids, null, null, $forceRefresh);
    }
    
    /**
     * Get name of database connection for this object
     *
     * @return string
     */
    public static function getConnection()
    {
        $class = get_called_class();
        $namespace = substr($class, 0, strrpos($class, '\\'));
        if (key_exists($namespace, Schema::$namespaces)) {
            return Schema::$namespaces[$namespace];
        }
        
        return 'default';
    }

    /**
     * Find a single(!) object via an arbitary $where clause
     *
     * @param mixed[] $where Where clause to search for
     * @return self
     */
    public static function find($where)
    {
        return static::factory($where, null, null, ['limit' => 1], true);
    }
    
    /**
     * Find a collection of objects via an arbitary $where clause
     *
     * @param mixed[] $where Where clause to search for
     * @param mixed[] $options Options to pass: limit => int, offeset => int, sort => "column direction"
     * @return \Automatorm\Orm\Collection
     */
    public static function findAll($where = [], $options = [])
    {
        return static::factory($where, null, null, $options);
    }
    
    /* FACTORY METHODS */
    // Build an appropriate Model object based on id and class/table name
    final public static function factory($where, $classOrTablename = null, $schema = null, array $options = null, $singleResult = false)
    {
        // Figure out the base class and table we need based on current context
        $schema = $schema ?: Schema::get(static::getNamespace());
        list($class, $table) = $schema->guessContext($classOrTablename ?: get_called_class());
        $namespace = $schema->namespace;
        
        // Get data from database
        $data = Model::factoryData($where, $table, $schema, $options);
        
        // If we're in one object mode, and have no data, return null rather than an empty Model_Collection!
        if ($singleResult and !$data) {
            return null;
        }
        
        // New container for the results
        $collection = new Collection();
        
        foreach ($data as $row) {
            if (!$obj = isset(Model::$instance[$namespace][$table][$row['id']]) ? Model::$instance[$namespace][$table][$row['id']] : false) {
                // Database data object unique to this object
                $dataObj = Data::make($row, $table, $schema);
                
                // Create the object!!
                $obj = new $class($dataObj);
                
                // Store it in the object cache.
                Model::$instance[$namespace][$table][$row['id']] = $obj;
                
                // Call Model objects _init() function - this is to avoid recursion issues with object's natural constructor and the cache above
                $obj->_init();
            }
            
            // If we only wanted one object then shortcut and return now that we have it!
            if ($singleResult) {
                return $obj;
            }
            
            // Add to the model collection
            $collection[] = $obj;
        }
        
        // Return the collection.
        return $collection;
    }
    
    final public static function factoryObjectCache($ids, $classOrTable = null, Schema $schema = null, $forceRefresh = false)
    {
        $schema = $schema ?: Schema::get(static::getNamespace());
        list(,$table) = $schema->guessContext($classOrTable ?: get_called_class());
        $namespace = $schema->namespace;
        
        // If we have a single id
        if (is_numeric($ids)) {
            if (!$forceRefresh) {
                // Check Model object cache
                if (isset(Model::$instance[$namespace][$table][$ids])) {
                    return Model::$instance[$namespace][$table][$ids];
                }
            }
            
            /* Cache miss, so create new object */
            return static::factory(['id' => $ids], $classOrTable, $schema, ['limit' => 1], true);
        
        // Else if we have an array of ids
        } elseif (is_array($ids)) {
            $collection = new Collection();
            
            foreach ($ids as $key => $id) {
                // If an id isn't numeric then skip it
                if (!is_numeric($id)) {
                    unset($ids[$key]);
                    continue;
                }
                // Try and pull the relevant object out of the cache.
                // If we succeed, remove it from the list of ids to search for in the database
                if (!$forceRefresh) {
                    // Check Model object cache
                    if (isset(Model::$instance[$namespace][$table][$id])) {
                        $collection[] = Model::$instance[$namespace][$table][$id];
                        unset($ids[$key]);
                    }
                }
            }
            
            // For any ids we failed to pull out the cache, pull them from the database instead
            if (count($ids) > 0) {
                $newresults = static::factory(['id' => $ids], $classOrTable, $schema);
                $collection = $collection->merge($newresults);
            }
            
            // Merge the database results with the cached results and return
            return $collection;
            
        // We don't have a valid id
        } else {
            return null;
        }
    }
    
    // Get data from database from which we can construct Model objects
    final public static function factoryData($where, $table, Schema $schema, array $options = null)
    {
        return $schema->connection->getDataAccessor()->getData($table, $where, $options);
    }

    // Get data from database from which we can construct Model objects
    final public static function factoryDataCount($where, $table, Schema $schema, array $options = null)
    {
        return $schema->connection->getDataAccessor()->getDataCount($table, $where, $options);
    }
    
    // Return an empty Model_Data object for this class/table so that a new object can be constructed (and a new row entered in the table).
    // For 'foreign' tables, a parent object must be supplied.
    public static function newData(Model $parentObject = null)
    {
        $namespace = static::getNamespace();
        // Get the schema for the current class/table
        $schema = Schema::get($namespace);
        list($class, $table) = $schema->guessContext(get_called_class());
        
        // Make a new blank data object
        $data = new Data([], $table, $schema, false, true);
        
        $tableSchema = $schema->getTable($table);
        // "Foreign" tables use a "parent" table for their primary key. We need that parent object for it's id.
        if ($tableSchema['type'] == 'foreign') {
            if (!$parentObject) {
                throw new Exception\Model('NO_PARENT_OBJECT', [$namespace, $class, $table, static::$tablename]);
            }
            $data->id = $parentObject->id;
        }
        
        return $data;
    }
    
    public static function clearInstanceCache($namespace = null, $table = null, $id = null)
    {
        if (isset($id)) {
            static::$instance[$namespace][$table][$id] = [];
            return;
        }
        if (isset($table)) {
            static::$instance[$namespace][$table] = [];
            return;
        }
        if (isset($namespace)) {
            static::$instance[$namespace] = [];
            return;
        }
        static::$instance = [];
    }

    public static function getNamespace()
    {
        $class = get_called_class();
        return substr($class, 0, strrpos($class, '\\'));
    }
    
    ///////////////////////////////////
    /*        OBJECT METHODS         */
    ///////////////////////////////////
    
    protected $id;                // Id of the table row this object represents
    protected $table;             // Name of db table relating to this object
    protected $namespace;         // Namespace (incase this is a pure Model object)

    protected $_data;             // Container for the Model_Data object for this row. Used for both internal and external __get access.
    protected $cache = false;     // Retain $_db the next time this item is serialised.
    
    // This is a replacement constructor that is called after the model object has been placed in the instance cache.
    // The real constructor is marked final as the normal constructor can cause infinite loops when combined with Class::get();
    // Empty by default - designed to be overridden by subclass
    protected function _init()
    {
    }
    
    // Actual constructor - stores row data and a the $model for this object type.
    final protected function __construct(Data $data)
    {
        // Together the table and id identify a unique row in the database
        $this->_data = $data;
        $this->id = $data->id;
        $this->table = $data->getTable();
        $this->namespace = $data->getNamespace();
    }

    // Dynamic object properties - Prefer properties set on the model object over column data from the db (Model_Data object)
    public function __get($var)
    {
        if ($var == '_') {
            return new PartialResult($this);
        }
        
        // If the property actually exists, then return it rather than looking at the Model_Data object.
        if (property_exists($this, $var)) {
            return $this->{$var};
        }
        
        // If a special property method exists, then call it (again, instead of looking at the Model_Data object).
        if (method_exists($this, '_property_'.$var)) {
            return $this->{$var} = call_user_func([$this, '_property_'.$var]);
        }
        
        // Nothing special set up, default to looking at the Model_Data object.
        return $this->{$var} = $this->_data->{$var};
    }
    
    public function __call($var, $args)
    {
        try {
            if (is_numeric($args[1]) && ($args[1] & Model::COUNT_ONLY)) {
                return $this->_data->joinCount($var, (array) $args[0]);
            }
            return $this->_data->join($var, (array) $args[0]);
        } catch (Exception\Model $e) {
            throw new \BadMethodCallException("Method does not exist ({$var})", 0, $e);
        }
    }
    
    public function __isset($var)
    {
        if (property_exists($this, $var)) {
            return true;
        }
        
        // If a special property method exists, then in effect the property exists, even if it hasn't been materialised yet.
        if (method_exists($this, '_property_'.$var)) {
            return true;
        }
        
        // Check the Data object
        return isset($this->_data->{$var});
    }
    
    // [FIXME] Is it actually safe to return ids for all objects, or do we want to even obfuscate this?
    public function jsonSerialize()
    {
        return ['id' => $this->id];
    }
    
    // Because we usually reconstruct the object from the db when it leaves the session,
    // we only need to keep the id and table/db to fully "rehydrate" the object.
    // If we are caching the object then keep the Model_Data object for this model.
    // [Note] Because we are not saving $cache, it will revert to null when the object is pulled out of the cache.
    //        This is intentional to stop the object becoming stale if it moves from the cache and into another
    //        serialized location (like the session, for example).
    
    public function __sleep()
    {
        $properties = ['id', 'table', 'namespace'];
        if ($this->cache) {
            $properties[] = '_data';
        }
        
        return $properties;
    }

    // Called after we pull the object out of the session/cache (during the session_start() call, for example)
    public function __wakeup()
    {
        // Store the object in the object cache
        Model::$instance[$this->namespace][$this->table][strtolower(get_called_class())][$this->id] = $this;
        
        if (!$this->_data) {
            // If we don't have a data object, then this object wasn't cached, regenerate the Data object.
            $this->dataRefresh();
        } else {
            // We have a data object, call replacement constructor after storing in the cache list (to prevent recursion)
            $this->_init();
        }
        
        return $this;
    }
    
    final public function data()
    {
        return clone $this->_data;
    }
    
    final public function dataOriginal()
    {
        return $this->_data;
    }
    
    // Swap out the Data object in this Model for an updated one (i.e. after doing an update)
    final public function dataUpdate(Data $db)
    {
        $this->_data = Data::updateCache($db);
        $this->dataClearCache();
    }

    // When updating data object, clear "cached" versions of column data saved in __get()
    final public function dataClearCache()
    {
        $modelschema = $this->_data->getModel();
        
        // Clean out cached column data
        foreach (array_keys($modelschema['columns']) as $column) {
            if ($column != 'id' && property_exists($this, $column)) {
                unset($this->{$column});
            }
        }
        
        // Clean out cached "dynamic property" data
        foreach (get_class_methods(get_called_class()) as $methodname) {
            if (substr($methodname, 0, 10) == '_property_') {
                $column = substr($methodname, 10);
                unset($this->{$column});
            }
        }
        
        // Clean out cached external data
        $foreignkeys = (array) $modelschema['one-to-one'] + (array) $modelschema['one-to-many'] + (array) $modelschema['many-to-many'] + (array) $modelschema['many-to-one'];
        foreach (array_keys($foreignkeys) as $column) {
            if ($column && $column != 'id') {
                unset($this->{$column});
            }
        }
    }
    
    // Grab a clean version of the Data object based on the current state in the database.
    // Mostly used for updating foreign key results after updates
    final public function dataRefresh()
    {
        $schema = Schema::get($this->namespace);
        list($data) = Model::factoryData(['id' => $this->id], $this->table, $schema);
        
        // Database data object unique to this object
        $this->_data = new Data($data, $this->table, $schema);
        Data::updateCache($this->_data);
        
        // Call replacement constructor after storing in the cache list (to prevent recursion)
        $this->dataClearCache();
        $this->_init();
        
        return $this;
    }
    
    // If true, Data object is preserved when serializing this object
    public function cachable($bool = true)
    {
        $this->cache = $bool;
        return $this;
    }
}
