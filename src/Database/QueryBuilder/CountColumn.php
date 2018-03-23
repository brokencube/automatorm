<?php
/**
 * Query Builder - build SQL queries programatically
 *
 * @package Automatorm\Database
 */

namespace Automatorm\Database\QueryBuilder;

use Automatorm\Database\QueryBuilder;

class CountColumn extends Column
{
    public function render(QueryBuilder $query) : string
    {
        return $this->renderFunction($query, 'COUNT');
    }
}
