<?php
namespace Automatorm\Orm;

use Automatorm\Database\Query;
use Automatorm\Database\QueryBuilder;
use Automatorm\Database\SqlString;
use Automatorm\Interfaces\Connection;

class DataAccess
{
    protected $connection;
    
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }
    
    public function commit($mode, $table, $id, $data, $externalData, $schema)
    {
        // Create a new query
        $query = new Query($this->connection);        

        // Insert/Update the data, and store the insert id into a variable
        if ($mode == 'delete') {
            $q = QueryBuilder::delete($table, ['id' => $id]);
            $query->sql($q);
        } elseif ($mode == 'insert') {
            $q = QueryBuilder::insert($table, $data);
            $query->sql($q)->sql("SELECT last_insert_id() into @id");
        } elseif ($mode == 'update') {
            $q = QueryBuilder::update($table, $data)->where(['id' => $id]);
            $query->sql($q)->sql("SELECT {$id} into @id");
        }
        
        if ($mode != 'delete') {
            $origin_id = new SqlString('@id');
            
            // Foreign tables
            foreach ($externalData as $property_name => $value) {
                // Skip property if this isn't an M-M table (M-1 and 1-M tables are dealt with in other ways)
                if (!$pivot = $schema['many-to-many'][$property_name]) continue;
                
                // We can only do updates support simple connection access for 2 key pivots.
                if (count($pivot['connections']) != 1) continue;
                
                // Get the table name of the pivot table for this property
                $tablename = Schema::underscoreCase($pivot['pivot']);
                
                // Clear out any existing data for this object - this is safe because we are in an atomic transaction.
                $query->sql("Delete from $tablename where {$pivot['id']} = @id");
                
                // Loops through the list of objects to link to this table
                foreach ($value as $object) {
                    $newdata = [
                        $pivot['id'] => $origin_id,      // Id of this object
                        $pivot['connections'][0]['column'] => $object->id  // Id of object linked to this object
                    ];
                    $query->sql(QueryBuilder::insert($tablename, $newdata, true));
                }
            }
        }
        
        $values = $query->execute(true);
        
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

}