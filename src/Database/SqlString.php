<?php
namespace Automatorm\Database;

use Automatorm\Database\Interfaces\Renderable;
use Automatorm\Database\QueryBuilder;

class SqlString implements Renderable
{
    private $string;
    
    public function __construct($string)
    {
        $this->string = $string;
    }
    
    public function __toString()
    {
        return $this->string;
    }
    
    public function render(QueryBuilder $query) : string
    {
        return $this->string;
    }
}
