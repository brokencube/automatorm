<?php
namespace Automatorm\DataLayer\Fake;

use Automatorm\DataLayer\Fake\DataAccess;
use Automatorm\DataLayer\Fake\SchemaGenerator;
use Automatorm\Interfaces\DataAccess as DataAccessInterface;
use Automatorm\Interfaces\SchemaGenerator as SchemaGeneratorInterface;
use Automatorm\Interfaces\Connection as ConnectionInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

class Connection implements ConnectionInterface
{
    use LoggerAwareTrait;
    public function getLogger()
    {
        return $this->logger;
    }
    
    public $data;
    public $schemaGenerator;
    public $schemaName;
    public $dataAccess;
    public $store;
    public function __construct(DataStore $datastore, $schemaname)
    {
        $this->store = $datastore;
        $this->data = $datastore->getData($schemaname);
        $this->schemaName = $schemaname;
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
        $this->data->generateData($this);
        return $this->data;
    }
    
    public function getDataAccessor() : DataAccessInterface
    {
        return $this->dataAccess;
    }

    public function getSchemaGenerator() : SchemaGeneratorInterface
    {
        return $this->schemaGenerator;
    }
    
    public function getDataStore() : DataStore
    {
        return $this->store;
    }
}
