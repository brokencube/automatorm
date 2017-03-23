<?php
namespace Automatorm\Fake;

use Automatorm\Interfaces\Connection as ConnectionInterface;
use Automatorm\Interfaces\DataAccess as DataAccessInterface;
use Automatorm\Orm\Schema;

class DataAccess implements DataAccessInterface
{
    protected $connection;
    protected $data;
    protected $tabledata = [];
    
    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
        $this->data = $connection->connect();
        $this->generateData();
    }
    
    protected function generateData()
    {
        $schema = $this->connection->getSchemaGenerator()->generate();
        
        $currentTable = null;
        $currentTableName = null;
        
        foreach(preg_split('~[\r\n]+~', $this->data) as $line) {
            if (empty($line) or ctype_space($line)) {
                continue;
            }
            
            $matches = [];
            $clean = trim(strtolower($line));
            if (preg_match('/^\s*([a-z_]+)\s*|', $clean, $matches)) {
                // Table Declaration
                $tableName = trim($matches[1]);
                $currentTableName = Schema::normaliseCase($tableName);
                $currentTable = $schema[$currentTableName];
            } elseif (preg_match('/^\s*([a-z_]+)\s*->', $clean, $matches)) {
                // Skip foreign key declaration
            } elseif ($rowdata = str_getcsv(trim($line))) {
                // If we have a parsable csv string, a tablename, and a matching number of columns
                if ($tableName) {
                    if (count($rowdata) == count($currentTable['columns'])) {
                        $this->tabledata[$currentTableName][] = array_combine(array_keys($currentTable['columns']), $rowdata);
                    }
                }
            }
        }
    }
    
    public function commit($mode, $table, $id, $data, $externalData, $schema)
    {
        // Create a new query
        $query = new Query($this->connection);

        // Insert/Update the data, and store the insert id into a variable
        if ($mode == 'delete') {
            $sql = QueryBuilder::delete($table, ['id' => $id]);
            $query->sql($sql);
        } elseif ($mode == 'insert') {
            $sql = QueryBuilder::insert($table, $data);
            $query->sql($sql)->sql("SELECT last_insert_id() into @id");
        } elseif ($mode == 'update') {
            $sql = QueryBuilder::update($table, $data)->where(['id' => $id]);
            $query->sql($sql)->sql("SELECT {$id} into @id");
        }
        
        if ($mode != 'delete') {
            $originId = new SqlString('@id');
            
            // Foreign tables
            foreach ($externalData as $propertyName => $value) {
                // Skip property if this isn't an M-M table (M-1 and 1-M tables are dealt with in other ways)
                if (!$pivot = $schema['many-to-many'][$propertyName]) {
                    continue;
                }
                
                // We can only do updates support simple connection access for 2 key pivots.
                if (count($pivot['connections']) != 1) {
                    continue;
                }
                
                // Get the table name of the pivot table for this property
                $tablename = Schema::underscoreCase($pivot['pivot']);
                
                // Clear out any existing data for this object - this is safe because we are in an atomic transaction.
                $query->sql("Delete from $tablename where {$pivot['id']} = @id");
                
                // Loops through the list of objects to link to this table
                foreach ($value as $object) {
                    $newdata = [
                        $pivot['id'] => $originId,      // Id of this object
                        $pivot['connections'][0]['column'] => $object->id  // Id of object linked to this object
                    ];
                    $query->sql(QueryBuilder::insert($tablename, $newdata, true));
                }
            }
        }
        
        $query->transaction();
        $query->execute();
        $query->commit();
        
        // Don't return anything if we just deleted this row.
        if ($mode == 'delete') {
            return null;
        }

        // Get the id we just inserted
        if ($mode == 'insert') {
            return $query->insertId(0);
        }
        
        // Else return the existing id.
        return $id;
    }

    public function getData($table, $where, $options)
    {
        // Select * from $table where $where
        $query = QueryBuilder::select($table)->where($where);
        
        if (is_array($options)) {
            // Limit
            if (key_exists('limit', $options)) {
                $query->limit($options['limit'], is_null($options['offset']) ? null : $options['offset']);
            }
            
            // Sort
            if (key_exists('sort', $options)) {
                foreach ((array) $options['sort'] as $sortby) {
                    list($sort, $dir) = explode(' ', $sortby, 3);
                    $query->sortBy($sort, $dir);
                }
            }
        }
        
        list($data) = Query::run($query, $this->connection);
        return $data;
    }
    
    public function getDataCount($table, $where, $options)
    {
        // Select * from $table where $where
        $query = QueryBuilder::count($table)->where($where);
        
        if (is_array($options) && key_exists('limit', $options)) {
            $query->limit($options['limit'], is_null($options['offset']) ? null : $options['offset']);
        }
        
        list($data) = Query::run($query, $this->connection);
        return $data;
    }
    
    public function getM2MData($pivotTablename, $pivot, $ids, $joinwhere = null, $where = null)
    {
        $query = QueryBuilder::select([$pivotTablename => 'pivot'], ['pivot.*'])
            ->where(['`pivot`.'.$pivot['id'] => $ids])
            ->join([Schema::underscoreCase($pivot['connections'][0]['table']) => 'pivotjoin'])
            ->joinOn(['pivotjoin.id' => "`pivot`.`{$pivot['connections'][0]['column']}`"]);
            
        if ($joinwhere) {
            $query->joinWhere($joinwhere);
        }
        if ($where) {
            $query->where($where);
        }
        
        list($data) = Query::run($query, $this->connection);
        return $data;
    }
}
