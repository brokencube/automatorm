<?php
namespace Automatorm\Orm;

use HodgePodge\Common;
use HodgePodge\Core;
use Automatorm\Exception;

/* MVC Model Class giving a lightweight ORM interface with an indirect active record pattern.
 * The rationale for this superclass is to make it trivial to create an object representing a single row in a database table (and a class
 * representing a database table).
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
    public static $dbconnection = 'default'; // Override database connection associated with this class - for subclasses
    public static $tablename;                // Override table associated with this class - for subclasses
    protected static $instance;              // An internal store of already created objects so that objects for each row only get created once
    
    /* PUBLIC CONSTRUCTION METHODS */
    public static function get($id, $force_refresh = false)
    {
        return static::factoryObjectCache($id, null, null, $force_refresh);
    }
    
    // Find a single(!) object via an arbitary $where clause
    public static function find($where, $cacheable = false)
    {
        // If result is cacheable, look in the cache
        if ($cacheable)
        {
            // Get table/class data
            if (!$database) $database = static::$dbconnection;
            $schema = Schema::get($database);
            list($class, $table) = $schema->guessContext($class_or_table ?: get_called_class());
            
            // Hash where clause
            $key = md5(serialize($where));
            
            // Look in cache
            if (isset(Model::$instance[$database][$table][$key])) {
                $result = Model::$instance[$database][$table][$key];
            }
        }
        
        // No cache or cache miss
        if (!$result)
        {
            $o = new Core\QueryOptions();
            $result = static::factory($where, null, null, $o->limit(1), true);
            
            // If caching, store in cache
            if ($cacheable)
            {
                Model::$instance[$database][$table][$key] = $result;
            }
        }
        
        return $result;
    }
    
    // Find a collection of objects via an arbitary $where clause
    public static function findAll($where = array(), $limit = null, $offset = 0, $sort = null)
    {
        $o = new Core\QueryOptions();
        return static::factory($where, null, null, $o->limit($limit, $offset)->sort($sort));
    }
    
    /* FACTORY METHODS */    
    // Build an appropriate Model object based on id and class/table name
    final public static function factory($where, $class_or_table_name = null, $database = null, Core\QueryOptions $options = null, $single_result = false)
    {
        // Some defaults
        if (!$database) $database = static::$dbconnection;
        
        // Figure out the base class and table we need based on current context
        $schema = Schema::get($database);
        list($base_class, $table) = $schema->guessContext($class_or_table_name ?: get_called_class());
        
        // Get data from database        
        $data = Model::factoryData($where, $table, $database, $options);
                
        // If we're in one object mode, and have no data, return null rather than an empty Model_Collection!
        if ($single_result and !$data) return null;
            
        // New container for the results
        $collection = new Collection();
        
        foreach($data as $row) {
            // Database data object unique to this object
            $data_obj = Data::make($row, $table, $schema);
            
            // Ask the base_class if there is a subclass we should be using.
            $class = call_user_func(array($base_class, '_subclass'), $data_obj);
            
            // Create the object!!
            $obj = new $class($data_obj);
            
            // Store it in the object cache.        
            Model::$instance[$database][$table][$row['id']] = $obj;
            
            // Call Model objects _init() function - this is to avoid recursion issues with object's natural constructor and the cache above
            $obj->_init();
            
            // If we only wanted one object then shortcut and return now that we have it!
            if ($single_result) return $obj;
            
            // Add to the model collection
            $collection[] = $obj;
        }
        
        // Return the collection.
        return $collection;
    }
    
    protected static function _subclass(Data $data)
    {
        return get_called_class();
    }
    
    final public static function factoryObjectCache($ids, $class_or_table = null, $database = null, $force_refresh = false)
    {
        if (!$database) $database = static::$dbconnection;
        $schema = Schema::get($database);
        list($class, $table) = $schema->guessContext($class_or_table ?: get_called_class());

        // If we have a single id
        if (is_numeric($ids)) {
            if (!$force_refresh) {
                // Check Model object cache
                if (isset(Model::$instance[$database][$table][$ids])) {
                    return Model::$instance[$database][$table][$ids];
                }
            }
            
            /* Cache miss, so create new object */
            $o = new Core\QueryOptions();
            return static::factory(array('id' => $ids), $class_or_table, $database, $o->limit(1), true);
        
        // Else if we have an array of ids
        } elseif (is_array($ids)) {
            $collection = new Collection();
            
            foreach ($ids as $key => $id) {
                // If an id isn't numeric then skip it
                if (!is_numeric($id))
                {
                    unset($ids[$key]);
                    continue;
                }
                // Try and pull the relevant object out of the cache.
                // If we succeed, remove it from the list of ids to search for in the database
                if (!$force_refresh) {
                    // Check Model object cache
                    if (isset(Model::$instance[$database][$table][$id])) {
                        $collection[] = Model::$instance[$database][$table][$id];
                        unset($ids[$key]);
                    }
                }
            }
            
            // For any ids we failed to pull out the cache, pull them from the database instead
            if (count($ids) > 0)
            {
                $newresults = static::factory(array('id' => $ids), $class_or_table, $database);
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
    final protected static function factoryData($where, $table, $database, Core\QueryOptions $options = null)
    {
        // Select * from $table where $where
        $query = new Core\Query($database);
        list($data) = $query->select($table, $where, $options)->execute();
                
        return $data;
    }
    
    // Return an empty Model_Data object for this class/table so that a new object can be constructed (and a new row entered in the table).
    // For 'foreign' tables, a parent object must be supplied.
    public static function newData(Model $parent_object = null)
    {
        // Get the schema for the current class/table
        $schema = Schema::get(static::$dbconnection);
        list($class, $table) = $schema->guessContext(get_called_class());
        
        // Make a new blank data object
        $model_data = new Data(array(), $table, $schema, false, true);
        
        $table_schema = $schema->getTable($table);
        // "Foreign" tables use a "parent" table for their primary key. We need that parent object for it's id.
        if ($table_schema['type'] == 'foreign') {
            if (!$parent_object) throw new Exception\Model('NO_PARENT_OBJECT', array($database, $class, $table, static::$tablename));
            $model_data->id = $parent_object->id;
        }
        
        return $model_data;
    }
    
    public static function clearInstanceCache($database = null, $table = null, $id = null)
    {
        if (isset($id)) {
            unset(static::$instance[$database][$table][$id]);
            return;
        }
        if (isset($table)) {
            unset(static::$instance[$database][$table]);
            return;
        }
        if (isset($database)) {
            unset(static::$instance[$database]);
            return;
        }
        unset(static::$instance);
        return;
    }
    
    ///////////////////////////////////
    /*        OBJECT METHODS         */
    ///////////////////////////////////
    
    protected $id;                // Id of the table row this object represents
    protected $table;             // Name of db table relating to this object
    protected $database;          // Name of db connection relating to this object

    protected $_data;             // Container for the Model_Data object for this row. Used for both internal and external __get access.
    protected $cache = false;     // Retain $_db the next time this item is serialised.
    
    // This is a replacement constructor that is called after the model object has been placed in the instance cache.
    // The real constructor is marked final as the normal constructor can cause infinite loops when combined with Class::get();
    // Empty by default - designed to be overridden by subclass
    protected function _init() {}
    
    // Actual constructor - stores row data and a the $model for this object type.
    final protected function __construct(Data $data)
    {
        // Together the table and id identify a unique row in the database
        $this->_data = $data;
        $this->id = $data->id;
        $this->table = $data->getTable();
        $this->database = $data->getDatabase();
    }

    // Dynamic object properties - Prefer properties set on the model object over column data from the db (Model_Data object)
    public function __get($var)
    {        
        // If the property actually exists, then return it rather than looking at the Model_Data object.
        if (property_exists($this, $var)) return $this->{$var};
        
        // If a special property method exists, then call it (again, instead of looking at the Model_Data object).
        if (method_exists($this, '_property_'.$var)) return call_user_func(array($this, '_property_'.$var));
        
        // Nothing special set up, default to looking at the Model_Data object.
        return $this->_data->{$var};
    }
    
    public function __call($var, $args)
    {
        try {
            return $this->_data->join($var, $args[0]);
        }
        catch (Exception\Model $e)
        {
            throw new \BadMethodCallException("Method does not exist ({$var})", 0, $e);
        }
    }
    
    public function __isset($var)
    {
        if (property_exists($this, $var)) return true;
        
        // If a special property method exists, then in effect the property exists, even if it hasn't been materialised yet.
        if (method_exists($this, '_property_'.$var)) return true;
        
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
        $properties = array('id', 'table', 'database');
        if ($this->cache) {
            $properties[] = '_data';
        }
        return $properties;
    }

    // Called after we pull the object out of the session/cache (during the session_start() call, for example)
    public function __wakeup()
    {
        // Store the object in the object cache
        Model::$instance[$this->database][$this->table][strtolower(get_called_class())][$this->id] = $this;
        
        if (!$this->_data) {
            // If we don't have a data object, then this object wasn't cached, regenerate the Data object.
            $this->dataRefresh();
        }
        else
        {
            // We have a data object, call replacement constructor after storing in the cache list (to prevent recursion)
            $this->_init();            
        }
        
        return $this;
    }
    
    final public function data($return_original = false)
    {
        if ($return_original) {
            return $this->_data;
        } else {
            return clone $this->_data;    
        }
    }
    
    final public function dataUpdate(Data $db)
    {
        $this->_data = Data::updateCache($db);
    }
    
    final public function dataRefresh()
    {
        list($data) = Model::factoryData(array('id' => $this->id), $this->table, $this->database);
        
        // Database data object unique to this object
        $this->_data = new Data($data, $this->table, Schema::get($this->database));
        Data::updateCache($this->_data);
        
        // Call replacement constructor after storing in the cache list (to prevent recursion)
        $this->_init();
        
        return $this;        
    }
    
    public function cachable($bool = true)
    {
        $this->cache = $bool;
        return $this;
    }
}
