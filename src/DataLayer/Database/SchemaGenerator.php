<?php

namespace Automatorm\DataLayer\Database;

use Automatorm\Interfaces\SchemaGenerator as SchemaGeneratorInterface;
use Automatorm\Interfaces\Connection as ConnectionInterface;
use Automatorm\Database\Query;
use Automatorm\Orm\Schema;
use Automatorm\Exception;

class SchemaGenerator implements SchemaGeneratorInterface
{
    protected $connection;
    
    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }
    
    // Generate Schema
    public function generate()
    {
        $connection = $this->connection;
        
        $model = [];
        
        // Get a list of all foreign keys in this database
        $query = new Query($connection);
        $query->sql("
            SELECT b.table_schema, b.table_name, b.column_name, b.referenced_table_schema, b.referenced_table_name, b.referenced_column_name
            FROM information_schema.table_constraints a 
            JOIN information_schema.key_column_usage b
            ON a.table_schema = b.table_schema AND a.constraint_name = b.constraint_name
            WHERE a.table_schema = database() AND a.constraint_type = 'FOREIGN KEY'
            ORDER BY b.table_name, b.constraint_name;"
        );
        $query->sql("
            SELECT b.table_schema, b.table_name, b.column_name, b.referenced_table_schema, b.referenced_table_name, b.referenced_column_name
            FROM information_schema.table_constraints a 
            JOIN information_schema.key_column_usage b
            ON a.table_schema = b.table_schema AND a.constraint_name = b.constraint_name
            WHERE b.referenced_table_schema = database() AND a.constraint_type = 'FOREIGN KEY'
            ORDER BY b.table_name, b.constraint_name;"
        );
        $query->sql("
            SELECT table_schema, table_name, column_name, data_type FROM information_schema.columns where table_schema = database();
        ");
        $query->sql("
            SELECT database() as `database`;
        ");
        
        list($keys, $reversekeys, $schema, $database) = $query->execute();
        
        $database = $database[0]['database'];
        
        // Assemble list of table columns by table
        foreach ($schema as $row) {
            $tableName = Schema::normaliseCase($row['table_name']);
            if (!array_key_exists($tableName, $model)) {
                $model[$tableName]['table_schema'] = $row['table_schema'];
                $model[$tableName]['table_name'] = $row['table_name'];
                // All tables default to type 'table' - can also be 'pivot' or 'foreign' as detected later
                $model[$tableName]['type'] = 'table';
                $model[$tableName]['one-to-one'] = [];
                $model[$tableName]['many-to-one'] = [];
                $model[$tableName]['one-to-many'] = [];
                $model[$tableName]['many-to-many'] = [];
            }
            
            // List all columns for this table
            $model[$tableName]['columns'][$row['column_name']] = $row['data_type'];
        }
        
        // Loop over every foreign key definition
        foreach ($keys as $row) {
            $tableName = Schema::normaliseCase($row['table_name']);
            $tableSchema = $row['table_schema'];
            
            $refTableName = Schema::normaliseCase($row['referenced_table_name']);
            $refTableSchema = $row['referenced_table_schema'];
            
            if ($row['referenced_column_name'] == 'id' and $row['column_name'] == 'id') {
                // If both columns in the key are 'id' then this is a 1 to 1 relationship.
                // Create a link in both objects to each other
                $model[$tableName]['one-to-one'][Schema::underscoreCase($refTableName)] = [
                    'table' => $refTableName,
                    'schema' => $refTableSchema
                ];
                $model[$tableName]['type'] = 'foreign';
            } elseif ($row['referenced_column_name'] == 'id') {
                // if this foreign key points at one 'id' column then this is a usable foreign 'key'
                if (substr($row['column_name'], -3) == '_id') {
                    $columnRoot = substr($row['column_name'], 0, -3);
                    $model[$tableName]['many-to-one'][$columnRoot] = [
                        'table' => $refTableName,
                        'schema' => $refTableSchema
                    ];
                }
            }
        }
        
        // Loop over every foreign key definition
        foreach ($reversekeys as $row) {
            $tableName = Schema::normaliseCase($row['table_name']);
            $tableSchema = $row['table_schema'];
            
            $refTableName = Schema::normaliseCase($row['referenced_table_name']);
            $refTableSchema = $row['referenced_table_schema'];
            
            if ($row['referenced_column_name'] == 'id' and $row['column_name'] == 'id') {
                // If both columns in the key are 'id' then this is a 1 to 1 relationship.
                // Create a link in both objects to each other
                if ($refTableSchema == $database) {
                    $model[$refTableName]['one-to-one'][Schema::underscoreCase($tableName)] = [
                        'table' => $tableName,
                        'schema' => $tableSchema
                    ];
                }
            } elseif ($row['referenced_column_name'] == 'id') {
                // if this foreign key points at one 'id' column then this is a usable foreign 'key'
                if (substr($row['column_name'], -3) == '_id') {
                    $columnRoot = substr($row['column_name'], 0, -3);
                    
                    // Add the key constraint in reverse, trying to make a sensible name.
                    // If the column name was derived from the table name, just use the table name.
                    // (e.g "my_account" table and "my_account_id" -> my_account)
                    // Otherwise, append the column name to the table name to make sure it is unique.
                    // (e.g "your_account" table and "my_account_id" -> your_account_my_account)
                    if ($columnRoot == $row['referenced_table_name']) {
                        $propertyName = Schema::underscoreCase($tableName);
                    } else {
                        $propertyName = Schema::underscoreCase($tableName) . '_' . $columnRoot;
                    }
                    
                    $model[$refTableName]['one-to-many'][$propertyName] = [
                        'table' => $tableName,
                        'schema' => $tableSchema,
                        'column_name' => $row['column_name']
                    ];
                }
            }
        }        
        
        // Now look for pivot tables
        foreach ($model as $pivottablename => $pivot) {
            // If we have found a table with only foreign keys then this must be a pivot table
            if (count($pivot['many-to-one']) > 1 and count($pivot['columns']) == count($pivot['many-to-one'])) {
                // Grab all foreign keys and rearrange them into arrays.
                $tableinfo = array();
                foreach ($pivot['many-to-one'] as $column => $tablearray) {
                    $tableinfo[] = [
                        'column' => $column . '_id',
                        'column_raw' => $column,
                        'table' => $tablearray['table'],
                        'schema' => $tablearray['schema']
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
                    $othertables = $tableinfo;
                    unset($othertables[$i]);
                    $othertables = array_values($othertables);
                    
                    $model[ $table['table'] ][ 'many-to-many' ][ $propertyName ] = array(
                        'schema' => $table['schema'],
                        'pivot' => $pivottablename,
                        'connections' => $othertables,
                        'id' => $table['column'],
                    );
                }
                
                $model[$pivottablename]['type'] = 'pivot';
                
                // Remove the M-1 keys for these tables to fully encapsulate the M-M scheme.
                foreach ($tableinfo as $table) {
                    if ($table['schema'] == $database) {
                        foreach ($model[ $table['table'] ][ 'one-to-many' ] as $key => $val) {
                            if ($val['table'] == $pivottablename) {
                                unset($model[ $table['table'] ][ 'one-to-many' ][$key]);
                            }
                        }
                    }
                }
            }
        }
        
        return [$model, $database];
    }
}
