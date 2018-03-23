<?php
/**
 * Query Builder - build SQL queries programatically
 *
 * @package Automatorm\Database
 */

namespace Automatorm\Database\Interfaces;

use Automatorm\Database\QueryBuilder;

interface Renderable
{
    public function render(QueryBuilder $query) : string;
}
