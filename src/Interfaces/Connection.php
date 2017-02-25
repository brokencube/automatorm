<?php
namespace Automatorm\Interfaces;

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
    
    public function getDataAccessor();
}
