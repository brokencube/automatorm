<?php
namespace Automatorm\Fake;

use Automatorm\Fake\DataAccess;
use Automatorm\Fake\SchemaGenerator;
use Automatorm\Interfaces\Connection as ConnectionInterface;

class Connection implements ConnectionInterface
{
    public $data;
    public $schemaGenerator;
    public $dataAccess;
    public function __construct($data)
    {
        $this->data = $data;
        $this->schemaGenerator = new SchemaGenerator($this);
        $this->dataAccess = new DataAccess($this);
    }
    
    /**
     * Pretend to clear connection.
     *
     * @return null
     */
    public function disconnect()
    {
    }

    /**
     * Return fake data source
     *
     * @return string Flat data source
     */
    public function connect()
    {
        return $this->data;
    }
    
    public function getDataAccessor()
    {
        return $this->dataAccess;
    }

    public function getSchemaGenerator()
    {
        return $this->schemaGenerator;
    }
}
