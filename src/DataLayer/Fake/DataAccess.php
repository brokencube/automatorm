<?php
namespace Automatorm\DataLayer\Fake;

use Automatorm\Interfaces\Connection as ConnectionInterface;
use Automatorm\Interfaces\DataAccess as DataAccessInterface;
use Automatorm\Orm\Schema;
use Automatorm\OperatorParser;

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
        list($schema, $database) = $this->connection->getSchemaGenerator()->generate();
        
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
    
    public function commit($mode, $table, $id, $data, $externalData, $schema) : int
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
                    $pivot['id'] => $id,
                    $pivot['connections'][0]['column'] => $object->id
                ];
            }
        }
        
        return $id;
    }

    public function getData($table, $where, array $options = []) : array
    {
        $tablename = Schema::normaliseCase($table);
        $returnData = $this->getTableData($tablename, $where);
        
        if (is_array($options)) {
            // Limit
            if (key_exists('limit', $options)) {
                $offset = key_exists('offset', $options) ? $options['offset'] : 0;
                $returnData = array_slice($returnData, $offset, $options['limit']);
            }
            
            // Sort
            if (key_exists('sort', $options)) {
                $sort = $options['sort'];
                usort($returnData, function ($a, $b) use ($sort) {
                    foreach ((array) $options['sort'] as $sortby) {
                        list($sort, $dir) = explode(' ', $sortby, 3);
                        if ($dir == 'desc') {
                            $result = natsort($a[$sort], $b[$sort]);
                        } else {
                            $result = natsort($b[$sort], $a[$sort]);
                        }
                        if ($result !== 0) {
                            return $result;
                        }
                    }
                });
            }
        }
        
        return $returnData;
    }
    
    protected function getTableData($table, $where)
    {
        $returnData = [];
        
        foreach ($this->tabledata[$table] as $id => $row) {
            foreach ($where as $column => $clause) {
                list($affix, $column) = OperatorParser::extractAffix($column, true);
                if (is_array($clause)) {
                    foreach ($clause as $value) {
                        if (OperatorParser::testOperator($affix, $row[$column], $value)) {
                            continue 3;
                        }
                    }
                } else {
                    if (OperatorParser::testOperator($affix, $row[$column], $clause)) {
                        continue 2;
                    }
                }
            }
            $returnData[] = $row;
        }
        return $returnData;
    }
    
    public function getDataCount($table, $where, array $options = []) : int
    {
        $tablename = Schema::normaliseCase($table);
        return count($this->getTableData($tablename, $where));
    }
    
    public function getM2MData($pivotSchema, $pivot, $ids, $where = []) : array
    {
        $pivotName = Schema::normaliseCase($pivotSchema['table_name']);
        $returnData = $this->getTableData($pivotName, []);
        
        $ids = [];
        foreach ($returnData as $row) {
            $ids[] = $row[$pivot['connections'][0]['column']];
        }
        
        // [FIXME] Do intersection with existing $search['id'];
        $search = $where + ['id' => $ids];
        $tableName = Schema::underscoreCase($pivot['connections'][0]['table']);
        
        $data = $this->getTableData($tableName, $search);
        
        foreach ($data as $row) {
            $return[] = [
                $pivot['connections'][0]['column'] => $row['id']
            ];
        }
        return $return;
    }
}
