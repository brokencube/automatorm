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
        if (!$this->where) {
            $this->where = new Where();
        }
        
        $this->where->addOnClauses($clauses);
        return $this;
    }

    public function where(array $clauses) : self
    {
        if (!$this->where) {
            $this->where = new Where();
        }
        
        $this->where->addClauses($clauses);
        return $this;
    }
    
    public function render(QueryBuilder $query) : string
    {
        $output = $this->type . " " . $this->table->render($query);
        
        if ($this->where) {
            $output .= " ON " . $this->where->render($query);
        }
        
        return $output;
    }
}
