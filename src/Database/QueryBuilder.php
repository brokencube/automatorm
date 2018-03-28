<?php
/**
 * Query Builder - build SQL queries programatically
 *
 * @package Automatorm\Database
 */

namespace Automatorm\Database;

use Automatorm\Exception;
use Automatorm\Database\QueryBuilder\{
    Table, SubQuery, Column, CountColumn, Join, Expression, Data, Where
};

class QueryBuilder
{
    const ENGINES = ['mysql', 'postgres', 'mssql'];
    public static $engine = "mysql";
    public static $requireWhereClause = true;
    
    protected $type;
    protected $table;
    protected $columns = [];
    protected $set = [];
    protected $joins = [];
    protected $where = [];
    protected $having = [];
    protected $limit;
    protected $offset;
    protected $sortBy = [];
    protected $groupBy = [];
    
    protected $data = [];
    
    public function __construct($type = null, $table = null)
    {
        if (!is_null($type)) {
            $this->type($type);
        }
        if (!is_null($table)) {
            $this->table($table);
        }
    }
    
    // ENTRY POINTS
    /**
     * Build a "SELECT" query
     *
     * @param mixed $table Name of table (string of [table => alias]) to select from
     * @param mixed[] $columns List of select clauses/columns
     * @return \Automatorm\Database\QueryBuilder
     */
    public static function select($table, array $columns = ['*']) : self
    {
        $query = new static('select', $table);
        $query->columns($columns);
        return $query;
    }

    /**
     * Build a "DELETE" query
     *
     * @param mixed $table Name of table (string of [table => alias]) to delete from
     * @param mixed[] $where Where clause array
     * @return \Automatorm\Database\QueryBuilder
     */
    public static function delete($table, $where = []) : self
    {
        $query = new static('delete', $table);
        $query->where($where);
        return $query;
    }

    /**
     * Build a "SELECT count() FROM" query
     *
     * @param mixed $table Name of table (string of [table => alias]) to select from
     * @param string $column The column to count - for most uses, this should be '*'
     * @return \Automatorm\Database\QueryBuilder
     */
    public static function count($table, $column = '* as count') : self
    {
        $query = new static('select', $table);
        $query->columns = [new CountColumn($column)];
        return $query;
    }

    /**
     * Build a "INSERT" query
     *
     * @param mixed $table Name of table (string of [table => alias]) to insert into
     * @param mixed[] $columndata List of column => data to insert
     * @return \Automatorm\Database\QueryBuilder
     */
    public static function insert($table, array $columndata = [], $insertIgnore = false) : self
    {
        $query = new static($insertIgnore ? 'insertignore' : 'insert', $table);
        $query->setData($columndata);
        return $query;
    }

    /**
     * Build a "UPDATE" query
     *
     * @param mixed $table Name of table (string of [table => alias]) to update
     * @param mixed[] $columndata List of column => data to update
     * @return \Automatorm\Database\QueryBuilder
     */
    public static function update($table, array $columndata = []) : self
    {
        $query = new static('update', $table);
        $query->setData($columndata);
        return $query;
    }

    /**
     * Build a "REPLACE" query
     *
     * @param mixed $table Name of table (string of [table => alias]) to replace into
     * @param mixed[] $columndata List of column => data to replace
     * @return \Automatorm\Database\QueryBuilder
     */
    public static function replace($table, array $columndata = []) : self
    {
        return new static('replace', $table);
    }
    
    // BUILDER FUNCTIONS
    /**
     * Set query type
     *
     * @param string $type Query type
     * @return self
     */
    public function type($type) : self
    {
        // Whitelist
        switch ($type) {
            case 'select':
            case 'replace':
            case 'update':
            case 'insert':
            case 'count':
            case 'delete':
            case 'insertignore':
                $this->type = $type;
                break;
            
            default:
                throw new Exception\QueryBuilder('Unknown Query Type');
        }
        return $this;
    }
    
    /**
     * Set main tablename / subquery
     *
     * @param mixed $table Table name, QueryBuilder object, or Table parts array
     * @return self
     */
    public function table($table) : self
    {
        if ($table instanceof Table) {
            $this->table = $table;
        } else if ($table instanceof QueryBuilder) {
            $this->table = new SubQuery($table);
        } else {
            $this->table = new Table($table);
        }
        
        return $this;
    }
    
    /**
     * Set columns for "SELECT" style queries
     *
     * @param mixed[] $columns List of select clauses/columns
     * @return self
     */
    public function columns(array $columns) : self
    {
        $col = [];
        foreach ($columns as $column) {
            $col[] = $column instanceof Column ? $column : new Column($column);
        }
        $this->columns = $col;
        return $this;
    }
    
    /**
     * Add where clauses to the query
     *
     * @param mixed[] $clauses List of where clauses to add
     * @return self
     */
    public function where(array $clauses) : self
    {
        if (!$this->where) {
            $this->where = new Where();
        }
        
        $this->where->addClauses($clauses);
        return $this;
    }
    
    /**
     * Add having clauses to the query
     *
     * @param mixed[] $clauses List of where clauses to add
     * @return self
     */
    public function having(array $clauses) : self
    {
        if (!$this->having) {
            $this->having = new Where();
        }
        
        $this->having->addClauses($clauses);
        return $this;
    }

    /**
     * Add a limit clause to the query
     *
     * @param int $limit Limit to x results
     * @param int $offset Offset x results - null implies 0
     * @return self
     */
    public function limit($limit, $offset = null) : self
    {
        $this->limit = intval($limit);
        $this->offset = is_null($offset) ? null : intval($offset);
        return $this;
    }
    
    /**
     * Add an Order by clause to the query
     *
     * @param string $sort Column to sort by
     * @param string $dir asc or desc - desc by default
     * @return self
     */
    public function sortBy($sort, $dir = 'desc') : self
    {
        $this->sortBy[] = ['sort' => new Column($sort), 'dir' => $dir == 'desc' ? 'desc' : 'asc'];
        return $this;
    }

    /**
     * Add an Order by clause to the query
     *
     * @param string $sort Column to sort by
     * @param string $dir asc or desc - desc by default
     * @return self
     */
    public function orderBy($sort, $dir = 'desc') : self
    {
        return $this->sortBy($sort, $dir);
    }
    
    /**
     * Join a table to this query
     *
     * @param mixed $table Name of table to select from
     * @return self
     */
    public function join($table, $rawtype = null) : self
    {
        $join = new Join($table, $rawtype);
        $this->joins[] = $this->currentJoin = $join;
        
        return $this;
    }

    /**
     * Join a subquery as a derived table to this query
     *
     * @param mixed $subquery String or QueryBuilder object representing subquery
     * @param string $alias Alias for the derived table
     * @return self
     */
    public function joinSubquery($subquery, $alias, $rawtype = null) : self
    {
        $join = new Join(new SubQuery($subquery, $alias), $rawtype);
        $this->joins[] = $this->currentJoin = $join;
        
        return $this;
    }
    
    /**
     * For the last defined join, add some "on" clauses (Join)
     *
     * @param mixed[] $columnclauses List of on clauses in the format column = column
     * @return self
     */
    public function joinOn(array $columnclauses = []) : self
    {
        $this->currentJoin->on($columnclauses);
        return $this;
    }

    /**
     * For the last defined join, add some "on" clauses (Where)
     *
     * @param mixed[] $columnclauses List of on clauses in the format column = value
     * @return self
     */
    public function joinWhere(array $columnclauses = []) : self
    {
        $this->currentJoin->where($columnclauses);
        return $this;
    }
    
    /**
     * Add an Group by clause to the query
     *
     * @param string $group Column to sort by
     * @return self
     */
    public function groupBy($group) : self
    {
        $this->groupBy[] = new Column($group);
        return $this;
    }
    
    public function setData($data) : self
    {
        $col = [];
        foreach ($data as $column => $datum) {
            $col[] = new Data(new Column($column), $datum);
        }
        $this->set = $col;
        return $this;
    }
    

    public function addData(array $data) : self
    {
        // Flatten datetimes
        foreach ($data as $key => $value) {
            if ($data[$key] instanceof \DateTimeInterface) {
                $data[$key] = $value->format('Y-m-d H:i:s.u');
            }
        }
        
        $this->data = array_merge($this->data, $data);
        return $this;
    }
    
    // RESOLVER FUNCTIONS
    /**
     * Resolve object into the SQL string and Parameterised data
     *
     * @return mixed[] Returns [$sql, $data] for parameterised queries
     */
    
    public function resolve() : array
    {
        $this->data = [];
        
        $table = $this->table->render($this);
        switch ($this->type) {
            case 'select':
                $columns = $this->resolveColumns();
                $join = $this->resolveJoins();
                $where = $this->resolveWhere();
                $group = $this->resolveGroup();
                $having = $this->resolveHaving();
                $sort = $this->resolveSort();
                $limit = $this->resolveLimit();
                
                return ["SELECT $columns FROM $table{$join}{$where}{$group}{$having}{$sort}{$limit}", $this->data];
            
            case 'insert':
                $join = $this->resolveJoins();
                $data = $this->resolveInsertColumnData();
                $limit = $this->resolveLimit();
                
                return ["INSERT INTO $table{$join}{$data}{$limit}", $this->data];

            case 'insertignore':
                $join = $this->resolveJoins();
                $data = $this->resolveInsertColumnData();
                $limit = $this->resolveLimit();
                
                return ["INSERT IGNORE INTO $table{$join}{$data}{$limit}", $this->data];

            case 'update':
                $join = $this->resolveJoins();
                $data = $this->resolveUpdateColumnData();
                $where = $this->resolveWhere();
                $limit = $this->resolveLimit();
                
                return ["UPDATE $table{$join}{$data}{$where}{$limit}", $this->data];

            case 'delete':
                $where = $this->resolveWhere();
                $limit = $this->resolveLimit();
                
                return ["DELETE FROM $table{$where}{$limit}", $this->data];
        }
    }
    
    public function resolveJoins() : string
    {
        $joinstring = '';
        foreach ($this->joins as $join) {
            $joinstring .= ' ' . $join->render($this);
        }
        
        return $joinstring;
    }
    
    public function resolveColumns() : string
    {
        $column = [];
        foreach ($this->columns as $col) {
            $column[] = $col->render($this);
        }
        return implode(', ', $column);
    }
    
    public function resolveWhere() : string
    {
        return $this->where ? ' WHERE ' . $this->where->render($this) : '';
    }
    
    public function resolveHaving() : string
    {
        return $this->having ? ' HAVING ' . $this->having->render($this) : '';
    }
    
    public function resolveLimit() : string
    {
        if (is_null($this->limit) && is_null($this->offset)) {
            return '';
        }
        if (is_null($this->offset)) {
            return " LIMIT {$this->limit}";
        }
        return " LIMIT {$this->offset},{$this->limit}";
    }
    
    public function resolveUpdateColumnData() : string
    {
        $column = [];
        foreach ($this->set as $data) {
            $column[] = $data->render($this);
        }
        return ' SET ' . implode(', ', $column);
    }

    public function resolveInsertColumnData() : string
    {
        $column = [];
        $value = [];
        
        foreach ($this->set as $data) {
            $column[] = $data->renderColumn($this);
            $value[] = $data->renderValue($this);
        }
        
        return ' (' . implode(', ', $column) . ') VALUES (' . implode(', ', $value) . ')';
    }
    
    public function resolveGroup() : string
    {
        if (!count($this->groupBy)) {
            return '';
        }
        
        $columns = [];
        foreach ($this->groupBy as $column) {
            $columns[] = $column->render($this);
        }
        
        return ' GROUP BY ' . implode(', ', $columns);
    }

    public function resolveSort() : string
    {
        if (!count($this->sortBy)) {
            return '';
        }

        $sortlist = [];
        foreach ($this->sortBy as $sort) {
            $sortlist[] = $sort['sort']->render($this) . ' ' . $sort['dir'];
        }
        return ' ORDER BY ' . implode(', ', $sortlist);
    }
}
