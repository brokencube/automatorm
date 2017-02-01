<?php
namespace Automatorm\Database;

class SqlString
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
}
