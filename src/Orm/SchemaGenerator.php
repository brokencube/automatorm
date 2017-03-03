<?php

namespace Automatorm\Orm;

use Automatorm\Interfaces\SchemaGenerator as SchemaGeneratorInterface;
use Automatorm\Interfaces\Connection as ConnectionInterface;
use Automatorm\Database\Query;
use Automatorm\Orm\Schema;
use Automatorm\Exception;

class SchemaGenerator implements SchemaGeneratorInterface
{
    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }
    
    // Generate Schema
    public function generate()
    {
        $connection = $this->connection();
        
        $model = [];
        
        // Get a list of all foreign keys in this database
        $query = new Query($connection);
        $query->sql("
            SELECT b.table_name, b.column_name, b.referenced_table_name, b.referenced_column_name
            FROM information_schema.table_constraints a 
            JOIN information_schema.key_column_usage b
            ON a.table_schema = b.table_schema AND a.constraint_name = b.constraint_name
            WHERE a.table_schema = database() AND a.constraint_type = 'FOREIGN KEY'
            ORDER BY b.table_name, b.constraint_name;"
        );
        $query->sql("
            SELECT table_name, column_name, data_type FROM information_schema.columns where table_schema = database();
        ");
        
        list($keys, $schema) = $query->execute();
        
        // Assemble list of table columns by table
        foreach ($schema as $row) {
            $tableName = Schema::normaliseCase($row['table_name']);
            
            $model[$tableName]['table_name'] = $row['table_name'];
            // All tables default to type 'table' - can also be 'pivot' or 'foreign' as detected later
            $model[$tableName]['type'] = 'table';
            // List all columns for this table
            $model[$tableName]['columns'][$row['column_name']] = $row['data_type'];
        }
        
        // Loop over every foreign key definition
        foreach ($keys as $row) {
            $tableName = Schema::normaliseCase($row['table_name']);
            $refTableName = Schema::normaliseCase($row['referenced_table_name']);
            
            if ($row['referenced_column_name'] == 'id' and $row['column_name'] == 'id') {
                // If both columns in the key are 'id' then this is a 1 to 1 relationship.
                // Create a link in both objects to each other
                $model[$refTableName]['one-to-one'][Schema::underscoreCase($tableName)] = $tableName;
                $model[$tableName]['one-to-one'][Schema::underscoreCase($refTableName)] = $refTableName;
                $model[$tableName]['type'] = 'foreign';
            } elseif ($row['referenced_column_name'] == 'id') {
                // if this foreign key points at one 'id' column then this is a usable foreign 'key'
                if (substr($row['column_name'], -3) == '_id') {
                    $columnRoot = substr($row['column_name'], 0, -3);
                    $model[$tableName]['many-to-one'][$columnRoot] = $refTableName;
                    
                    // Add the key constraint in reverse, trying to make a sensible name.
                    // If the column name was derived from the table name, just use the table name.
                    // (e.g "my_account" table and "my_account_id" -> my_account)
                    // Otherwise, append the column name to the table name to make sure it is unique.
                    // (e.g "your_account" table and "my_account_id" -> your_account_my_account)
                    if ($columnRoot == $row['referenced_table_name']) {
                        $property_name = Schema::underscoreCase($tableName);
                    } else {
                        $property_name = Schema::underscoreCase($tableName) . '_' . $columnRoot;
                    }
                    
                    $model[$refTableName]['one-to-many'][$property_name] = array('table' => $tableName, 'column_name' => $row['column_name']);
                }
            }
        }
        
        // Now look for pivot tables
        foreach ($model as $pivottablename => $pivot) {
            // If we have found a table with only foreign keys then this must be a pivot table
            if (count($pivot['many-to-one']) > 1 and count($pivot['columns']) == count($pivot['many-to-one'])) {
                // Grab all foreign keys and rearrange them into arrays.
                $tableinfo = array();
                foreach ($pivot['many-to-one'] as $column => $tablename) {
                    $tableinfo[] = array('column' => $column . '_id', 'column_raw' => $column, 'table' => $tablename);
                }
                
                // For each foreign key, store details in the table it point to on how to get to the OTHER table in the "Many to Many" relationship
                foreach ($tableinfo as $i => $table) {
                    // If the column name is named based on the foreign table name, then use the pivot table name as the property name
                    // This is the normal/usual case
                    if ($table['column'] == Schema::underscoreCase($table['table']) . '_id') {
                        $property_name = Schema::underscoreCase($pivottablename);
                    } else {
                        // Else append the column name to the pivot table name.
                        // This is mostly for when a pivot table references the same table twice, and so
                        // needs to have a unique name for at least one of the columns (which is not based on the table name)
                        $property_name = Schema::underscoreCase($pivottablename) . '_' . $table['column_raw'];
                    }
                    
                    // Outersect of tables to create an array of all OTHER foreign keys in this table, for this foreign key.
                    $othertables = array_values(array_diff_assoc($tableinfo, array($i => $table)));
                    
                    $model[ $table['table'] ][ 'many-to-many' ][ $property_name ] = array(
                        'pivot' => $pivottablename,
                        'connections' => $othertables,
                        'id' => $table['column'],
                    );
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
        
        return $model;
    }
}
