<?php
namespace Automatorm\Database;

use Automatorm\Exception;

class QueryBuilder
{
    const ENGINES = ['mysql', 'postgres', 'mssql'];
    public static $engine = "mysql";
    public static $requireWhereClause = true;
    
    protected $type;
    protected $table;
    protected $table_alias;
    protected $columns = [];
    protected $count;
    protected $set = [];
    protected $joins = [];
    protected $where = [];
    protected $limit;
    protected $offset;
    protected $sortBy = [];
    
    protected $data = [];
    
    // ENTRY POINTS
    public static function select($table, array $columns = ['*'])
    {
        $query = new static('select');
        $query->columns = $columns;
        $query->table = $table;
        
        return $query;
    }

    public static function delete($table, $where = [])
    {
        $query = new static('delete');
        $query->table = $table;
        $query->where($where);
        
        return $query;
    }

    public static function count($table, $column = '*')
    {
        $query = new static('count');
        $query->count = $column;
        $query->table = $table;
        
        return $query;
    }

    public static function insert($table, array $columndata = [])
    {
        $query = new static('insert');
        $query->set = $columndata;
        $query->table = $table;

        return $query;
    }

    public static function update($table, array $columndata = [])
    {
        $query = new static('update');
        $query->set = $columndata;
        $query->table = $table;

        return $query;        
    }

    public static function replace($table, array $columndata = [])
    {
        $query = new static('replace');
        $query->table = $table;

        return $query;                
    }
    
    protected function __construct($type)
    {
        $this->type = $type; 
    }

    
    // BUILDER FUNCTIONS
    public function columns(array $columns)
    {
        $this->columns = $columns;
        return $this;
    }
    
    public function where($clauses)
    {
        foreach ($clauses as $key => $value)
        {
            if (is_numeric($key) && $value instanceof SqlString) {
                $this->where[] = $value;
            } else {
                $this->where[] = $this->extractWhereColumn($key, $value);    
            }
        }
        return $this;
    }
    
    public function extractWhereColumn($column, $value, $onclause = false)
    {
        $comparitor = '=';
        
        preg_match('/^([!=<>%]*)([^!=<>%]+)([!=<>%]*)$/', $column, $parts);
        $affix = $parts[1] ?: $parts[3];
        $column = $parts[2];
        
        // Special cases for null values in where clause
        if (is_null($value)) {
            if ($affix == '!') return [$column, "is not null", null];
            return [$column, "is null", null];
        }
        
        // Special case for in clauses
        if (is_array($value) && !$onclause) {
            // Special case for empty in clauses
            if (!count($value)) return $affix == '!' ? [$column, "=", 'true'] : [$column, "=", 'false'];
            
            if ($affix == '!') {
                return [$column, "not in", $value];
            } else {
                return [$column, "in", $value];
            }
        }
        
        switch ($affix) {
            case '=':   $comparitor = '=';        break;
            case '!':   $comparitor = '!=';       break;
            case '!=':  $comparitor = '!=';       break;
            case '>':   $comparitor = '>';        break;
            case '<':   $comparitor = '<';        break;
            case '>=':  $comparitor = '>=';       break;
            case '<=':  $comparitor = '<=';       break;
            case '%':   $comparitor = 'like';     break;
            case '!%':  $comparitor = 'not like'; break;
            case '%!':  $comparitor = 'not like'; break;                    
        }
        
        return [$column, $comparitor, $value];
    }
    
    public function limit($limit, $offset = null)
    {
        $this->limit = intval($limit);
        $this->offset = is_null($offset) ? null : intval($offset);
        return $this;
    }
    
    public function sortBy($sort, $dir = 'desc')
    {
        $this->sortBy[] = ['sort' => $sort, 'dir' => $dir == 'desc' ? 'desc' : 'asc'];
        return $this;
    }
    
    public function join($table, $columnclauses = [], $valueclauses = [])
    {
        $this->joins[] = ['table' => $table, 'where' => [], 'on' => []];
        
        if ($columnclauses) $this->joinOn($columnclauses);
        if ($valueclauses) $this->joinWhere($valueclauses);
        
        return $this;
    }
    
    public function joinOn($columnclauses = [])
    {
        end($this->joins);
        $joinkey = key($this->joins);
        
        foreach ($columnclauses as $key => $value)
        {
            if (is_numeric($key) && $value instanceof SqlString) {
                $this->joins[$joinkey]['on'][] = $value;
            } else {
                $this->joins[$joinkey]['on'][] = $this->extractWhereColumn($key, $value, true);    
            }
        }
        return $this;
    }

    public function joinWhere($columnclauses = [])
    {
        end($this->joins);
        $joinkey = key($this->joins);
        
        foreach ($columnclauses as $key => $value)
        {
            if (is_numeric($key) && $value instanceof SqlString) {
                $this->joins[$joinkey]['where'][] = $value;
            } else {
                $this->joins[$joinkey]['where'][] = $this->extractWhereColumn($key, $value);
            }
        }
        
        return $this;
    }
    
    // RESOLVER FUNCTIONS
    public function resolve()
    {
        $table = $this->resolveTable();
        
        switch ($this->type)
        {
            case 'count':
                $columns = $this->resolveCount();
                $join = $this->resolveJoins();
                $where = $this->resolveWhere();
                $limit = $this->resolveLimit();
                $sort = $this->resolveSort();
                
                return ["SELECT $columns FROM $table{$join}{$where}{$limit}{$sort};", $this->data];
                
            case 'select':
                $columns = $this->resolveColumns();
                $join = $this->resolveJoins();
                $where = $this->resolveWhere();
                $limit = $this->resolveLimit();
                $sort = $this->resolveSort();
                
                return ["SELECT $columns FROM $table{$join}{$where}{$limit}{$sort};", $this->data];
            
            case 'insert':
                $join = $this->resolveJoins();
                $data = $this->resolveColumnData();
                $limit = $this->resolveLimit();
                
                return ["INSERT INTO $table{$join}{$data}{$limit};", $this->data];

            case 'update':
                $join = $this->resolveJoins();
                $data = $this->resolveColumnData();
                $where = $this->resolveWhere();
                $limit = $this->resolveLimit();
                
                return ["UPDATE $table{$join}{$data}{$where}{$limit};", $this->data];

            case 'delete':
                $where = $this->resolveWhere();
                $limit = $this->resolveLimit();
                
                return ["DELETE FROM $table{$where}{$limit};", $this->data];
        }
    }
    
    public function resolveTable()
    {
        return $this->escapeTable($this->table);
    }
    
    public function resolveJoins()
    {
        if (!$this->joins) return '';
        
        $joinstring = '';
        foreach ($this->joins as $join)
        {
            $clauses = [];
            $joinstring .= ' JOIN ' . $this->escapeTable($join['table']);
            if ($join['where'])
            {
                foreach ($join['where'] as $where) {
                    if ($where instanceof SqlString) {
                        $clauses[] = (string) $where;
                    } else {
                        list($column, $comp, $value) = $where;
                        if (is_null($value)) {
                            $clauses[] = $this->escapeColumn($column) . ' ' . $comp;
                        } elseif (is_array($value)) {
                            $count = count($value);
                            $clauses[] = $this->escapeColumn($column) . ' ' . $comp . ' ' . '(' . implode(',', array_fill(0, $count, '?')) . ')';
                            foreach ($value as $val) {
                                $this->data[] = $this->resolveValue($val);
                            }
                        } else {
                            $clauses[] = $this->escapeColumn($column) . ' ' . $comp . ' ?';
                            $this->data[] = $this->resolveValue($value);
                        }
                    }
                }
            }

            if ($join['on'])
            {
                foreach ($join['on'] as $where) {
                    if ($where instanceof SqlString) {
                        $clauses[] = (string) $where;
                    } else {
                        list($column, $comp, $value) = $where;
                        $clauses[] = $this->escapeColumn($column) . ' ' . $comp . ' ' . $this->escapeColumn($value);
                    }
                }
            }
            
            
            if ($clauses) {
                $joinstring .= ' ON ' . implode(' AND ', $clauses);    
            }
        }
        
        return $joinstring;
    }
    
    public function resolveColumnData()
    {
        $column = [];
        foreach ($this->set as $col => $value) {
            if (is_array($value)) {
                
            } else {
                $column[] = $this->escapeColumn($col) .  ' = ?';
                $this->data[] = $this->resolveValue($value);
            }
        }
        return ' SET ' . implode(', ', $column);
    }
    
    public function resolveCount()
    {
        if ($this->count == '*') return 'count(*) as count';
        return 'count(*) as count';
        $this->columns[] = $this->count;
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
            if ($where instanceof SqlString) {
                $clauses[] = (string) $where;
            } else {
                list($column, $comp, $value) = $where;
                if (is_null($value)) {
                    $clauses[] = $this->escapeColumn($column) . " $comp";
                } elseif (is_array($value)) {
                    $count = count($value);
                    $clauses[] = $this->escapeColumn($column) . ' ' . $comp . ' ' . '(' . implode(',', array_fill(0, $count, '?')) . ')';
                    foreach ($value as $val) {
                        $this->data[] = $this->resolveValue($val);
                    }
                } else {
                    $clauses[] = $this->escapeColumn($column) . " $comp ?";
                    $this->data[] = $this->resolveValue($value);
                }
            }
        }
        return ' WHERE ' . implode(' AND ', $clauses);
    }
    
    public function resolveValue($value)
    {
        # [FIXME] Date values
        return $value;
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
        if (!$this->sortBy) return '';
        
        $string = ' ORDER BY ';
        foreach ($this->sortBy as $sort)
        {
            $string .= $this->escapeColumn($sort['sort']) . ' ' . $sort['dir'];
        }
        
        return $string;
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
    }

    public function escapeColumn($rawcolumn)
    {
        # [FIXME] Alternative engines
        $q = '`';
        $alias = '';

        // Parse aliases
        if (is_array($rawcolumn))
        {
            list($alias) = array_values($rawcolumn);
            list($rawcolumn) = array_keys($rawcolumn);
        }

        // Regex out parts
        preg_match('/^(?:`(.+?)`|(.+?))(?:\.`?(.+?)`?)?(?:\.`?(.+?)`?)?$/', $rawcolumn, $column);
        $first = $column[1] ?: $column[2];
        $second = count($column) == 4 ? $column[3] : '';
        $third = count($column) == 5 ? $column[4] : '';
        
        // Create column
        if ($third) {
            if ($alias) return $q . $first . $q . '.' . $q . $second . $q . '.' . $q . $third . $q . ' as ' . $q . $alias . $q;
            return $q . $first . $q . '.' . $q . $second . $q . '.' . $q . $third . $q;
        } elseif ($second) {
            if ($alias) return $q . $first . $q . '.' . $q . $second . $q . ' as ' . $q . $alias . $q;
            return $q . $first . $q . '.' . $q . $second . $q;
        } elseif ($first) {
            if ($alias) return $q . $first . $q . ' as ' . $q . $alias . $q;
            return $q . $first . $q;
        }
    }
}