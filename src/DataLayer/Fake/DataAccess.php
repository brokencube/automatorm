<?php
namespace Automatorm\DataLayer\Fake;

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
            if (preg_match('/^\s*([a-z_]+)\s*\|/', $clean, $matches)) {
                // Table Declaration
                $tableName = trim($matches[1]);
                $currentTableName = Schema::normaliseCase($tableName);
                $currentTable = $schema[$currentTableName];
            } elseif (preg_match('/^\s*([a-z_]+)\s*->/', $clean, $matches)) {
                // Skip foreign key declaration
            } elseif ($rowdata = str_getcsv(trim($line))) {
                // If we have a parsable csv string, a tablename, and a matching number of columns
                
                if ($currentTableName) {
                    if (count($rowdata) == count($currentTable['columns'])) {
                        $combined = array_combine(array_keys($currentTable['columns']), $rowdata);
                        if (isset($combined['id'])) {
                            $this->tabledata[$currentTableName][$combined['id']] = $combined;
                        } else {
                            $this->tabledata[$currentTableName][] = $combined;
                        }
                    }
                }
            }
        }
    }
    
    public function commit($mode, $table, $id, $data, $externalData, $schema)
    {
        if ($mode == 'delete') {
            unset($this->tabledata[$table][$id]);
            return $id;
        }
        
        if ($mode == 'insert') {
            $id = max(array_keys($this->tabledata[$table])) + 1;
            $this->tabledata[$table][$id] = $data;
        } elseif ($mode == 'update') {
            $this->tabledata[$table][$id] = $data;
        }
        
        $originId = $id;
        
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
            $tablename = Schema::normaliseCase($pivot['pivot']);
            
            // Clear out any existing data for this object - this is safe because we are in an atomic transaction.
            foreach ($this->tabledata[$tablename] as $key => $row) {
                if ($row[$pivot['id']] == $id) {
                    unset($this->tabledata[$tablename][$key]);
                }
            }
            
            // Loops through the list of objects to link to this table
            foreach ($value as $object) {
                $this->tabledata[$tablename][] = [
                    $pivot['id'] => $originId,
                    $pivot['connections'][0]['column'] => $object->id
                ];
            }
        }
        
        return $id;
    }

    public function getData($table, $where, array $options = [])
    {
        $tablename = Schema::normaliseCase($table);
        $returnData = [];

        foreach ($this->tabledata[$tablename] as $id => $row) {
            foreach ($where as $column => $clause) {
                if (is_array($clause)) {
                    if (in_array($row[$column], $clause) === false) {
                        continue 2;
                    }
                } else {
                    if ($row[$column] != $clause) {
                        continue 2;
                    }
                }
            }
            $returnData[] = $row;
        }
        
        if (is_array($options)) {
            // Limit
            if (key_exists('limit', $options)) {
                $offset = key_exists('offset', $options) ? $options['offset'] : 0;
                $returnData = array_slice($returnData, $offset, $options['limit']);
            }
            
            // Sort
            if (key_exists('sort', $options)) {
                /*
                foreach ((array) $options['sort'] as $sortby) {
                    list($sort, $dir) = explode(' ', $sortby, 3);
                    $query->sortBy($sort, $dir);
                }
                */
            }
        }
        
        return $returnData;
    }
    
    public function getDataCount($table, $where, array $options = [])
    {
        $tablename = Schema::normaliseCase($table);
        $returnData = [];
        
        foreach ($this->tabledata[$tablename] as $id => $row) {
            foreach ($where as $column => $clause) {
                if (is_array($clause)) {
                    if (in_array($row[$column], $clause) === false) {
                        continue 2;
                    }
                } else {
                    if ($row[$column] != $clause) {
                        continue 2;
                    }
                }
                
                $returnData[] = $row;
            }
        }
        
        return count($returnData);
    }
    
    public function getM2MData($pivotTablename, $pivot, $ids, $joinwhere = null, $where = null)
    {
        $data = [];
        /*
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
        */
        return $data;
    }
}
