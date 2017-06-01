<?php
namespace Automatorm\Interfaces;

use Automatorm\Interfaces\DataAccess as DataAccessInterface;
use Automatorm\Interfaces\SchemaGenerator as SchemaGeneratorInterface;

interface Connection
{
    /**
     * Return an object that can be used by the DataAccessor to retrieve results
     */
    public function connect();
    
    /**
     * Clear connection.
     * A call to connect() after this call will return a new PDO instance.
     *
     * @return null
     */
    public function disconnect();
    
    /**
     * Retrieve an instance of an appropriate dataAccessor.
     *
     * @return DataAccessInterface
     */
    public function getDataAccessor() : DataAccessInterface;
    
    /**
     * Retrieve an instance of an appropriate dataAccessor.
     *
     * @return SchemaGeneratorInterface
     */
    public function getSchemaGenerator() : SchemaGeneratorInterface;
}
