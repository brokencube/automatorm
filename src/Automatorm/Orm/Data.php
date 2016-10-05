<?php
namespace Automatorm\Orm;

use Automatorm\Database\Query;
use Automatorm\Database\QueryBuilder;
use Automatorm\Database\SqlString;
use Automatorm\Exception;

use HodgePodge\Core;

class Data
{
    protected $data = array();     // Data from columns on this table
    protected $external = array(); // Links to foreign key objects
    protected $database;           // Database this data is associated with
    protected $schema;             // Schema object for this database
    protected $table;              // Class this data is associated with
    protected $model;              // Fragment of Schema object for this table
    protected $locked = true;      // Can we use __set() - for updates/inserts
    protected $new = false;        // Is this to be a new row? (used with Model::new_db())
    protected $delete = false;     // Row is marked for deletion
    
    protected static $instance = [];
    
    public static function make(array $data, $table, Schema $schema)
    {
        $key = $data['id'] . ':' . $table . ':' . $schema->namespace;
        
        if (isset(static::$instance[$key]))
        {
            $obj = static::$instance[$key];
        }
        else
        {
            $obj = static::$instance[$key] = new static($data, $table, $schema, true, false);    
        }
        
        return $obj;
    }
    
    public static function updateCache(Data $db)
    {
        $db->lock();
        $key = $db->data['id'] . ':' . $db->table . ':' . $db->schema->namespace;
        static::$instance[$key] = $db;
        return $db;
    }
    
    public function __construct(array $data, $table, Schema $schema, $locked = true, $new = false)
    {
        $this->database = $schema->database;
        $this->table = $table;
        $this->schema = $schema;
        $this->model = $schema->getTable($table);
        $this->locked = $locked;
        $this->new = $new;
        
        // Pull in data from $data
        foreach($data as $key => $value) {
            // Make a special object for dates
            if(!is_null($value) and (
                $this->model['columns'][$key] == 'datetime'
                or $this->model['columns'][$key] == 'timestamp'
                or $this->model['columns'][$key] == 'date'
            )) {
                $this->data[$key] = new Time($value, new \DateTimeZone('UTC'));
            } else {
                $this->data[$key] = $value;
            }
        }
    }
    
    // Generally used when this class is accessed through $modelobject->db()
    // This returns an 'unlocked' version of this object that can be used to modify the database row.
    public function __clone()
    {
        $this->locked = false;
        $this->external = array();
    }

    // Create a open cloned copy of this object, ready to reinsert as a new row.
    public function duplicate()
    {
        $clone = clone $this;
        $clone->new = true;
        $clone->delete = false;
        unset($clone->data['id']);
        return $clone;
    }
    
    public function lock()
    {
        $this->locked = true;
        return $this;
    }
    
    public function delete()
    {
        if ($this->new) throw new Exception\Model('MODEL_DATA:CANNOT_DELETE_UNCOMMITED_DATA');
        $this->delete = true;
        return $this;
    }
    
    // Accessor method for object properties (columns from the db)
    public function &__get($var)
    {
        /* This property is a native database column, return it */
        if (isset($this->data[$var])) return $this->data[$var];
        
        /* This property has already been defined, return it */
        if (isset($this->external[$var])) return $this->external[$var];
        
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
        if (key_exists($var, (array) $proto->model['one-to-one']))
        {
            $ids = $collection->id->toArray();
            
            /* Call Tablename::factory(foreign key id) to get the object we want */
            $table = $proto->model['many-to-one'][$var];
            $results = Model::factoryObjectCache($ids, $table, $proto->database);
            
            return $results;
        }
        
        if (key_exists($var, (array) $proto->model['many-to-one']))
        {
            // Remove duplicates from the group
            $ids = array_unique($collection->{$var . '_id'}->toArray());
            
            /* Call Tablename::factoryObjectCache(foreign key ids) to makes sure all the objects we want are in the instance cache */
            $table = $proto->model['many-to-one'][$var];

            if (!$where)
            {
                $results = Model::factoryObjectCache($ids, $table, $proto->database);
                
                // Store the object results on the relevant objects
                foreach ($collection as $obj)
                {
                    $obj->_data->external[$var] = Model::factoryObjectCache($obj->{$var . '_id'}, $table, $proto->database);
                }
                
                return $results;
            }
            else
            {
                $results = Model::factory($where + ['id' => $ids], $table, $proto->database);
                
                return $results;
            }
        }
        
        /* Look for lists of objects in other tables referencing this one */
        if (key_exists($var, (array) $proto->model['one-to-many'])) {
            
            $table = $proto->model['one-to-many'][$var]['table'];
            $column = $proto->model['one-to-many'][$var]['column_name'];
            
            $ids = $collection->id->toArray();
            
            // Use the model factory to find the relevant items
            $results = Model::factory($where + [$column => $ids], $table, $proto->database);
            
            // If we didn't use a filter, store the relevant results in each object
            if (!$where)
            {
                foreach($results as $obj)
                {
                    $external[$obj->$column][] = $obj;
                }
                
                foreach ($collection as $obj)
                {
                    $obj->_data->external[$var] = new Collection((array) $external[$obj->id]);
                }
            }
            
            return $results;
        }
        
        if (key_exists($var, (array) $proto->model['many-to-many'])) {
            
            // Get pivot schema
            $pivot = $proto->model['many-to-many'][$var];
            
            $ids = $collection->id->toArray();
            
            // We can only support simple connection access for 2 key pivots.
            if (count($pivot['connections']) != 1) throw new Exception\Model('MODEL_DATA:CANNOT_CALL_MULTIPIVOT_AS_PROPERTY', array($var));
            
            // Get a list of ids linked to this object (i.e. the tablename_id stored in the pivot table)
            $pivot_schema = $proto->schema->getTable($pivot['pivot']);
            $pivot_tablename = $pivot_schema['table_name'];
            
            $q = QueryBuilder::select([$pivot_tablename => 'pivot'], ['pivot.*'])
                ->where(['`pivot`.'.$pivot['id'] => $ids])
                ->join([Schema::underscoreCase($pivot['connections'][0]['table']) => 'pivotjoin'])
                ->joinOn(['pivotjoin.id' => "`pivot`.`{$pivot['connections'][0]['column']}`"])
                ->joinWhere($where);
                
            $query = new Query($proto->database);
            list($raw) = $query->sql($q)->execute();
            
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
            $results = Model::factoryObjectCache($flat_ids, $pivot['connections'][0]['table'], $proto->database);
            
            // If we don't have a filter ($where), then we can split up the results per object and store the
            // results relevant to the result on that object. The calls to Model::factoryObjectCache below will never hit the database, because
            // all of the possible objects were returned in the call above.
            if (!$where)
            {
                foreach ($collection as $obj)
                {
                    $data = Model::factoryObjectCache($grouped_ids[$obj->id], $pivot['connections'][0]['table'], $proto->database);
                    $obj->_data->external[$var] = $data ?: new Collection;
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
        if (key_exists($var, (array) $proto->model['one-to-one']))
        {
            $ids = $collection->id->toArray();
            
            /* Call Tablename::factory(foreign key id) to get the object we want */
            $table = $proto->model['many-to-one'][$var];
            
            list($data) = Model::factoryDataCount(['id' => $ids] + $where, $table, $proto->database);
            return $data['count'];
        }
        
        if (key_exists($var, (array) $proto->model['many-to-one']))
        {
            // Remove duplicates from the group
            $ids = array_unique($collection->{$var . '_id'}->toArray());
            
            /* Call Tablename::factory(foreign key id) to get the object we want */
            $table = $proto->model['many-to-one'][$var];
            list($data) = Model::factoryDataCount(['id' => $ids] + $where, $table, $proto->database);
            return $data['count'];
        }
        
        /* Look for lists of objects in other tables referencing this one */
        if (key_exists($var, (array) $proto->model['one-to-many'])) {
            
            $table = $proto->model['one-to-many'][$var]['table'];
            $column = $proto->model['one-to-many'][$var]['column_name'];
            
            $ids = $collection->id->toArray();
            
            // Use the model factory to find the relevant items
            list($data) = Model::factoryDataCount([$column => $ids] + $where, $table, $proto->database);
            return $data['count'];
        }
        
        if (key_exists($var, (array) $proto->model['many-to-many'])) {
            
            // Get pivot schema
            $pivot = $proto->model['many-to-many'][$var];
            
            $ids = $collection->id->toArray();
            
            // We can only support simple connection access for 2 key pivots.
            if (count($pivot['connections']) != 1) throw new Exception\Model('MODEL_DATA:CANNOT_CALL_MULTIPIVOT_AS_PROPERTY', array($var));
            
            // Get a list of ids linked to this object (i.e. the tablename_id stored in the pivot table)
            $pivot_schema = $proto->schema->getTable($pivot['pivot']);
            $pivot_tablename = $pivot_schema['table_name'];
            
            $q = QueryBuilder::select([$pivot_tablename => 'pivot'], ['pivot.*'])
                ->where(['`pivot`.'.$pivot['id'] => $ids])
                ->join([Schema::underscoreCase($pivot['connections'][0]['table']) => 'pivotjoin'])
                ->joinOn(['pivotjoin.id' => "`pivot`.`{$pivot['connections'][0]['column']}`"])
                ->joinWhere($where);
                
            $query = new Query($proto->database);
            list($raw) = $query->sql($q)->execute();

            // Rearrange the list of ids into a flat array and an id grouped array
            $flat_ids = [];
            foreach($raw as $raw_id)
            {
                $flat_ids[] = $raw_id[$pivot['connections'][0]['column']];
            }
            
            // Remove duplicates to make sql call smaller.
            $flat_ids = array_unique($flat_ids);
            
            // Use the model factory to retrieve the objects from the list of ids (using cache first)
            list($data) = Model::factoryDataCount(['id' => $flat_ids] + $where, $pivot['connections'][0]['table'], $proto->database);
            return $data['count'];
        }        
    }
    
    public function hasForeignKey($var)
    {
        return (bool) (
            key_exists($var, (array) $this->model['one-to-one'])
            or key_exists($var, (array) $this->model['one-to-many'])
            or key_exists($var, (array) $this->model['many-to-one'])
            or key_exists($var, (array) $this->model['many-to-many'])
        );
    }

    public function join($var, array $where = [])
    {
        if ($this->external[$var]) return $this->external[$var]->filter($where);
        
        // If this Model_Data isn't linked to the db yet, then linked values cannot exist
        if (!$id = $this->data['id']) return new Collection();
        
        /* FOREIGN KEYS */
        if (key_exists($var, (array) $this->model['one-to-one'])) {        
            /* Call Tablename::factory(foreign key id) to get the object we want */
            $table = $this->model['one-to-one'][$var];
            $this->external[$var] = Model::factoryObjectCache($id, $table, $this->database);
            
            return $this->external[$var];
        }
        
        if (key_exists($var, (array) $this->model['many-to-one'])) {        
            /* Call Tablename::factory(foreign key id) to get the object we want */
            $table = $this->model['many-to-one'][$var];
            $this->external[$var] = Model::factoryObjectCache($this->data[$var . '_id'], $table, $this->database);
            
            return $this->external[$var];
        }
        
        /* Look for lists of objects in other tables referencing this one */
        if (key_exists($var, (array) $this->model['one-to-many'])) {
            
            $table = $this->model['one-to-many'][$var]['table'];
            $column = $this->model['one-to-many'][$var]['column_name'];
            
            // Use the model factory to find the relevant items
            $results = Model::factory($where + [$column => $id], $table, $this->database);
            
            if (empty($where)) $this->external[$var] = $results;
            
            return $results;
        }
        
        if (key_exists($var, (array) $this->model['many-to-many'])) {
            
            // Get pivot schema
            $pivot = $this->model['many-to-many'][$var];
            
            // We can only support simple connection access for 2 key pivots.
            if (count($pivot['connections']) != 1) throw new Exception\Model('MODEL_DATA:CANNOT_CALL_MULTIPIVOT_AS_PROPERTY', array($var));
            
            // Get a list of ids linked to this object (i.e. the tablename_id stored in the pivot table)
            $pivot_schema = $this->schema->getTable($pivot['pivot']);
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
            $q = QueryBuilder::select([$pivot_tablename => 'pivot'], ['pivot.*'])
                ->where(['`pivot`.'.$pivot['id'] => $this->data['id']])
                ->join([Schema::underscoreCase($pivot['connections'][0]['table']) => 'pivotjoin'])
                ->joinOn(['pivotjoin.id' => "`pivot`.`{$pivot['connections'][0]['column']}`"])
                ->where($clauses);
                
            $query = new Query($this->database);
            list($raw) = $query->sql($q)->execute();

            // Rearrange the list of ids into a flat array
            $id = [];
            foreach($raw as $raw_id) $id[] = $raw_id[$pivot['connections'][0]['column']];
            
            // Use the model factory to retrieve the objects from the list of ids (using cache first)
            $results = Model::factoryObjectCache($id, $pivot['connections'][0]['table'], $this->database);
            
            if (!$where) $this->external[$var] = $results;
            
            return $results;
        }
        
        throw new Exception\Model("MODEL_DATA:UNKNOWN_FOREIGN_PROPERTY", ['property' => $var, 'data' => $this]);
    }
    
    public function joinCount($var, $where = [])
    {
        if (!is_null($this->external[$var]) && !$this->external[$var] instanceof Collection) return 1;
        if ($this->external[$var]) return $this->external[$var]->filter($where)->count();
        
        // If this Model_Data isn't linked to the db yet, then linked values cannot exist
        if (!$id = $this->data['id']) return 0;
        
        /* FOREIGN KEYS */
        // 1-1, just grab the object - not worth optimising
        if (key_exists($var, (array) $this->model['one-to-one'])) {
            /* Call Tablename::factory(foreign key id) to get the object we want */
            $table = $this->model['one-to-one'][$var];
            $this->external[$var] = Model::factoryObjectCache($id, $table, $this->database);
            return $this->external[$var] ? 1 : 0;
        }
        
        // M-1, just grab the object - not worth optimising
        if (key_exists($var, (array) $this->model['many-to-one'])) {        
            /* Call Tablename::factory(foreign key id) to get the object we want */
            $table = $this->model['many-to-one'][$var];
            $this->external[$var] = Model::factoryObjectCache($this->data[$var . '_id'], $table, $this->database);
            return $this->external[$var] ? 1 : 0;
        }
        
        /* Look for lists of objects in other tables referencing this one */
        if (key_exists($var, (array) $this->model['one-to-many'])) {
            
            $table = $this->model['one-to-many'][$var]['table'];
            $column = $this->model['one-to-many'][$var]['column_name'];
            
            // Use the model factory to find the relevant items
            list($data) = Model::factoryDataCount($where + [$column => $id], $table, $this->database);
            return $data['count'];
        }
        
        if (key_exists($var, (array) $this->model['many-to-many'])) {
            
            // Get pivot schema
            $pivot = $this->model['many-to-many'][$var];
            
            // We can only support simple connection access for 2 key pivots.
            if (count($pivot['connections']) != 1) throw new Exception\Model('MODEL_DATA:CANNOT_CALL_MULTIPIVOT_AS_PROPERTY', array($var));
            
            // Get a list of ids linked to this object (i.e. the tablename_id stored in the pivot table)
            $pivot_schema = $this->schema->getTable($pivot['pivot']);
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
            $q = QueryBuilder::select([$pivot_tablename => 'pivot'], ['pivot.*'])
                ->where(['`pivot`.'.$pivot['id'] => $this->data['id']])
                ->join([Schema::underscoreCase($pivot['connections'][0]['table']) => 'pivotjoin'])
                ->joinOn(['pivotjoin.id' => "`pivot`.`{$pivot['connections'][0]['column']}`"])
                ->where($clauses);
                
            $query = new Query($this->database);
            list($raw) = $query->sql($q)->execute();
            
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
        if (isset($this->data[$var])) return true;
        if (isset($this->external[$var])) return true;
        
        // Check through all the possible foreign keys for a matching name
        if (key_exists($var, (array) $this->model['one-to-one'])) return true;        
        if (key_exists($var, (array) $this->model['many-to-one'])) return true;
        if (key_exists($var, (array) $this->model['one-to-many'])) return true;
        if (key_exists($var, (array) $this->model['many-to-many'])) return true;
        
        return false;
    }
    
    public function __set($var, $value)
    {
        // Cannot change data if it is locked (i.e. it is attached to a Model object)
        if ($this->locked) throw new Exception\Model('MODEL_DATA:SET_WHEN_LOCKED', array($var, $value));
        
        // Cannot update primary key on existing objects
        // (and cannot set id for new objects that don't have a foreign primary key)
        if ($var == 'id' && $this->new == false && $this->model['type'] != 'foreign') {
            throw new Exception\Model('MODEL_DATA:CANNOT_CHANGE_ID', array($var, $value));
        }
        
        // Updating normal columns
        if (key_exists($var, $this->model['columns']))
        {
            if ($this->model['columns'][$var] == 'datetime'
                or $this->model['columns'][$var] == 'timestamp'
                or $this->model['columns'][$var] == 'date'
            ) {
                // Special checks for datetimes
                if ($value instanceof Time) { 
                    $this->data[$var] = $value;
                } elseif ($value instanceof \DateTime) { 
                    $this->data[$var] = new Time($value->format(Time::MYSQL_DATE), new \DateTimeZone('UTC'));
                } elseif (($datetime = strtotime($value)) !== false) { // Fall back to standard strings
                    $this->data[$var] = new Time(date(Time::MYSQL_DATE, $datetime), new \DateTimeZone('UTC'));
                } elseif (is_null($value)) { // Allow "null"
                    $this->data[$var] = null;
                } else { 
                    // Oops!
                    throw new Exception\Model('MODEL_DATA:DATETIME_VALUE_EXPECTED_FOR_COLUMN', array($var, $value));
                }
            } elseif (is_scalar($value) or is_null($value) or $value instanceof DB_String) {
                // Standard values
                $this->data[$var] = $value;
            } else {
                // Objects, arrays etc that cannot be stored in a db column. Explosion!
                throw new Exception\Model('MODEL_DATA:SCALAR_VALUE_EXPECTED_FOR_COLUMN', array($var, $value));
            }
            
            return;
        }
        
        // table_id -> Table - Foreign keys to other tables
        if (key_exists($var, (array) $this->model['many-to-one'])) {
            if (is_null($value)) {
                $this->data[$var.'_id'] = null;
                $this->external[$var] = null;
                return;
            } elseif ($value instanceof Model) {
                $this->data[$var.'_id'] = $value->id;
                $this->external[$var] = $value;
                return;
            } else {
                throw new Exception\Model('MODEL_DATA:MODEL_EXPECTED_FOR_KEY', array($var, $value));
            }
        }
        
        // Pivot tables - needs an array of appropriate objects for this column
        if (key_exists($var, (array) $this->model['many-to-many'])) {
            if (is_array($value)) $value = new Collection($value);
            if (!$value) $value = new Collection();
            
            // Still not got a valid collection? Boom!
            if (!$value instanceof Collection) throw new Exception\Model('MODEL_DATA:ARRAY_EXPECTED_FOR_PIVOT', array($var, $value));
            
            foreach($value as $obj) {                
                if (!$obj instanceof Model) throw new Exception\Model('MODEL_DATA:MODEL_EXPECTED_IN_PIVOT_ARRAY', array($var, $value, $obj));
            }
            
            $this->external[$var] = $value;
            return;
        }
        
        // Table::this_id -> this - Foreign keys on other tables pointing to this one - we cannot 'set' these here.
        // These values must be changes on their root tables (i.e. the table with the twin many-to-one relationship)
        if (key_exists($var, (array) $this->model['one-to-many'])) {
            throw new Exception\Model('MODEL_DATA:CANNOT_SET_EXTERNAL_KEYS_TO_THIS_TABLE', array($var, $value));
        }
        
        // Undefined column
        throw new Exception\Model('MODEL_DATA:UNEXPECTED_COLUMN_NAME', array($this->model, $var, $value));
    }
    
    public function commit()
    {
        // Create a new query
        $query = new Query($this->database);        
        $this->buildQuery($query);
        $values = $query->execute(true);
        
        // Don't return anything if we just deleted this row.
        if ($this->delete) {
            return null;  
        } 

        // Get the id we just inserted
        if ($this->new) {
            $this->new = false;
            return $query->insertId(0);
        }
        
        // Else return the esiting id.
        return $this->data['id'];
    }
    
    protected function buildQuery(&$query)
    {
        // [FIXME] [NikB] Why did I split this back out to update/insert rather than replace?
        // Log says "Fixed major overwriting problem in commit()" but what was getting overwritten?
        
        // Insert/Update the data, and store the insert id into a variable
        if ($this->delete) {
            $q = QueryBuilder::delete($this->table, ['id' => $this->data['id']]);
            $query->sql($q);
        } elseif ($this->new) {
            $q = QueryBuilder::insert($this->table, $this->data);
            $query->sql($q)->sql("SELECT last_insert_id() into @id");
        } else {
            $q = QueryBuilder::update($this->table, $this->data)->where(['id' => $this->data['id']]);
            $query->sql($q)->sql("SELECT ".$this->data['id']." into @id");
        }
        
        if (!$this->delete) {
            $origin_id = new SqlString('@id');
            
            // Foreign tables
            foreach ($this->external as $property_name => $value) {
                // Skip property if this isn't an M-M table (M-1 and 1-M tables are dealt with in other ways)
                if (!$pivot = $this->model['many-to-many'][$property_name]) continue;
                
                // We can only do updates support simple connection access for 2 key pivots.
                if (count($pivot['connections']) != 1) continue;
                
                // Get the table name of the pivot table for this property
                $table = Schema::underscoreCase($pivot['pivot']);
                
                // Clear out any existing data for this object - this is safe because we are in an atomic transaction.
                $query->sql("Delete from $table where {$pivot['id']} = @id");
                
                // Loops through the list of objects to link to this table
                foreach ($value as $object) {
                    $newdata = [
                        $pivot['id'] => $origin_id,      // Id of this object
                        $pivot['connections'][0]['column'] => $object->id  // Id of object linked to this object
                    ];
                    $query->sql(QueryBuilder::insert($table, $newdata));
                }
            }
        }
    }
    
    // Get the table that this object is attached to.
    public function getTable()
    {
        return $this->table;
    }
    
    public function getDatabase()
    {
        return $this->database;
    }
    
    public function getModel()
    {
        return $this->model;
    }

    public function getSchema()
    {
        return $this->schema;
    }
    
    public function externalKeyExists($var)
    {
        if (key_exists($var, (array) $this->model['one-to-one'])) return 'one-to-one';
        if (key_exists($var, (array) $this->model['one-to-many'])) return 'one-to-many';
        if (key_exists($var, (array) $this->model['many-to-one'])) return 'many-to-one';
        if (key_exists($var, (array) $this->model['many-to-many'])) return 'many-to-many';
        return null;
    }
    
    // By default, hide most of the schema internals of Data objects when var_dumping them!
    public function __debugInfo()
    {
        $return = get_object_vars($this);
        unset($return['schema']);
        unset($return['model']);
        return $return;
    }    
}