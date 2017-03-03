<?php
namespace Automatorm\Interfaces;

use Automatorm\Interfaces\DataAccess as DataAccessInterface;
use Automatorm\Interfaces\SchemaGenerator as SchemaGeneratorInterface;

interface Connection
{
    /**
     * Return a PDO instance based on the supplied connection details.
     * This object should always return the same PDO instance until ->disconnect() is called.
     *
     * @return PDO Instance of PDO connection
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
    public function getDataAccessor();
    
    /**
     * Retrieve an instance of an appropriate dataAccessor.
     *
     * @return SchemaGeneratorInterface
     */
    public function getSchemaGenerator();
}
