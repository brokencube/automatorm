<?php

namespace Automatorm\DataLayer\Fake;

use Automatorm\Interfaces\SchemaGenerator as SchemaGeneratorInterface;
use Automatorm\Interfaces\Connection as ConnectionInterface;
use Automatorm\Orm\Schema;
use Automatorm\Exception;

class SchemaGenerator implements SchemaGeneratorInterface
{
    protected $connection;
    
    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }
    
    protected function generateTableList($data)
    {
        foreach(preg_split('~[\r\n]+~', $data) as $line) {
            if (empty($line) or ctype_space($line)) {
                continue;
            }
            
            $matches = [];
            $clean = trim(strtolower($line));
            if (preg_match('/^\s*([a-z_]+)\s*\|/', $clean, $matches)) {
                $tableName = trim($matches[1]);
                $columns = [];
                $rowDefinition = explode(',', substr($clean, strpos($clean, '|') + 1));
                foreach ($rowDefinition as $columnDefinition) {
                    $columnDefinition = explode(':', $columnDefinition);
                    $columnname = trim($columnDefinition[0]);
                    $type = 'text';
                    foreach (array_slice($columnDefinition, 1) as $key => $value) {
                        switch(trim($value)) {
                            case 'int':
                                $type = 'int';
                                break;
                            case 'text':
                                $type = 'text';
                                break;
                            case 'date':
                            case 'time':
                            case 'datetime':
                                $type = 'datetime';
                                break;
                        }
                    }
                    
                    $columns[$columnname] = $type;
                }
                
                yield $tableName => $columns;
            }
        }
    }
    
    protected function generateForeignKeys($data)
    {
        $tableName = null;
        $keys = [];
        
        foreach(preg_split('~[\r\n]+~', $data) as $line) {
            if (empty($line) or ctype_space($line)) {
                continue;
            }
            
            $matches = [];
            $clean = trim(strtolower($line));
            if (preg_match('/^\s*([a-z_]+)\s*\|/', $clean, $matches)) {
                if ($tableName) yield $tableName => $keys;
                $keys = [];
                $tableName = trim($matches[1]);
                continue;
            }
            
            if (preg_match('/^\s*([a-z_]+)\s*->\s*([a-z_]+)\s*\|\s*([a-z_]+)\s*$/', $clean, $matches)) {
                $keys[$matches[1]] = ['table' => $matches[2], 'column_name' => $matches[3]];
            }
        }
        
        if ($tableName) {
            yield $tableName => $keys;
        }
    }
    
    // Generate Schema
    public function generate()
    {
        $data = $this->connection->connect();
        $database = $this->connection->schemaName;
        
        foreach ($this->generateTableList($data) as $tableName => $rows) {
            $normalised = Schema::normaliseCase($tableName);
            $model[$normalised] = [
                'table_name' => $tableName,
                'type' => 'table',
                'columns' => $rows,
                'one-to-one' => [],
                'one-to-many' => [],
                'many-to-one' => [],
                'many-to-many' => [],
            ];
        }
        
        foreach ($this->generateForeignKeys($data) as $table => $keys) {
            $tableName = Schema::normaliseCase($table);
            foreach ($keys as $columnName => $key) {
                $refTableName = Schema::normaliseCase($key['table']);
                
                if ($columnName == $key['column_name']) {
                    $model[$refTableName]['one-to-one'][Schema::underscoreCase($tableName)] = [
                        'table' => $tableName,
                        'schema' => $database
                    ];
                    $model[$tableName]['one-to-one'][Schema::underscoreCase($refTableName)] = [
                        'table' => $refTableName,
                        'schema' => $database
                    ];
                    $model[$tableName]['type'] = 'foreign';
                } elseif ($key['column_name'] == 'id') {
                    // if this foreign key points at one 'id' column then this is a usable foreign 'key'
                    if (substr($columnName, -3) == '_id') {
                        $columnRoot = substr($columnName, 0, -3);
                        $model[$tableName]['many-to-one'][$columnRoot] = [
                            'table' => $refTableName,
                            'schema' => $database
                        ];
                        
                        // Add the key constraint in reverse, trying to make a sensible name.
                        // If the column name was derived from the table name, just use the table name.
                        // (e.g "my_account" table and "my_account_id" -> my_account)
                        // Otherwise, append the column name to the table name to make sure it is unique.
                        // (e.g "your_account" table and "my_account_id" -> your_account_my_account)
                        if ($columnRoot == $key['table']) {
                            $propertyName = Schema::underscoreCase($tableName);
                        } else {
                            $propertyName = Schema::underscoreCase($tableName) . '_' . $columnRoot;
                        }
                        
                        $model[$refTableName]['one-to-many'][$propertyName] = [
                            'schema' => $database,
                            'table' => $tableName,
                            'column_name' => $columnName
                        ];
                    }
                }
            }
        }
        
        /////////////////////////////
        
        // Now look for pivot tables
        foreach ($model as $pivottablename => $pivot) {
            // If we have found a table with only foreign keys then this must be a pivot table
            if (count($pivot['many-to-one']) > 1 and count($pivot['columns']) == count($pivot['many-to-one'])) {
                // Grab all foreign keys and rearrange them into arrays.
                $tableinfo = [];
                foreach ($pivot['many-to-one'] as $column => $tablename) {
                    $tableinfo[] = [
                        'column' => $column . '_id',
                        'column_raw' => $column,
                        'table' => $tablename,
                        'schema' => $database
                    ];
                }
                
                // For each foreign key, store details in the table it point to on how to get to the OTHER table in the "Many to Many" relationship
                foreach ($tableinfo as $i => $table) {
                    // If the column name is named based on the foreign table name, then use the pivot table name as the property name
                    // This is the normal/usual case
                    if ($table['column'] == Schema::underscoreCase($table['table']) . '_id') {
                        $propertyName = Schema::underscoreCase($pivottablename);
                    } else {
                        // Else append the column name to the pivot table name.
                        // This is mostly for when a pivot table references the same table twice, and so
                        // needs to have a unique name for at least one of the columns (which is not based on the table name)
                        $propertyName = Schema::underscoreCase($pivottablename) . '_' . $table['column_raw'];
                    }
                    
                    // Outersect of tables to create an array of all OTHER foreign keys in this table, for this foreign key.
                    $othertables = [];
                    foreach ($tableinfo as $key => $value) {
                        if ($value['column'] !== $table['column'] && $value['table'] !== $table['table']) {
                            $othertables[$key] = $value;
                        }
                    }
                    
                    $model[ $table['table'] ][ 'many-to-many' ][ $propertyName ] = [
                        'schema' => $database,
                        'pivot' => $pivottablename,
                        'connections' => $othertables,
                        'id' => $table['column'],
                    ];
                }
                
                $model[$pivottablename]['type'] = 'pivot';
                
                // Remove the M-1 keys for these tables to fully encapsulate the M-M scheme.
                foreach ($tableinfo as $table) {
                    foreach ((array) $model[ $table['table'] ][ 'one-to-many' ] as $key => $val) {
                        if ($val['table'] == $pivottablename) {
                            unset($model[ $table['table'] ][ 'one-to-many' ][$key]);
                        }
                    }
                }
            }
        }
        
        return [$model, $database];
    }
}

/*
project|id:pk:int, description:text, date_created:date, account_id:int
    1,"my project","2016-01-01",2
    account_id->account|id

account|id:pk:int, first_name:text, last_name:text
    1,"nik","barham"
    2,"craig","king"

account_project|account_id:pk:int, project_id:pk:int
    1,1
    account_id->account|id
    project_id->project|id
 */