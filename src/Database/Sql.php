<?php
namespace Automatorm\Database;

class Sql
{
    public $sql;
    public $data;
    
    public function __construct($sql, $data = [])
    {
        $this->sql = $sql;
        if (!is_array($data)) $data = [$data];
        $this->data = $data;
    }
    
    public function execute(\PDO $pdo)
    {
        $pdostatement = $pdo->prepare($this->sql);
        $pdostatement->execute($this->data);
        return $pdostatement;
    }
    
    public function __toString()
    {
        return $this->sql;
    }
    
    public static function build(QueryBuilder $query)
    {
        list ($sql, $data) = $query->resolve();
        return new static($sql, $data);
    }
}
