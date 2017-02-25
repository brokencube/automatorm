<?php
namespace Automatorm\Orm;

use Automatorm\Exception;

class Data
{
    protected $__data = array();     // Data from columns on this table
    protected $__external = array(); // Links to foreign key objects
    protected $__schema;             // Schema object for this database
    protected $__namespace;          // Namespace of the Model for this data - used to find Schema again
    protected $__table;              // Class this data is associated with
    protected $__model;              // Fragment of Schema object for this table
    protected $__locked = true;      // Can we use __set() - for updates/inserts
    protected $__new = false;        // Is this to be a new row? (used with Model::new_db())
    protected $__delete = false;     // Row is marked for deletion
    
    protected static $__instance = [];
    
    public static function make(array $data, $table, Schema $schema)
    {
        $key = $data['id'] . ':' . $table . ':' . $schema->namespace;
        
        if (isset(static::$__instance[$key]))
        {
            $obj = static::$__instance[$key];
        }
        else
        {
            $obj = static::$__instance[$key] = new static($data, $table, $schema, true, false);    
        }
        
        return $obj;
    }
    
    public static function updateCache(Data $db)
    {
        $db->lock();
        $key = $db->data['id'] . ':' . $db->table . ':' . $db->schema->namespace;
        return static::$__instance[$key] = $db;
    }
    
    public function __construct(array $data, $table, Schema $schema, $locked = true, $new = false)
    {
        $this->__table = $table;
        $this->__schema = $schema;
        $this->__namespace = $schema->namespace;
        $this->__model = $schema->getTable($table);
        $this->__locked = $locked;
        $this->__new = $new;
        
        // Pull in data from $data
        foreach($data as $key => $value) {
            // Make a special object for dates
            if(!is_null($value) and (
                $this->__model['columns'][$key] == 'datetime'
                or $this->__model['columns'][$key] == 'timestamp'
                or $this->__model['columns'][$key] == 'date'
            )) {
                $this->__data[$key] = new Time($value, new \DateTimeZone('UTC'));
            } else {
                $this->__data[$key] = $value;
            }
        }
    }
    
    // Generally used when this class is accessed through $modelobject->db()
    // This returns an 'unlocked' version of this object that can be used to modify the database row.
    public function __clone()
    {
        $this->__locked = false;
        $this->__external = array();
    }

    // Create a open cloned copy of this object, ready to reinsert as a new row.
    public function duplicate()
    {
        $clone = clone $this;
        $clone->__new = true;
        $clone->__delete = false;
        unset($clone->__data['id']);
        return $clone;
    }
    
    public function lock()
    {
        $this->__locked = true;
        return $this;
    }
    
    public function delete()
    {
        if ($this->__new) throw new Exception\Model('MODEL_DATA:CANNOT_DELETE_UNCOMMITED_DATA');
        $this->__delete = true;
        return $this;
    }
    
    // Accessor method for object properties (columns from the db)
    public function &__get($var)
    {
        /* This property is a native database column, return it */
        if (isset($this->__data[$var])) return $this->__data[$var];
        
        /* This property has already been defined, return it */
        if (isset($this->__external[$var])) return $this->__external[$var];
        
        /* This property hasn't been defined, so it's not one of the table columns. We want to look at foreign keys and pivots */
        
        /* If we try and access a foreign key column without adding the _id on the end assume we want the object, not the id
         * From example at the top: $proj->account_id returns 1      $proj->account returns Account object with id 1
         */
        
        try {
            return $this->join($var);
        }
        catch (Exception\Model $e) {
            if ($e->code == 'MODEL_DATA:UNKNOWN_FOREIGN_PROPERTY') return null;
            throw $e;
        }
    }
    
    public static function groupJoin(Collection $collection, $var, $where = [])
    {
        if (!$collection->count()) return $collection;
        
        $proto = $collection[0]->_data;
        
        $results = new Collection();
        
        /* FOREIGN KEYS */
        if (key_exists($var, (array) $proto->__model['one-to-one']))
        {
            $ids = $collection->id->toArray();
            
            /* Call Tablename::factory(foreign key id) to get the object we want */
            $table = $proto->__model['one-to-one'][$var];
            $results = Model::factoryObjectCache($ids, $table, $proto->__schema);
            
            return $results;
        }
        
        if (key_exists($var, (array) $proto->__model['many-to-one']))
        {
            // Remove duplicates from the group
            $ids = array_unique($collection->{$var . '_id'}->toArray());
            
            /* Call Tablename::factoryObjectCache(foreign key ids) to makes sure all the objects we want are in the instance cache */
            $table = $proto->__model['many-to-one'][$var];

            if (!$where)
            {
                $results = Model::factoryObjectCache($ids, $table, $proto->__schema);
                
                // Store the object results on the relevant objects
                foreach ($collection as $obj)
                {
                    $obj->_data->__external[$var] = Model::factoryObjectCache($obj->{$var . '_id'}, $table, $proto->__schema);
                }
                
                return $results;
            }
            else
            {
                $results = Model::factory($where + ['id' => $ids], $table, $proto->__schema);
                
                return $results;
            }
        }
        
        /* Look for lists of objects in other tables referencing this one */
        if (key_exists($var, (array) $proto->__model['one-to-many'])) {
            
            $table = $proto->__model['one-to-many'][$var]['table'];
            $column = $proto->__model['one-to-many'][$var]['column_name'];
            
            $ids = $collection->id->toArray();
            
            // Use the model factory to find the relevant items
            $results = Model::factory($where + [$column => $ids], $table, $proto->__schema);
            
            // If we didn't use a filter, store the relevant results in each object
            if (!$where)
            {
                foreach($results as $obj)
                {
                    $external[$obj->$column][] = $obj;
                }
                
                foreach ($collection as $obj)
                {
                    $obj->_data->__external[$var] = new Collection((array) $external[$obj->id]);
                }
            }
            
            return $results;
        }
        
        if (key_exists($var, (array) $proto->__model['many-to-many'])) {
            
            // Get pivot schema
            $pivot = $proto->__model['many-to-many'][$var];
            
            $ids = $collection->id->toArray();
            
            // We can only support simple connection access for 2 key pivots.
            if (count($pivot['connections']) != 1) throw new Exception\Model('MODEL_DATA:CANNOT_CALL_MULTIPIVOT_AS_PROPERTY', array($var));
            
            // Get a list of ids linked to this object (i.e. the tablename_id stored in the pivot table)
            $pivot_schema = $proto->__schema->getTable($pivot['pivot']);
            $pivot_tablename = $pivot_schema['table_name'];
            
            $raw = $this->getDataAccessor()->getM2MData(
                $pivot_tablename,
                $pivot,
                $ids,
                $where
            );
            
            // Rearrange the list of ids into a flat array and an id grouped array
            $flat_ids = [];
            $grouped_ids = [];
            foreach($raw as $raw_id)
            {
                $flat_ids[] = $raw_id[$pivot['connections'][0]['column']];
                $grouped_ids[$raw_id[$pivot['id']]][] = $raw_id[$pivot['connections'][0]['column']];
            }
            
            // Remove duplicates to make sql call smaller.
            $flat_ids = array_unique($flat_ids);
            
            // Use the model factory to retrieve the objects from the list of ids (using cache first)
            $results = Model::factoryObjectCache($flat_ids, $pivot['connections'][0]['table'], $proto->__schema);
            
            // If we don't have a filter ($where), then we can split up the results per object and store the
            // results relevant to the result on that object. The calls to Model::factoryObjectCache below will never hit the database, because
            // all of the possible objects were returned in the call above.
            if (!$where)
            {
                foreach ($collection as $obj)
                {
                    $data = Model::factoryObjectCache($grouped_ids[$obj->id], $pivot['connections'][0]['table'], $proto->__schema);
                    $obj->_data->__external[$var] = $data ?: new Collection;
                }
            }
            
            return $results;
        }        
    }

    public static function groupJoinCount(Collection $collection, $var, $where = [])
    {
        if (!$collection->count()) return $collection;
        
        $proto = $collection[0]->_data;
        
        $results = new Collection();
        
        /* FOREIGN KEYS */
        if (key_exists($var, (array) $proto->__model['one-to-one']))
        {
            $ids = $collection->id->toArray();
            
            /* Call Tablename::factory(foreign key id) to get the object we want */
            $table = $proto->__model['many-to-one'][$var];
            
            list($data) = Model::factoryDataCount(['id' => $ids] + $where, $table, $proto->__schema);
            return $data['count'];
        }
        
        if (key_exists($var, (array) $proto->__model['many-to-one']))
        {
            // Remove duplicates from the group
            $ids = array_unique($collection->{$var . '_id'}->toArray());
            
            /* Call Tablename::factory(foreign key id) to get the object we want */
            $table = $proto->__model['many-to-one'][$var];
            list($data) = Model::factoryDataCount(['id' => $ids] + $where, $table, $proto->__schema);
            return $data['count'];
        }
        
        /* Look for lists of objects in other tables referencing this one */
        if (key_exists($var, (array) $proto->__model['one-to-many'])) {
            
            $table = $proto->__model['one-to-many'][$var]['table'];
            $column = $proto->__model['one-to-many'][$var]['column_name'];
            
            $ids = $collection->id->toArray();
            
            // Use the model factory to find the relevant items
            list($data) = Model::factoryDataCount([$column => $ids] + $where, $table, $proto->__schema);
            return $data['count'];
        }
        
        if (key_exists($var, (array) $proto->__model['many-to-many'])) {
            
            // Get pivot schema
            $pivot = $proto->__model['many-to-many'][$var];
            
            $ids = $collection->id->toArray();
            
            // We can only support simple connection access for 2 key pivots.
            if (count($pivot['connections']) != 1) throw new Exception\Model('MODEL_DATA:CANNOT_CALL_MULTIPIVOT_AS_PROPERTY', array($var));
            
            // Get a list of ids linked to this object (i.e. the tablename_id stored in the pivot table)
            $pivot_schema = $proto->__schema->getTable($pivot['pivot']);
            $pivot_tablename = $pivot_schema['table_name'];
            
            $raw = $this->getDataAccessor()->getM2MData(
                $pivot_tablename,
                $pivot,
                $ids,
                $where
            );

            // Rearrange the list of ids into a flat array and an id grouped array
            $flat_ids = [];
            foreach($raw as $raw_id)
            {
                $flat_ids[] = $raw_id[$pivot['connections'][0]['column']];
            }
            
            // Remove duplicates to make sql call smaller.
            $flat_ids = array_unique($flat_ids);
            
            // Use the model factory to retrieve the objects from the list of ids (using cache first)
            list($data) = Model::factoryDataCount(['id' => $flat_ids] + $where, $pivot['connections'][0]['table'], $proto->__schema);
            return $data['count'];
        }        
    }
    
    public function hasForeignKey($var)
    {
        return (bool) (
            key_exists($var, (array) $this->__model['one-to-one'])
            or key_exists($var, (array) $this->__model['one-to-many'])
            or key_exists($var, (array) $this->__model['many-to-one'])
            or key_exists($var, (array) $this->__model['many-to-many'])
        );
    }

    public function join($var, array $where = [])
    {
        if ($this->__external[$var]) return $this->__external[$var]->filter($where);
        
        // If this Model_Data isn't linked to the db yet, then linked values cannot exist
        if (!$id = $this->__data['id']) return new Collection();
        
        /* FOREIGN KEYS */
        if (key_exists($var, (array) $this->__model['one-to-one'])) {        
            /* Call Tablename::factory(foreign key id) to get the object we want */
            $table = $this->__model['one-to-one'][$var];
            $this->__external[$var] = Model::factoryObjectCache($id, $table, $this->__schema);
            
            return $this->__external[$var];
        }
        
        if (key_exists($var, (array) $this->__model['many-to-one'])) {        
            /* Call Tablename::factory(foreign key id) to get the object we want */
            $table = $this->__model['many-to-one'][$var];
            $this->__external[$var] = Model::factoryObjectCache($this->__data[$var . '_id'], $table, $this->__schema);
            
            return $this->__external[$var];
        }
        
        /* Look for lists of objects in other tables referencing this one */
        if (key_exists($var, (array) $this->__model['one-to-many'])) {
            
            $table = $this->__model['one-to-many'][$var]['table'];
            $column = $this->__model['one-to-many'][$var]['column_name'];
            
            // Use the model factory to find the relevant items
            $results = Model::factory($where + [$column => $id], $table, $this->__schema);
            
            if (empty($where)) $this->__external[$var] = $results;
            
            return $results;
        }
        
        if (key_exists($var, (array) $this->__model['many-to-many'])) {
            
            // Get pivot schema
            $pivot = $this->__model['many-to-many'][$var];
            
            // We can only support simple connection access for 2 key pivots.
            if (count($pivot['connections']) != 1) throw new Exception\Model('MODEL_DATA:CANNOT_CALL_MULTIPIVOT_AS_PROPERTY', array($var));
            
            // Get a list of ids linked to this object (i.e. the tablename_id stored in the pivot table)
            $pivot_schema = $this->__schema->getTable($pivot['pivot']);
            $pivot_tablename = $pivot_schema['table_name'];

            $clauses = [];
            if ($where) foreach ($where as $clause_column => $clause_value)
            {
                // Rewrite $where clauses to insert `pivotjoin` table in column name
                preg_match('/^([!=<>%]*)(.+?)([!=<>%]*)$/', $clause_column, $parts);
                $prefix = $parts[1] ?: $parts[3];
                $clause_column = $parts[2];
                
                $clauses['`pivotjoin`.`' . $clause_column . '`' . $prefix] = $clause_value;
            }
            
            // Build Query
            $raw = $this->getDataAccessor()->getM2MData(
                $pivot_tablename,
                $pivot,
                $ids,
                null,
                $clauses
            );
            
            // Rearrange the list of ids into a flat array
            $id = [];
            foreach($raw as $raw_id) $id[] = $raw_id[$pivot['connections'][0]['column']];
            
            // Use the model factory to retrieve the objects from the list of ids (using cache first)
            $results = Model::factoryObjectCache($id, $pivot['connections'][0]['table'], $this->__schema);
            
            if (!$where) $this->__external[$var] = $results;
            
            return $results;
        }
        
        throw new Exception\Model("MODEL_DATA:UNKNOWN_FOREIGN_PROPERTY", ['property' => $var, 'data' => $this]);
    }
    
    public function joinCount($var, $where = [])
    {
        if (!is_null($this->__external[$var]) && !$this->__external[$var] instanceof Collection) return 1;
        if ($this->__external[$var]) return $this->__external[$var]->filter($where)->count();
        
        // If this Model_Data isn't linked to the db yet, then linked values cannot exist
        if (!$id = $this->__data['id']) return 0;
        
        /* FOREIGN KEYS */
        // 1-1, just grab the object - not worth optimising
        if (key_exists($var, (array) $this->__model['one-to-one'])) {
            /* Call Tablename::factory(foreign key id) to get the object we want */
            $table = $this->__model['one-to-one'][$var];
            $this->__external[$var] = Model::factoryObjectCache($id, $table, $this->__schema);
            return $this->__external[$var] ? 1 : 0;
        }
        
        // M-1, just grab the object - not worth optimising
        if (key_exists($var, (array) $this->__model['many-to-one'])) {        
            /* Call Tablename::factory(foreign key id) to get the object we want */
            $table = $this->__model['many-to-one'][$var];
            $this->__external[$var] = Model::factoryObjectCache($this->__data[$var . '_id'], $table, $this->__schema);
            return $this->__external[$var] ? 1 : 0;
        }
        
        /* Look for lists of objects in other tables referencing this one */
        if (key_exists($var, (array) $this->__model['one-to-many'])) {
            
            $table = $this->__model['one-to-many'][$var]['table'];
            $column = $this->__model['one-to-many'][$var]['column_name'];
            
            // Use the model factory to find the relevant items
            list($data) = Model::factoryDataCount($where + [$column => $id], $table, $this->__schema->database);
            return $data['count'];
        }
        
        if (key_exists($var, (array) $this->__model['many-to-many'])) {
            
            // Get pivot schema
            $pivot = $this->__model['many-to-many'][$var];
            
            // We can only support simple connection access for 2 key pivots.
            if (count($pivot['connections']) != 1) throw new Exception\Model('MODEL_DATA:CANNOT_CALL_MULTIPIVOT_AS_PROPERTY', array($var));
            
            // Get a list of ids linked to this object (i.e. the tablename_id stored in the pivot table)
            $pivot_schema = $this->__schema->getTable($pivot['pivot']);
            $pivot_tablename = $pivot_schema['table_name'];
            
            $clauses = [];
            if ($where) foreach ($where as $clause_column => $clause_value)
            {
                // Rewrite $where clauses to insert `pivotjoin` table in column name
                preg_match('/^([!=<>%]*)(.+?)([!=<>%]*)$/', $clause_column, $parts);
                $prefix = $parts[1] ?: $parts[3];
                $clause_column = $parts[2];
                
                $clauses['`pivotjoin`.`' . $clause_column . '`' . $prefix] = $clause_value;
            }
            
            // Build Query
            $raw = $this->getDataAccessor()->getM2MData(
                $pivot_tablename,
                $pivot,
                $ids,
                null,
                $clauses
            );
            
            // Rearrange the list of ids into a flat array
            $id = array();
            foreach($raw as $raw_id) $id[] = $raw_id[$pivot['connections'][0]['column']];
            $dedup = array_unique($id);
            
            return count($dedup);
        }
        
        throw new Exception\Model("MODEL_DATA:UNKNOWN_FOREIGN_PROPERTY", ['property' => $var, 'data' => $this]);
    }
    
    public function __isset($var)
    {
        // Is it already set in local array?
        if (isset($this->__data[$var])) return true;
        if (isset($this->__external[$var])) return true;
        
        // Check through all the possible foreign keys for a matching name
        if (key_exists($var, (array) $this->__model['one-to-one'])) return true;        
        if (key_exists($var, (array) $this->__model['many-to-one'])) return true;
        if (key_exists($var, (array) $this->__model['one-to-many'])) return true;
        if (key_exists($var, (array) $this->__model['many-to-many'])) return true;
        
        return false;
    }
    
    public function __set($var, $value)
    {
        // Cannot change data if it is locked (i.e. it is attached to a Model object)
        if ($this->__locked) throw new Exception\Model('MODEL_DATA:SET_WHEN_LOCKED', array($var, $value));
        
        // Cannot update primary key on existing objects
        // (and cannot set id for new objects that don't have a foreign primary key)
        if ($var == 'id' && $this->__new == false && $this->__model['type'] != 'foreign') {
            throw new Exception\Model('MODEL_DATA:CANNOT_CHANGE_ID', array($var, $value));
        }
        
        // Updating normal columns
        if (key_exists($var, $this->__model['columns']))
        {
            if ($this->__model['columns'][$var] == 'datetime'
                or $this->__model['columns'][$var] == 'timestamp'
                or $this->__model['columns'][$var] == 'date'
            ) {
                // Special checks for datetimes
                // Special case for "null"
                if (is_null($value)) { 
                    $this->__data[$var] = null;
                } elseif ($value instanceof Time) { 
                    $this->__data[$var] = $value;
                } elseif ($value instanceof \DateTime) { 
                    $this->__data[$var] = new Time($value->format(Time::MYSQL_DATE), new \DateTimeZone('UTC'));
                } elseif (($datetime = strtotime($value)) !== false) { // Fall back to standard strings
                    $this->__data[$var] = new Time(date(Time::MYSQL_DATE, $datetime), new \DateTimeZone('UTC'));
                } elseif (is_int($value)) { // Fall back to unix timestamp
                    $this->__data[$var] = new Time(date(Time::MYSQL_DATE, $value), new \DateTimeZone('UTC'));
                } else { 
                    // Oops!
                    throw new Exception\Model('MODEL_DATA:DATETIME_VALUE_EXPECTED_FOR_COLUMN', array($var, $value));
                }
            } elseif (is_scalar($value) or is_null($value) or $value instanceof DB_String) {
                // Standard values
                $this->__data[$var] = $value;
            } else {
                // Objects, arrays etc that cannot be stored in a db column. Explosion!
                throw new Exception\Model('MODEL_DATA:SCALAR_VALUE_EXPECTED_FOR_COLUMN', array($var, $value));
            }
            
            return;
        }
        
        // table_id -> Table - Foreign keys to other tables
        if (key_exists($var, (array) $this->__model['many-to-one'])) {
            if (is_null($value)) {
                $this->__data[$var.'_id'] = null;
                $this->__external[$var] = null;
                return;
            } elseif ($value instanceof Model) {
                // Trying to pass in the wrong table for the relationship!
                // That is, the table name on the foreign key does not match the table name in the passed Model object
                $value_table = Schema::normaliseCase($value->data(true)->__table);
                $expected_table = $this->__model['many-to-one'][$var];
                
                if ($value_table !== $expected_table) {
                    throw new Exception\Model('MODEL_DATA:INCORRECT_MODEL_FOR_RELATIONSHIP', [$var, $value_table, $expected_table]);
                }
                $this->__data[$var.'_id'] = $value->id;
                $this->__external[$var] = $value;
                return;
            } else {
                throw new Exception\Model('MODEL_DATA:MODEL_EXPECTED_FOR_KEY', [$var, $value]);
            }
        }
        
        // Pivot tables - needs an array of appropriate objects for this column
        if (key_exists($var, (array) $this->__model['many-to-many'])) {
            if (is_array($value)) $value = new Collection($value);
            if (!$value) $value = new Collection();
            
            // Still not got a valid collection? Boom!
            if (!$value instanceof Collection) throw new Exception\Model('MODEL_DATA:ARRAY_EXPECTED_FOR_PIVOT', array($var, $value));
            
            foreach($value as $obj) {                
                if (!$obj instanceof Model) throw new Exception\Model('MODEL_DATA:MODEL_EXPECTED_IN_PIVOT_ARRAY', array($var, $value, $obj));
            }
            
            $this->__external[$var] = $value;
            return;
        }
        
        // Table::this_id -> this - Foreign keys on other tables pointing to this one - we cannot 'set' these here.
        // These values must be changes on their root tables (i.e. the table with the twin many-to-one relationship)
        if (key_exists($var, (array) $this->__model['one-to-many'])) {
            throw new Exception\Model('MODEL_DATA:CANNOT_SET_EXTERNAL_KEYS_TO_THIS_TABLE', array($var, $value));
        }
        
        // Undefined column
        throw new Exception\Model('MODEL_DATA:UNEXPECTED_COLUMN_NAME', array($this->__model, $var, $value));
    }
    
    public function commit()
    {
        if ($this->__delete) {
            $mode = 'delete';
        } elseif ($this->__new) {
            $mode = 'insert';
        } else {
            $mode = 'update';
        }
        
        $id = $this->getDataAccessor()->commit(
            $mode,
            $this->__table,
            $this->__data['id'],
            $this->__data,
            $this->__external,
            $this->__model
        );
        
        $this->__new = false;
        
        return $id;
    }
  
    // Get the table that this object is attached to.
    public function getTable()
    {
        return $this->__table;
    }
    
    public function getDatabase()
    {
        return $this->__schema->database;
    }
    
    public function getModel()
    {
        return $this->__model;
    }

    public function getSchema()
    {
        return $this->__schema;
    }
    
    public function getNamespace()
    {
        return $this->__schema->namespace;
    }
    
    public function getDataAccessor()
    {
        return $this->__schema->database->getDataAccessor();
    }
    
    public function externalKeyExists($var)
    {
        if (key_exists($var, (array) $this->__model['one-to-one'])) return 'one-to-one';
        if (key_exists($var, (array) $this->__model['one-to-many'])) return 'one-to-many';
        if (key_exists($var, (array) $this->__model['many-to-one'])) return 'many-to-one';
        if (key_exists($var, (array) $this->__model['many-to-many'])) return 'many-to-many';
        return null;
    }
    
    // By default, hide most of the schema internals of Data objects when var_dumping them!
    public function __debugInfo()
    {
        $return = get_object_vars($this);
        unset($return['__schema']);
        unset($return['__model']);
        return $return;
    }
    
    public function __sleep()
    {
        return [
            '__data',
            '__namespace',
            '__table',
            '__locked',
            '__new',
            '__delete'
        ];
    }
    
    public function __wakeup()
    {
        $this->__external = [];
        $this->__schema = Schema::get($this->__namespace);
        $this->__model = $this->__schema->getTable($this->__table);
    }
}