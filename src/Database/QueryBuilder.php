<?php
/**
 * Query Builder - build SQL queries programatically
 *
 * @package Automatorm\Database
 */

namespace Automatorm\Database;

use Automatorm\Exception;

class QueryBuilder
{
    const ENGINES = ['mysql', 'postgres', 'mssql'];
    public static $engine = "mysql";
    public static $requireWhereClause = true;
    
    protected $type;
    protected $table;
    protected $tableSubquery;
    protected $columns = [];
    protected $count;
    protected $set = [];
    protected $joins = [];
    protected $where = [];
    protected $having = [];
    protected $limit;
    protected $offset;
    protected $sortBy = [];
    protected $groupBy = [];
    
    protected $data = [];
    
    // ENTRY POINTS
    /**
     * Build a "SELECT" query
     *
     * @param mixed $table Name of table (string of [table => alias]) to select from
     * @param mixed[] $columns List of select clauses/columns
     * @return \Automatorm\Database\QueryBuilder
     */
    public static function select($table, array $columns = ['*'])
    {
        $query = new static('select');
        $query->columns = $columns;
        if ($table instanceof QueryBuilder) {
            $query->tableSubquery = $table->resolve();
        } else {
            $query->table = $table;
        }
        
        return $query;
    }

    /**
     * Build a "DELETE" query
     *
     * @param mixed $table Name of table (string of [table => alias]) to delete from
     * @param mixed[] $where Where clause array
     * @return \Automatorm\Database\QueryBuilder
     */
    public static function delete($table, $where = [])
    {
        $query = new static('delete');
        $query->where($where);
        if ($table instanceof QueryBuilder) {
            $query->tableSubquery = $table->resolve();
        } else {
            $query->table = $table;
        }
        
        return $query;
    }

    /**
     * Build a "SELECT count() FROM" query
     *
     * @param mixed $table Name of table (string of [table => alias]) to select from
     * @param string $column The column to count - for most uses, this should be '*'
     * @return \Automatorm\Database\QueryBuilder
     */
    public static function count($table, $column = '*')
    {
        $query = new static('count');
        $query->count = $column;
        if ($table instanceof QueryBuilder) {
            $query->tableSubquery = $table->resolve();
        } else {
            $query->table = $table;
        }
        
        return $query;
    }

    /**
     * Build a "INSERT" query
     *
     * @param mixed $table Name of table (string of [table => alias]) to insert into
     * @param mixed[] $columndata List of column => data to insert
     * @return \Automatorm\Database\QueryBuilder
     */
    public static function insert($table, array $columndata = [], $insertIgnore = false)
    {
        $query = $insertIgnore ? new static ('insertignore') : new static('insert');
        $query->set = $columndata;
        if ($table instanceof QueryBuilder) {
            $query->tableSubquery = $table->resolve();
        } else {
            $query->table = $table;
        }
        
        return $query;
    }

    /**
     * Build a "UPDATE" query
     *
     * @param mixed $table Name of table (string of [table => alias]) to update
     * @param mixed[] $columndata List of column => data to update
     * @return \Automatorm\Database\QueryBuilder
     */
    public static function update($table, array $columndata = [])
    {
        $query = new static('update');
        $query->set = $columndata;
        if ($table instanceof QueryBuilder) {
            $query->tableSubquery = $table->resolve();
        } else {
            $query->table = $table;
        }

        return $query;        
    }

    /**
     * Build a "REPLACE" query
     *
     * @param mixed $table Name of table (string of [table => alias]) to replace into
     * @param mixed[] $columndata List of column => data to replace
     * @return \Automatorm\Database\QueryBuilder
     */
    public static function replace($table, array $columndata = [])
    {
        $query = new static('replace');
        if ($table instanceof QueryBuilder) {
            $query->tableSubquery = $table->resolve();
        } else {
            $query->table = $table;
        }

        return $query;                
    }
    
    protected function __construct($type)
    {
        $this->type = $type; 
    }

    
    // BUILDER FUNCTIONS
    /**
     * Set columns for "SELECT" style queries
     *
     * @param mixed[] $columns List of select clauses/columns
     * @return self
     */
    public function columns(array $columns)
    {
        $this->columns = $columns;
        return $this;
    }
    
    /**
     * Add where clauses to the query
     *
     * @param mixed[] $clauses List of where clauses to add
     * @return self
     */
    public function where(array $clauses)
    {
        foreach ($clauses as $key => $value) {
            if (is_numeric($key) && $value instanceof SqlString) {
                $this->where[] = $value;
            } else {
                $this->where[] = $this->extractWhereColumn($key, $value);    
            }
        }
        return $this;
    }
    
    /**
     * Add having clauses to the query
     *
     * @param mixed[] $clauses List of where clauses to add
     * @return self
     */
    public function having(array $clauses)
    {
        foreach ($clauses as $key => $value) {
            if (is_numeric($key) && $value instanceof SqlString) {
                $this->having[] = $value;
            } else {
                $this->having[] = $this->extractWhereColumn($key, $value);    
            }
        }
        return $this;
    }

    /**
     * Add a limit clause to the query
     *
     * @param int $limit Limit to x results
     * @param int $offset Offset x results - null implies 0
     * @return self
     */
    public function limit($limit, $offset = null)
    {
        if (!is_null($limit)) {
            $this->limit = intval($limit);
            $this->offset = is_null($offset) ? null : intval($offset);
        }
        return $this;
    }
    
    /**
     * Add an Order by clause to the query
     *
     * @param string $sort Column to sort by
     * @param string $dir asc or desc - desc by default
     * @return self
     */
    public function sortBy($sort, $dir = 'desc')
    {
        if ($sort) $this->sortBy[] = ['sort' => $sort, 'dir' => $dir == 'desc' ? 'desc' : 'asc'];
        return $this;
    }

    /**
     * Add an Order by clause to the query
     *
     * @param string $sort Column to sort by
     * @param string $dir asc or desc - desc by default
     * @return self
     */
    public function orderBy($sort, $dir = 'desc')
    {
        return $this->sortBy($sort, $dir);
    }
    
    /**
     * Join a table to this query
     *
     * @param string $table Name of table to select from
     * @return self
     */
    public function join($table, $rawtype = null)
    {
        $type = 'JOIN';
        if ($rawtype) switch (strtolower($rawtype)) {
            case 'left':
                $type = 'LEFT JOIN';
                break;
            case 'left outer':
                $type = 'LEFT OUTER JOIN';
                break;
            case 'cross':
                $type = 'CROSS JOIN';
                break;
            default:
                throw new Exception\QueryBuilder('Unknown Join Type');
        }
        
        $this->joins[] = ['table' => $table, 'type' => $type, 'where' => [], 'on' => []];
        
        return $this;
    }

    /**
     * Join a subquery as a derived table to this query
     *
     * @param mixed $subquery String or QueryBuilder object representing subquery
     * @param string $alias Alias for the derived table
     * @return self
     */
    public function joinSubquery($subquery, $alias, $rawtype = null)
    {
        $type = 'JOIN';
        if ($rawtype) switch (strtolower($rawtype)) {
            case 'left':
                $type = 'LEFT JOIN';
                break;
            case 'left outer':
                $type = 'LEFT OUTER JOIN';
                break;
            case 'cross':
                $type = 'CROSS JOIN';
                break;
            default:
                throw new Exception\QueryBuilder('Unknown Join Type');
        }
        
        $this->joins[] = ['table' => null, 'subquery' => $subquery, 'type' => $type, 'alias' => $alias, 'where' => [], 'on' => []];
        return $this;
    }
    
    /**
     * For the last defined join, add some "on" clauses (Join)
     *
     * @param mixed[] $columnclauses List of on clauses in the format column = column
     * @return self
     */
    public function joinOn($columnclauses = [])
    {
        end($this->joins);
        $joinkey = key($this->joins);
        
        foreach ($columnclauses as $key => $value) {
            if (is_numeric($key) && $value instanceof SqlString) {
                $this->joins[$joinkey]['on'][] = $value;
            } else {
                $this->joins[$joinkey]['on'][] = $this->extractWhereColumn($key, $value, true);    
            }
        }
        return $this;
    }

    /**
     * For the last defined join, add some "on" clauses (Where)
     *
     * @param mixed[] $columnclauses List of on clauses in the format column = value
     * @return self
     */
    public function joinWhere($columnclauses = [])
    {
        end($this->joins);
        $joinkey = key($this->joins);
        
        foreach ($columnclauses as $key => $value) {
            if (is_numeric($key) && $value instanceof SqlString) {
                $this->joins[$joinkey]['where'][] = $value;
            } else {
                $this->joins[$joinkey]['where'][] = $this->extractWhereColumn($key, $value);
            }
        }
        
        return $this;
    }
    
    /**
     * Add an Group by clause to the query
     *
     * @param string $group Column to sort by
     * @return self
     */
    public function groupBy($group)
    {
        if ($group) $this->groupBy[] = $group;
        return $this;
    }
    
    // Preprocess Functions
    /**
     * Extract features from "where" clauses 
     *
     * @param string $column Column name as given by user
     * @param mixed $value Value to compare column to
     * @param bool $onclause Processing is different for "on clauses", clauses of the form column = column
     * @return mixed[] Returns [$column, $comparitor, $value, $special] for future internal use when resolving
     */    
    protected function extractWhereColumn($column, $value, $onclause = false)
    {
        $comparitor = '=';
        $special = null;
        
        preg_match('/^([!=<>%#]*)([^!=<>%#]+)([!=<>%#]*)$/', $column, $parts);
        $affix = $parts[1] ?: $parts[3];
        $column = $parts[2];
        
        // Special cases for null values in where clause
        if (is_null($value)) {
            return [$column, $affix == '!' ? "is not null" : "is null", null, null];
        }
        
        // Special case for in clauses
        if (is_array($value) && !$onclause) {
            // Special case for empty in clauses
            if (!count($value)) return $affix == '!' ? [null, null, null, "true"] : [null, null, null, "false"];
            
            return [$column, $affix == '!' ? "not in" : "in", $value, null];
        }
        
        // Special case for # "count" clause
        if (strpos($affix, '#') !== false)
        {
            // Excise the # from the affix
            $affix = substr($affix, 0, strpos($affix, '#')) . substr($affix, strpos($affix, '#') + 1);
            $special = 'count';
        }
        
        switch ($affix) {
            case '=':
            case '!=':
            case '>':
            case '<':
            case '>=':
            case '<=':
                $comparitor = $affix;
                break;
            case '!':
                $comparitor = '!=';
                break;
            case '%':
                $comparitor = 'like';
                break;
            case '!%':
            case '%!':
                $comparitor = 'not like';
                break;
        }
        
        return [$column, $comparitor, $value, $special];
    }
    
    // RESOLVER FUNCTIONS
    /**
     * Resolve object into the SQL string and Parameterised data
     *
     * @return mixed[] Returns [$sql, $data] for parameterised queries
     */
    public function resolve()
    {
        $this->data = [];
        
        $table = $this->resolveTable();
        switch ($this->type) {
            case 'count':
                $columns = $this->resolveCount();
                $join = $this->resolveJoins();
                $where = $this->resolveWhere();
                $group = $this->resolveGroup();
                $having = $this->resolveHaving();
                $sort = $this->resolveSort();
                $limit = $this->resolveLimit();
                
                return ["SELECT $columns FROM $table{$join}{$where}{$group}{$having}{$sort}{$limit}", $this->data];
                
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
    
    public function resolveTable()
    {
        if ($this->tableSubquery) {
            list($sql, $subdata) = $this->tableSubquery;
            $this->data = array_merge($this->data, $subdata);
            return '(' . $sql . ') AS subquery';
        }
        return $this->escapeTable($this->table);
    }
    
    public function resolveJoins()
    {
        if (!$this->joins) return '';
        
        $joinstring = '';
        
        foreach ($this->joins as $join) {
            if ($join['table']) {
                $clauses = [];
                $joinstring .= " {$join['type']} " . $this->escapeTable($join['table']);
            } elseif ($join['subquery']) {
                $sql = $join['subquery'];
                if ($sql instanceof QueryBuilder) {
                    list ($sql, $subdata) = $join['subquery']->resolve();
                    $this->data = array_merge($this->data, $subdata);
                }
                
                $joinstring .= " {$join['type']} ($sql) as {$join['alias']}";
            }
            
            if ($join['where']) {
                foreach ($join['where'] as $where) {
                    $clauses[] = $this->resolveWhereClause($where);
                }
            }

            if ($join['on']) {
                foreach ($join['on'] as $where) {
                    if ($where instanceof SqlString) {
                        $clauses[] = (string) $where;
                    } else {
                        list($column, $comp, $value) = $where;
                        if ($value instanceof SqlString) {
                            $clauses[] = $this->escapeColumn($column) . " $comp " . $value;
                        } else {
                            $clauses[] = $this->escapeColumn($column) . ' ' . $comp . ' ' . $this->escapeColumn($value);    
                        }
                    }
                }
            }
            
            if ($clauses) {
                $joinstring .= ' ON ' . implode(' AND ', $clauses);    
            }
        }
        
        return $joinstring;
    }
    
    public function resolveWhereClause($where)
    {
        if ($where instanceof SqlString) {
            return (string) $where;
        } else {
            list($column, $comp, $value, $special) = $where;
            if ($special == 'true') {
                return 'true';
            } elseif ($special == 'false') {
                return 'false';
            } elseif (is_null($value)) {
                return $this->escapeColumn($column) . ' ' . $comp;
            } elseif ($value instanceof SqlString) {
                return $this->escapeColumn($column) . " $comp " . $value;
            } elseif (is_array($value)) {
                $count = count($value);
                foreach ($value as $val) {
                    $this->data[] = $this->resolveValue($val);
                }
                if ($special == 'count') {
                    $col = 'count('.$this->escapeColumn($column).')';
                } else {
                    $col = $this->escapeColumn($column);
                }
                return $col . ' ' . $comp . ' ' . '(' . implode(',', array_fill(0, $count, '?')) . ')';
            } else {
                $this->data[] = $this->resolveValue($value);
                if ($special == 'count') {
                    $col = 'count('.$this->escapeColumn($column).')';
                } else {
                    $col = $this->escapeColumn($column);
                }
                return $col . ' ' . $comp . ' ?';
            }
        }
    }
    
    public function resolveUpdateColumnData()
    {
        $column = [];
        foreach ($this->set as $col => $value) {
            if ($value instanceof SqlString) {
                $column[] = $this->escapeColumn($col) .  ' = ' . $value;
            } else {
                $column[] = $this->escapeColumn($col) .  ' = ?';
                $this->data[] = $this->resolveValue($value);
            }
        }
        return ' SET ' . implode(', ', $column);
    }

    public function resolveInsertColumnData()
    {
        $column = [];
        $value = [];
        
        foreach ($this->set as $col => $val) {
            $column[] = $this->escapeColumn($col);
        }
        
        foreach ($this->set as $val) {
            
            if ($val instanceof SqlString) {
                $value[] = (string) $val;
            } else {
                $value[] = '?';
                $this->data[] = $this->resolveValue($val);
            }
        }
        return ' (' . implode(', ', $column) . ') VALUES (' . implode(', ', $value) . ')';
    }
    
    public function resolveCount()
    {
        return 'COUNT('.$this->escapeColumn($this->count).') as count';
    }
    
    public function resolveColumns()
    {
        $column = [];
        foreach ($this->columns as $col) {
            $column[] = $this->escapeColumn($col);    
        }
        return implode(', ', $column);
    }
    
    public function resolveWhere()
    {
        if (!$this->where) return '';
        $clauses = [];
        foreach ($this->where as $where) {
            $clauses[] = $this->resolveWhereClause($where);
        }
        return ' WHERE ' . implode(' AND ', $clauses);
    }
    
    public function resolveValue($value)
    {
        if ($value instanceof \DateTime) {
            # [FIXME] Non Mysql Date values
            return $value->format('Y-m-d H:i:s');
        }
        return $value;
    }
    
    public function resolveGroup()
    {
        if (!count($this->groupBy)) return '';
        
        $columns = array_map([$this, 'escapeColumn'], $this->groupBy);
        return ' GROUP BY ' . implode(', ', $columns);
    }
    
    public function resolveHaving()
    {
        if (!$this->having) return '';
        $clauses = [];
        foreach ($this->having as $where) {
            $clauses[] = $this->resolveWhereClause($where);
        }
        return ' HAVING ' . implode(' AND ', $clauses);
    }
    
    public function resolveLimit()
    {
        if (is_null($this->limit) && is_null($this->offset))
        {
            return '';
        }
        if (is_null($this->offset))
        {
            return " LIMIT {$this->limit}";
        }
        return " LIMIT {$this->offset},{$this->limit}";
    }

    public function resolveSort()
    {
        $sortlist = [];
        foreach ($this->sortBy as $sort) {
            if ($sort['sort']) $sortlist[] = $this->escapeColumn($sort['sort']) . ' ' . $sort['dir'];
        }
        
        if ($sortlist) {
            return ' ORDER BY ' . implode(', ', $sortlist);
        }
        
        return '';
    }
    
    public function escapeTable($table)
    {
        # [FIXME] Alternative engines
        if (!is_array($table)) return '`' . $table . '`';
        if (count($table) == 1) {
            list($key) = array_keys($table);
            if (is_numeric($key)) return '`' . $table[$key] . '`';
            return '`' . $key . '` as `' . $table[$key] . '`';
        }
        
        if (count($table) == 2) {
            list($key, $key2) = array_keys($table);
            if (is_numeric($key2)) return '`' . $table[$key] . '`.`' . $table[$key2] . '`';
            return '`' . $table[$key] . '`.`' . $key2 . '` as `' . $table[$key2] . '`';
        }

        if (count($table) == 3) {
            list($key, $key2, $key3) = array_keys($table);
            if (is_numeric($key3)) return '`' . $table[$key] . '`.`' . $table[$key2] . '`.`' . $table[$key3] . '`';
            return '`' . $table[$key] . '`.`' . $table[$key2] . '`.`' . $key3 . '` as `' . $table[$key3] . '`';
        }
        
        throw new Exception\QueryBuilder('Cannot resolve table name', $table);
    }

    public function escapeColumn($rawcolumn)
    {
        // Cowardly refuse to process SqlStrings
        if ($rawcolumn instanceof SqlString) return $rawcolumn;
        
        # [FIXME] Alternative engines
        $q = '`';
        $alias = '';

        // Parse aliases
        if (is_array($rawcolumn)) {
            list($alias) = array_values($rawcolumn);
            list($rawcolumn) = array_keys($rawcolumn);
        }

        // Regex out parts - Matching Examples:
        // column                    => "column"
        // `column`                  => "column"
        // table.column              => "table","column"
        // `table`.`column`          => "table","column"
        // `schema`.`table`.`column` => "schema","table","column"
        // `column.column`           => "column.column"
        // table.`column.column`     => "table","column.column"
        preg_match('/^(?:`(.+?)`|(.+?))(?:\.`?(.+?)`?)?(?:\.`?(.+?)`?)?$/', $rawcolumn, $column);
        $first = $column[1] ?: $column[2];
        $second = count($column) == 4 ? $column[3] : '';
        $third = count($column) == 5 ? $column[4] : '';
        
        // Quote parts
        if ($first && $first != '*')   $first =  $q . $first  . $q;
        if ($second && $second != '*') $second = $q . $second . $q;
        if ($third && $third != '*')   $third =  $q . $third  . $q;
        if ($alias)                    $alias =  $q . $alias  . $q;
        
        // Create column
        if ($third) {
            $return = $first . '.' .  $second .  '.' .  $third;
        } elseif ($second) {
            $return = $first . '.' .  $second;
        } elseif ($first) {
            $return = $first;
        }
        
        if ($alias) $return .= ' as ' . $alias;
        
        return $return;
    }
}
