<?php
namespace Automatorm\Orm;

use Automatorm\Exception;
use Automatorm\Database\SqlString;

class Data
{
    protected $__data = [];           // Data from columns on this table
    protected $__external = [];       // Links to foreign key objects
    
    protected $__update = [];         // Keys to be updated
    protected $__updateExternal = []; // Foreign keys to be updated
    
    protected $__schema;              // Schema object for this database
    protected $__namespace;           // Namespace of the Model for this data - used to find Schema again
    protected $__table;               // Class this data is associated with
    protected $__model;               // Fragment of Schema object for this table
    
    protected $__locked = true;       // Can we use __set() - for updates/inserts
    protected $__new = false;         // Is this to be a new row? (used with Model::new_db())
    protected $__delete = false;      // Row is marked for deletion
    
    public function __construct(array $data, $table, Schema $schema, $new = false)
    {
        $this->__table = $table;
        $this->__schema = $schema;
        $this->__namespace = $schema->namespace;
        $this->__model = $schema->getTable($table);
        $this->__new = $new;
        $this->__locked = !$new;
        
        $this->updateData($data);
    }
    
    public function updateData($data)
    {
        $this->__data = [];
        
        // Pull in data from $data
        foreach ($data as $key => $value) {
            // Make a special object for dates
            if (!is_null($value) and (
                $this->__model['columns'][$key] == 'datetime'
                or $this->__model['columns'][$key] == 'timestamp'
                or $this->__model['columns'][$key] == 'date'
            )) {
                $this->__data[$key] = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
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
    
    /**
     * Create a open cloned copy of this object, ready to reinsert as a new row.
     *
     * @param bool $cloneExternalProps Clone M2M properties as well
     * @return self
     */
    public function duplicate($cloneExternalProps = false)
    {
        $clone = clone $this;
        $clone->__new = true;
        $clone->__delete = false;
        unset($clone->__data['id']);
        foreach (array_keys($clone->__data) as $key) {
            $clone->__update[$key] = true;
        }
        
        // Clone M-M joins
        if ($cloneExternalProps) {
            foreach (array_keys($this->__model['many-to-many']) as $key) {
                $clone->__external[$key] = $this->{$key};
                $clone->__updateExternal[$key] = true;
            }
        }
        
        return $clone;
    }

    /**
     * Mark data object for deletion when commited
     *
     * @return self
     */
    public function delete()
    {
        if ($this->__new) {
            throw new Exception\Model('MODEL_DATA:CANNOT_DELETE_UNCOMMITED_DATA');
        }
        $this->__delete = true;
        return $this;
    }
    
    // Accessor method for object properties (columns from the db)
    public function &__get($var)
    {
        /* This property is a native database column, return it */
        if (isset($this->__data[$var])) {
            return $this->__data[$var];
        }
        
        /* This property has already been defined, return it */
        if (isset($this->__external[$var])) {
            $data = $this->__external[$var];
            
            // If this data object isn't locked, it's likely being used for a insert/update.
            // If ->var contains a Collection, this must be a M2M relationship - mark it for update
            if (!$this->__locked && $data instanceof Collection) {
                $this->__updateExternal[$var] = true;
            }
            
            return $data;
        }
        
        /* This property hasn't been defined, so it's not one of the table columns.
         * We want to look at foreign keys and pivots
         * 
         * If we try to access a foreign key column without the _id on the end assume we want the object, not the id
         * From example at the top: $proj->account_id returns 1      $proj->account returns Account object with id 1
         */
        
        try {
            $data = $this->join($var);
            
            // If this Data object isn't locked, it's likely being used for a insert/update.
            // If ->var contains a Collection, this must be a M2M relationship - mark it for update
            if (!$this->__locked && $data instanceof Collection) {
                $this->__updateExternal[$var] = true;
            }
            
            return $data;
        } catch (Exception\Model $e) {
            if ($e->code == 'MODEL_DATA:UNKNOWN_FOREIGN_PROPERTY') {
                return null;
            }
            throw $e;
        }
    }
    
    public static function groupJoin(Collection $collection, $var, $where = [], $onlyCount = false)
    {
        if (!$collection->count()) {
            return $collection;
        }
        
        /* FOREIGN KEYS */
        if (key_exists($var, $proto->__model['one-to-one'])) {
            return static::groupJoin121($collection, $var, $where, $onlyCount);
        }
        
        if (key_exists($var, $proto->__model['many-to-one'])) {
            return static::groupJoinM21($collection, $var, $where, $onlyCount);
        }
        
        if (key_exists($var, $proto->__model['one-to-many'])) {
            return static::groupJoin12M($collection, $var, $where, $onlyCount);
        }
        
        if (key_exists($var, $proto->__model['many-to-many'])) {
            return static::groupJoinM2M($collection, $var, $where, $onlyCount);
        }
        
        throw new Exception\Model('MODEL:CALLED_GROUP_JOIN_ON_UNKNOWN_FOREIGN_PROPERTY', [$var, $collection]);
    }

    protected static function groupJoin121(Collection $collection, $var, $where, $countOnly = false)
    {
        $proto = $collection[0]->_data;
        $ids = $collection->id->toArray();
        
        /* Call Tablename::factory(foreign key id) to get the object we want */
        $table = $proto->__model['one-to-one'][$var]['table'];
        $schema = Schema::getSchemaByName($proto->__model['one-to-one'][$var]['schema']);
        
        if ($countOnly) {
            list($data) = static::factoryDataCount(['id' => $ids] + $where, $table, $schema);
            return $data['count'];
        }
        
        return Model::factoryObjectCache($ids, $table, $schema);
    }

    protected static function groupJoinM21(Collection $collection, $var, $where, $countOnly = false)
    {
        $proto = $collection[0]->_data;
        
        // Remove duplicates from the group
        $ids = array_unique($collection->{$var . '_id'}->toArray());
        
        $table = $proto->__model['many-to-one'][$var]['table'];
        $schema = Schema::getSchemaByName($proto->__model['many-to-one'][$var]['schema']);
        
        if ($countOnly) {
            list($data) = static::factoryDataCount(['id' => $ids] + $where, $table, $schema);
            return $data['count'];            
        }
        
        if (!$where) {
            $results = Model::factoryObjectCache($ids, $table, $schema);

            // Store the object results on the relevant objects
            foreach ($collection as $obj) {
                $obj->_data->__external[$var] =
                    Model::factoryObjectCache($obj->{$var . '_id'}, $table, $schema);
            }
            return $results;
        }
        
        return Model::factory($where + ['id' => $ids], $table, $schema);
    }
    
    protected static function groupJoin12M(Collection $collection, $var, $where, $countOnly = false)
    {
        $proto = $collection[0]->_data;

        $table = $proto->__model['one-to-many'][$var]['table'];
        $column = $proto->__model['one-to-many'][$var]['column_name'];
        $schema = Schema::getSchemaByName($proto->__model['one-to-many'][$var]['schema']);
        
        $ids = $collection->id->toArray();
        
        if ($countOnly) {
            list($data) = static::factoryDataCount([$column => $ids] + $where, $table, $schema);
            return $data['count'];
        }
        
        // Use the model factory to find the relevant items
        $results = Model::factory($where + [$column => $ids], $table, $schema);
        
        // If we didn't use a filter, store the relevant results in each object
        if (!$where) {
            foreach ($results as $obj) {
                $external[$obj->$column][] = $obj;
            }
            
            foreach ($collection as $obj) {
                $obj->_data->__external[$var] = new Collection((array) $external[$obj->id]);
            }
        }
        
        return $results;
    }

    protected static function groupJoinM2M(Collection $collection, $var, $where, $countOnly = false)
    {
        $results = new Collection();
        $proto = $collection[0]->_data;

        // Get pivot schema
        $pivot = $proto->__model['many-to-many'][$var];
        $ids = $collection->id->toArray();
        
        // We can only support simple connection access for 2 key pivots.
        if (count($pivot['connections']) != 1) {
            throw new Exception\Model('MODEL_DATA:CANNOT_CALL_MULTIPIVOT_AS_PROPERTY', array($var));
        }
        
        // Get a list of ids linked to this object (i.e. the tablename_id stored in the pivot table)
        $pivotSchema = $proto->__schema->getTable($pivot['pivot']);
        $pivotCon = $pivot['connections'][0];
        $pivotConSchema = Schema::getSchemaByName($pivotCon['schema']);
        
        $raw = $proto->getDataAccessor()->getM2MData(
            $pivotSchema,
            $pivot,
            $ids,
            $where
        );
        
        // Rearrange the list of ids into a flat array and an id grouped array
        $flatIds = [];
        $groupedIds = [];
        foreach ($raw as $rawId) {
            $flatIds[] = $rawId[$pivotCon['column']];
            $groupedIds[$rawId[$pivot['id']]][] = $rawId[$pivotCon['column']];
        }
        
        // Remove duplicates to make sql call smaller.
        $flatIds = array_unique($flatIds);
        
        if ($countOnly) {
            list($data) = static::factoryDataCount(['id' => $flatIds] + $where, $pivotCon['table'], $pivotConSchema);
            return $data['count'];    
        }
        
        // Use the model factory to retrieve the objects from the list of ids (using cache first)
        $results = Model::factoryObjectCache($flatIds, $pivotCon['table'], $pivotConSchema);
        
        // If we don't have a filter ($where), then we can split up the results per object and store the
        // results relevant to the result on that object. The calls to Model::factoryObjectCache below will never
        // hit the database, because all of the possible objects were returned in the call above.
        if (!$where) {
            foreach ($collection as $obj) {
                $data = Model::factoryObjectCache($groupedIds[$obj->id], $pivotCon['table'], $pivotConSchema);
                $obj->_data->__external[$var] = $data ?: new Collection;
            }
        }
        
        return $results;
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
        if (array_key_exists($var, $this->__external)) {
            return $this->__external[$var]->filter($where);
        }
        
        // If this Model_Data isn't linked to the db yet, then linked values cannot exist
        if (!$id = $this->__data['id']) {
            return new Collection();
        }
        
        /* FOREIGN KEYS */
        if (key_exists($var, (array) $this->__model['one-to-one'])) {
            /* Call Tablename::factory(foreign key id) to get the object we want */
            $table = $this->__model['one-to-one'][$var]['table'];
            $schema = $this->__model['one-to-one'][$var]['schema'];
            $this->__external[$var] = Model::factoryObjectCache($id, $table, Schema::getSchemaByName($schema));
            
            return $this->__external[$var];
        }
        
        if (key_exists($var, (array) $this->__model['many-to-one'])) {
            /* Call Tablename::factory(foreign key id) to get the object we want */
            $table = $this->__model['many-to-one'][$var]['table'];
            $schema = $this->__model['many-to-one'][$var]['schema'];
            $this->__external[$var] = Model::factoryObjectCache($this->__data[$var . '_id'], $table, Schema::getSchemaByName($schema));
            
            return $this->__external[$var];
        }
        
        /* Look for lists of objects in other tables referencing this one */
        if (key_exists($var, (array) $this->__model['one-to-many'])) {
            $table = $this->__model['one-to-many'][$var]['table'];
            $schema = $this->__model['one-to-many'][$var]['schema'];
            $column = $this->__model['one-to-many'][$var]['column_name'];
            
            // Use the model factory to find the relevant items
            $results = Model::factory($where + [$column => $id], $table, Schema::getSchemaByName($schema));
            
            if (empty($where)) {
                $this->__external[$var] = $results;
            }
            
            return $results;
        }
        
        if (key_exists($var, (array) $this->__model['many-to-many'])) {
            // Get pivot schema
            $pivot = $this->__model['many-to-many'][$var];
            
            // We can only support simple connection access for 2 key pivots.
            if (count($pivot['connections']) != 1) {
                throw new Exception\Model('MODEL_DATA:CANNOT_CALL_MULTIPIVOT_AS_PROPERTY', array($var));
            }
            
            // Get a list of ids linked to this object (i.e. the tablename_id stored in the pivot table)
            $pivotSchema = $this->__schema->getTable($pivot['pivot']);
            $pivotCon = $pivot['connections'][0];
            
            $clauses = [];
            if ($where) {
                foreach ($where as $clauseColumn => $clauseValue) {
                    // Rewrite $where clauses to insert `pivotjoin` table in column name
                    preg_match('/^([!=<>%]*)(.+?)([!=<>%]*)$/', $clauseColumn, $parts);
                    $prefix = $parts[1] ?: $parts[3];
                    $clauseColumn = $parts[2];
                
                    $clauses['`pivotjoin`.`' . $clauseColumn . '`' . $prefix] = $clauseValue;
                }
            }
            
            // Build Query
            $raw = $this->getDataAccessor()->getM2MData(
                $pivotSchema,
                $pivot,
                $this->__data['id'],
                null,
                $clauses
            );
            
            // Rearrange the list of ids into a flat array
            $id = [];
            foreach ($raw as $rawId) {
                $id[] = $rawId[$pivotCon['column']];
            }
            
            // Use the model factory to retrieve the objects from the list of ids (using cache first)
            $results = Model::factoryObjectCache($id, $pivotCon['table'], Schema::getSchemaByName($pivotCon['schema']));
            
            if (!$where) {
                $this->__external[$var] = $results;
            }
            
            return $results;
        }
        
        throw new Exception\Model("MODEL_DATA:UNKNOWN_FOREIGN_PROPERTY", ['property' => $var, 'data' => $this]);
    }
    
    public function joinCount($var, $where = [])
    {
        if (!is_null($this->__external[$var]) && !$this->__external[$var] instanceof Collection) {
            return 1;
        }
        if ($this->__external[$var]) {
            return $this->__external[$var]->filter($where)->count();
        }
        
        // If this Model_Data isn't linked to the db yet, then linked values cannot exist
        if (!$id = $this->__data['id']) {
            return 0;
        }
        
        /* FOREIGN KEYS */
        // 1-1, just grab the object - not worth optimising
        if (key_exists($var, (array) $this->__model['one-to-one'])) {
            /* Call Tablename::factory(foreign key id) to get the object we want */
            $table = $this->__model['one-to-one'][$var]['table'];
            $schema = $this->__model['one-to-one'][$var]['schema'];
            $this->__external[$var] = Model::factoryObjectCache($id, $table, Schema::getSchemaByName($schema));
            return $this->__external[$var] ? 1 : 0;
        }
        
        // M-1, just grab the object - not worth optimising
        if (key_exists($var, (array) $this->__model['many-to-one'])) {
            /* Call Tablename::factory(foreign key id) to get the object we want */
            $table = $this->__model['many-to-one'][$var]['table'];
            $schema = $this->__model['many-to-one'][$var]['schema'];
            $this->__external[$var] = Model::factoryObjectCache($this->__data[$var . '_id'], $table, Schema::getSchemaByName($schema));
            return $this->__external[$var] ? 1 : 0;
        }
        
        /* Look for lists of objects in other tables referencing this one */
        if (key_exists($var, (array) $this->__model['one-to-many'])) {
            $table = $this->__model['one-to-many'][$var]['table'];
            $schema = $this->__model['one-to-many'][$var]['schema'];
            $column = $this->__model['one-to-many'][$var]['column_name'];
            
            // Use the model factory to find the relevant items
            list($data) = static::factoryDataCount($where + [$column => $id], $table, Schema::getSchemaByName($schema));
            return $data['count'];
        }
        
        if (key_exists($var, (array) $this->__model['many-to-many'])) {
            // Get pivot schema
            $pivot = $this->__model['many-to-many'][$var];
            
            // We can only support simple connection access for 2 key pivots.
            if (count($pivot['connections']) != 1) {
                throw new Exception\Model('MODEL_DATA:CANNOT_CALL_MULTIPIVOT_AS_PROPERTY', array($var));
            }
            
            // Get a list of ids linked to this object (i.e. the tablename_id stored in the pivot table)
            $pivotSchema = $this->__schema->getTable($pivot['pivot']);
            $pivotCon = $pivot['connections'][0];
            
            $clauses = [];
            if ($where) {
                foreach ($where as $clauseColumn => $clauseValue) {
                    // Rewrite $where clauses to insert `pivotjoin` table in column name
                    preg_match('/^([!=<>%]*)(.+?)([!=<>%]*)$/', $clauseColumn, $parts);
                    $prefix = $parts[1] ?: $parts[3];
                    $clauseColumn = $parts[2];
                
                    $clauses['`pivotjoin`.`' . $clauseColumn . '`' . $prefix] = $clauseValue;
                }
            }
            
            // Build Query
            $raw = $this->getDataAccessor()->getM2MData(
                $pivotSchema,
                $pivot,
                $this->__data['id'],
                null,
                $clauses
            );
            
            // Rearrange the list of ids into a flat array
            $id = array();
            foreach ($raw as $rawId) {
                $id[] = $rawId[$pivotCon['column']];
            }
            $dedup = array_unique($id);
            
            return count($dedup);
        }
        
        throw new Exception\Model("MODEL_DATA:UNKNOWN_FOREIGN_PROPERTY", ['property' => $var, 'data' => $this]);
    }
    
    public function __isset($var)
    {
        // Is it already set in local array?
        if (array_key_exists($var, $this->__data)) {
            return true;
        }
        if (array_key_exists($var, $this->__external)) {
            return true;
        }
        
        // Check through all the possible foreign keys for a matching name
        if (array_key_exists($var, (array) $this->__model['one-to-one'])) {
            return true;
        }
        if (array_key_exists($var, (array) $this->__model['many-to-one'])) {
            return true;
        }
        if (array_key_exists($var, (array) $this->__model['one-to-many'])) {
            return true;
        }
        if (array_key_exists($var, (array) $this->__model['many-to-many'])) {
            return true;
        }
        
        return false;
    }
    
    public function __set($var, $value)
    {
        // Cannot change data if it is locked (i.e. it is attached to a Model object)
        if ($this->__locked) {
            throw new Exception\Model('MODEL_DATA:SET_WHEN_LOCKED', array($var, $value));
        }
        
        // Cannot update primary key on existing objects
        // (and cannot set id for new objects that don't have a foreign primary key)
        if ($var == 'id' && $this->__new == false && $this->__model['type'] != 'foreign') {
            throw new Exception\Model('MODEL_DATA:CANNOT_CHANGE_ID', array($var, $value));
        }
        
        // Updating normal columns
        if (key_exists($var, $this->__model['columns'])) {
            return $this->__data[$var] = $this->setColumnData($var, $value);
        }
        
        // table_id -> Table - Foreign keys to other tables
        if (key_exists($var, (array) $this->__model['many-to-one'])) {
            list(
                $this->__data[$var . '_id'],
                $this->__external[$var]
            ) = $this->setManyToOneData($var, $value);
            $this->__update[$var . '_id'] = true;
            return;
        }
        
        // Pivot tables - needs an array of appropriate objects for this column
        if (key_exists($var, (array) $this->__model['many-to-many'])) {
            return $this->__external[$var] = $this->setManyToManyData($var, $value);
        }
        
        // Table::this_id -> this - Foreign keys on other tables pointing to this one - we cannot 'set' these here.
        // These values must be changes on their root tables (i.e. the table with the twin many-to-one relationship)
        if (key_exists($var, (array) $this->__model['one-to-many'])) {
            throw new Exception\Model('MODEL_DATA:CANNOT_SET_EXTERNAL_KEYS_TO_THIS_TABLE', array($var, $value));
        }
        
        // Undefined column
        throw new Exception\Model('MODEL_DATA:UNEXPECTED_COLUMN_NAME', array($this->__model, $var, $value));
    }
    
    protected function setColumnData($var, $value)
    {
        $this->__update[$var] = true;
        
        if ($this->__model['columns'][$var] == 'datetime'
            or $this->__model['columns'][$var] == 'timestamp'
            or $this->__model['columns'][$var] == 'date'
        ) {
            // Special checks for datetimes
            // Special case for "null"
            if (is_null($value)) {
                return null;
            } elseif ($value instanceof \DateTimeInterface) {
                return $value;
            } elseif (($datetime = strtotime($value)) !== false) { // Fall back to standard strings
                return new \DateTimeImmutable('@' . $datetime, new \DateTimeZone('UTC'));
            } elseif (is_int($value)) { // Fall back to unix timestamp
                return new \DateTimeImmutable('@' . $value, new \DateTimeZone('UTC'));
            } else {
                // Oops!
                throw new Exception\Model('MODEL_DATA:DATETIME_VALUE_EXPECTED_FOR_COLUMN', array($var, $value));
            }
        } elseif (is_scalar($value) or is_null($value) or $value instanceof SqlString) {
            // Standard values
            return $value;
        }
        
        // Objects, arrays etc that cannot be stored in a db column. Explosion!
        throw new Exception\Model('MODEL_DATA:SCALAR_VALUE_EXPECTED_FOR_COLUMN', array($var, $value));
    }
    
    protected function setManyToOneData($var, $value)
    {
        $this->__updateExternal[$var] = true;
        
        if (is_null($value)) {
            return [null, null];
        } elseif ($value instanceof Model) {
            // Trying to pass in the wrong table for the relationship!
            // That is, the table name on the foreign key does not match the table name in the passed Model object
            $valueTable = Schema::normaliseCase($value->dataOriginal()->__table);
            $expectedTable = $this->__model['many-to-one'][$var]['table'];
            
            if ($valueTable !== $expectedTable) {
                throw new Exception\Model('MODEL_DATA:INCORRECT_MODEL_FOR_RELATIONSHIP', [$var, $valueTable, $expectedTable]);
            }
            return [$value->id, $value];
        } else {
            throw new Exception\Model('MODEL_DATA:MODEL_EXPECTED_FOR_KEY', [$var, $value]);
        }        
    }
    
    protected function setManyToManyData($var, $value)
    {
        $this->__updateExternal[$var] = true;
        
        if (is_null($value)) {
            return new Collection();
        }
        
        if (is_array($value)) {
            $value = new Collection($value);
        }
        
        // Still not got a valid collection? Boom!
        if (!$value instanceof Collection) {
            throw new Exception\Model('MODEL_DATA:ARRAY_EXPECTED_FOR_PIVOT', [$var, $value]);
        }
        
        foreach ($value as $obj) {
            if (!$obj instanceof Model) {
                throw new Exception\Model('MODEL_DATA:MODEL_EXPECTED_IN_PIVOT_ARRAY', [$var, $value, $obj]);
            }
        }
        
        return $value;
    }
    
    public function assign(array $data, array $validkeys)
    {
        try {
            foreach ($validkeys as $key) {
                if (array_key_exists($key, $data)) {
                    $this->__set($key, $data[$key]);
                }
            }
            return $this;
        } catch (Exception\Model $e) {
            throw new Exception\Model(' ', [$data, $validkeys], $e);
        }
    }
    
    public function commit()
    {
        // Determine the type of SQL instruction to run
        if ($this->__delete) {
            $mode = 'delete';
        } elseif ($this->__new) {
            $mode = 'insert';
        } else {
            $mode = 'update';
        }
        
        // Collect the updated columns/foreign keys
        $columndata = [];
        foreach (array_keys($this->__update) as $key) {
            $columndata[$key] = $this->__data[$key];
        }

        $externaldata = [];
        foreach (array_keys($this->__updateExternal) as $key) {
            $externaldata[$key] = $this->__external[$key];
        }
        
        // Use connection's dataAccessor to commit the data to the db
        $id = $this->getDataAccessor()->commit(
            $mode,
            $this->__table,
            $this->__data['id'],
            $columndata,
            $externaldata,
            $this->__model
        );
        
        // Reset flags on this object
        $this->__new = false;
        $this->__locked = true;
        
        // Clear update fields
        $this->__update = [];
        $this->__updateExternal = [];
        
        // Clear cached foreign key data
        $this->__external = [];

        // Get clean version of data from database (in case of db triggers etc)
        if ($mode != 'delete') {
            list($data) = $this->getDataAccessor()->getData($this->__table, ['id' => $id]);
            $this->updateData($data);
        }
        
        return $this;
    }
  
    // Get the table that this object is attached to.
    public function getTable()
    {
        return $this->__table;
    }
    
    public function getConnection()
    {
        return $this->__schema->connection;
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
        return $this->__schema->connection->getDataAccessor();
    }
    
    public function externalKeyExists($var)
    {
        if (key_exists($var, (array) $this->__model['one-to-one'])) {
            return 'one-to-one';
        }
        if (key_exists($var, (array) $this->__model['one-to-many'])) {
            return 'one-to-many';
        }
        if (key_exists($var, (array) $this->__model['many-to-one'])) {
            return 'many-to-one';
        }
        if (key_exists($var, (array) $this->__model['many-to-many'])) {
            return 'many-to-many';
        }
        return null;
    }
    
    public function clearCache()
    {
        if (!$this->__locked) {
            throw new Exception\Model('CANNOT_CLEAR_UNLOCKED_DATA_OBJECTS', [$this]);
        }
        $this->__external = [];
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
        $this->__schema = Schema::get($this->__namespace);
        $this->__model = $this->__schema->getTable($this->__table);
    }
    
    // Get data from database from which we can construct Model objects
    final public static function factoryData($where, $table, Schema $schema, array $options = [])
    {
        return $schema->connection->getDataAccessor()->getData($table, $where, $options);
    }

    // Get data from database from which we can construct Model objects
    final public static function factoryDataCount($where, $table, Schema $schema, array $options = [])
    {
        return $schema->connection->getDataAccessor()->getDataCount($table, $where, $options);
    }    
}
