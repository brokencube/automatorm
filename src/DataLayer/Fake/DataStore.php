<?php
namespace Automatorm\DataLayer\Fake;

use Automatorm\Interfaces\Connection as ConnectionInterface;
use Automatorm\Interfaces\DataAccess as DataAccessInterface;
use Automatorm\Orm\Schema;
use Automatorm\OperatorParser;

class DataStore
{
    protected $store = [];
    
    public function __construct(array $stores = [])
    {
        foreach ($stores as $store) {
            $this->addData($store);
        }
    }
    
    public function addData(Data $data)
    {
        $this->store[$data->schemaName] = $data;
    }
    
    public function getData($schemaName)
    {
        return $this->store[$schemaName];
    }
}
