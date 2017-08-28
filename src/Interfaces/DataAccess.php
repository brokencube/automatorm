<?php
namespace Automatorm\Interfaces;

use Automatorm\Interfaces\Connection;

interface DataAccess
{
    /**
     * Insert, Update or Delete data from the data source
     * @param $mode Must be 'insert', 'update' or 'delete'
     * @param $table Name of the table
     * @param $id Value of primary key for update/delete
     * @param $data Column data in ['column' => $value] format
     * @param $externalData Data for M-M joined data
     * @param $schema Return from $schema->getTable($table)
     * 
     * @return int Primary id affected by call
     */
    public function commit($mode, $table, $id, $data, $externalData, $schema) : int;
    
    /**
     * Select data from data source
     * @param $table Name of table
     * @param $where Where clauses in standard ['column' => $value] format
     * @param $options Optional array of limit, offset, sortby options
     *
     * @return array Array of data in $row[$column => $value] format
     */
    public function getData($table, $where, array $options = []) : array;
    
    /**
     * Return number of matching data records from data source
     * @param $table Name of table
     * @param $where Where clauses in standard ['column' => $value] format
     * @param $options Optional array of limit, offset, sortby options
     *
     * @return int Number of results that would be returned
     */
    public function getDataCount($table, $where, array $options = []) : int;
    
    /**
     * Return data based on a M2M relationship
     * @param $pivotTablename Name of pivot table
     * @param $pivot
     * @param $ids Ids to match from starting side of the relationship
     * @param $where Where clauses in standard ['column' => $value] format
     *
     * @return array Array of data in $row[$column => $value] format
     */
    public function getM2MData($pivotTablename, $pivot, $ids, $where = []) : array;
    
}
