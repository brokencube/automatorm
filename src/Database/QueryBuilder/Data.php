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

class Data
{
    const PLACEHOLDER = '?';
    
    public $column;
    public $value;
    
    public function __construct(Column $column, $value)
    {
        $this->column = $column;
        $this->value = $value;
    }

    public function __clone()
    {
        $this->column = clone $this->column;
        if (is_object($this->value)) {
            $this->value = clone $this->value;
        }
    }
    
    public function render(QueryBuilder $query) : string
    {
        return $this->renderColumn($query) . ' = ' . $this->renderValue($query);
    }
    
    public function renderColumn(QueryBuilder $query) : string
    {
        return $this->column->render($query);
    }

    public function renderValue(QueryBuilder $query) : string
    {
        if (is_null($this->value)) {
            return 'null'; 
        }
        
        if ($this->value instanceof \DateTimeInterface) {
            return "'" . $this->value->format('Y-m-d H:i:s') . "'";
        }

        if ($this->value instanceof SqlString) {
            return (string) $this->value;
        }
        
        $query->addData([$this->value]);
        return static::PLACEHOLDER;
    }
}
