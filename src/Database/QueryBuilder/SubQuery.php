<?php
/**
 * Query Builder - build SQL queries programatically
 *
 * @package Automatorm\Database
 */

namespace Automatorm\Database\QueryBuilder;

use Automatorm\Database\QueryBuilder;
use Automatorm\Database\Interfaces\Renderable;
use Automatorm\Exception;

class SubQuery extends Table
{
    protected $sql;
    protected $data;
    protected $alias;
    
    public function __construct($query, ?string $alias = 'subquery')
    {
        if ($query instanceof QueryBuilder) {
            [$this->sql, $this->data] = $query->resolve();
        } elseif ($query instanceof SqlString) {
            $this->sql = (string) $query;
        } elseif (is_string($query)) {
            $this->sql = $query;
        } else {
            throw new Exception\QueryBuilder('Cannot parse subquery', $query);
        }
        
        $this->alias = $this->escape($alias);
    }

    public function render(QueryBuilder $query) : string
    {
        $query->addData($this->data);
        return '(' . $this->sql . ') AS ' . $this->alias;
    }
}
