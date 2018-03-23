<?php
/**
 * Query Builder - build SQL queries programatically
 *
 * @package Automatorm\Database
 */

namespace Automatorm\Database\QueryBuilder;

use Automatorm\Database\SqlString;
use Automatorm\Database\QueryBuilder;
use Automatorm\Database\Interfaces\Renderable;
use Automatorm\Exception;

class Join implements Renderable
{
    protected $table;
    protected $type;
    protected $where;
    
    public function __construct($table, $rawtype = '')
    {
        $this->table = $table instanceof Table ? $table : new Table($table);
        
        switch (strtolower($rawtype)) {
            case '':
                $this->type = 'JOIN';
                break;
            case 'left':
                $this->type = 'LEFT JOIN';
                break;
            case 'left outer':
                $this->type = 'LEFT OUTER JOIN';
                break;
            case 'cross':
                $this->type = 'CROSS JOIN';
                break;
            default:
                throw new Exception\QueryBuilder('Unknown Join Type');
        }
    }
    
    public function on(array $clauses) : self
    {
        foreach ($clauses as $key => $value) {
            if (is_numeric($key) && $value instanceof SqlString) {
                $this->where[] = $value;
            } else {
                [$column, $affix] = Expression::extractAffix($key);
                $this->where[] = new Expression(new Column($column), $affix, new Column($value));
            }
        }
        return $this;
    }

    public function where(array $clauses) : self
    {
        foreach ($clauses as $key => $value) {
            if (is_numeric($key) && $value instanceof SqlString) {
                $this->where[] = $value;
            } else {
                [$column, $affix] = Expression::extractAffix($key);
                $this->where[] = new Expression(new Column($column), $affix, $value);
            }
        }
        return $this;
    }
    
    public function render(QueryBuilder $query) : string
    {
        $output = $this->type . " " . $this->table->render($query);
        
        if ($this->where) {
        $strings = [];
            foreach ($this->where as $clause) {
                $strings[] = $clause->render($query);
            }
            $clauses = implode(' AND ', $strings);
            
            $output .= " ON " . $clauses;
        }
        
        return $output;
    }
}
