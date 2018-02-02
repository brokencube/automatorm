<?php
namespace Automatorm\DataLayer\Fake;

use Automatorm\Interfaces\Connection as ConnectionInterface;
use Automatorm\Interfaces\DataAccess as DataAccessInterface;
use Automatorm\Orm\Schema;
use Automatorm\OperatorParser;

class Data
{
    public $connection;
    public $data;
    public $schemaName;
    public $tabledata = [];
    public $crossschema = [];
    
    public function __construct($data, $schemaName = 'default')
    {
        $this->schemaName = $schemaName;
        $this->data = $data;
    }
    
    public function generateData($connection)
    {
        list($schema, $database) = $connection->getSchemaGenerator()->generate();
        
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
            } elseif ($rowdata = str_getcsv(trim($line), ',', '\'')) {
                // If we have a parsable csv string, a tablename, and a matching number of columns
                if ($currentTableName) {
                    if (count($rowdata) == count($currentTable['columns'])) {
                        $combined = array_combine(array_keys($currentTable['columns']), $rowdata);
                        /* Special case '*null' as null */
                        foreach ($combined as $key => $value) {
                            if (strtolower($value) === '*null') {
                                $combined[$key] = null;
                            }
                            if (substr(strtolower($value), 0, 6) == '**null') {
                                $combined[$key] = substr($value, 1);
                            }
                        }
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
    
    public function addCrossSchemaForeignKey($table, $type, $column, $data)
    {
        $this->crossschema[] = [
            'table' => $table,
            'type' => $type,
            'column' => $column,
            'data' => $data
        ];
    }
    
    public function getCrossSchemaForeignKeys() : array
    {
        return $this->crossschema;
    }
    
    public function getData()
    {
        return $this->tabledata;
    }
    
    public function getTable($table)
    {
        return $this->tabledata[$table];
    }
    
    public function getRow($table, $id)
    {
        return $this->tabledata[$table][$id];
    }
    
    public function delete($table, $id)
    {
        unset($this->tabledata[$table][$id]);
    }
    
    public function autoincrementId($table)
    {
        return max(array_keys($this->tabledata[$table])) + 1;
    }
    
    public function addData($table, $id, $column, $value)
    {
        return $this->tabledata[$table][$id][$column] = $value;
    }
    
    public function addRow($table, $id, $data)
    {
        if (is_null($id)) {
            $id = $this->autoincrementId($table);
        }
        return $this->tabledata[$table][$id] = $data;
    }
}
