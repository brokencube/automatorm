<?php
/**
 * Query Builder - build SQL queries programatically
 *
 * @package Automatorm\Database
 */

namespace Automatorm\Database\QueryBuilder;

use Automatorm\Database\QueryBuilder;

class FunctionColumn extends Column
{
    protected $column;
    protected $func;
    
    public function __construct($function, $column)
    {
        $this->column = new Column($column);
        $this->func = $function;
    }
    
    public function __clone()
    {
        $this->column = clone $this->column;
    }
    
    public function render(QueryBuilder $query) : string
    {
        return $this->column->renderFunction($query, $this->func);
    }
}
