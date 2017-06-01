<?php
namespace Automatorm\DataLayer\Database;

use Automatorm\Exception as Ex;
use Automatorm\DataLayer\Database\DataAccess;
use Automatorm\DataLayer\Database\SchemaGenerator;
use Automatorm\Interfaces\DataAccess as DataAccessInterface;
use Automatorm\Interfaces\SchemaGenerator as SchemaGeneratorInterface;
use Automatorm\Interfaces\Connection as ConnectionInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use PDO;
use PDOException;

class Connection implements ConnectionInterface
{
    use LoggerAwareTrait;
    public function getLogger()
    {
        return $this->logger;
    }
    
    /************************
     * CONNECTION FUNCTIONS *
     ************************/
    public static function register(array $db, LoggerInterface $logger = null, array $options = [])
    {
        return new static($db, $options, $logger);
    }

    public static function registerPDO(PDO $pdo, LoggerInterface $logger = null)
    {
        return new static($pdo, [], $logger);
    }

    public function __get($var)
    {
        if (property_exists($this, $var)) {
            return $this->{$var};
        }
        return null;
    }
   
    protected $type;
    protected $user;
    protected $pass;
    protected $server;
    protected $database;
    protected $options;
    
    protected $connection;
    protected $schemaGenerator;
    protected $dataAccess;
    
    protected function __construct($details, array $options = [], LoggerInterface $logger = null)
    {
        $this->schemaGenerator = new SchemaGenerator($this);
        $this->dataAccess = new DataAccess($this);
        $this->logger = $logger;
        $options = $options + [
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ];
        
        if ($details instanceof PDO) {
            $this->connection = $details;
        } elseif (is_array($details)) {
            $this->unix_socket = $details['unix_socket'];
            $this->server = $details['server'];
            $this->user = $details['user'];
            $this->pass = $details['pass'];
            $this->database = $details['database'];
            $this->options = $options;
            $this->type = $details['type'] ?: 'mysql';
            
            if ($this->unix_socket && $this->server) {
                throw new Ex\Database("Must use server OR unix_socket - both supplied", $details);
            }
        } else {
            throw new Ex\Database("Not enough details to construct Database object", $details);
        }
    }
    
    /**
     * Clear connection.
     * A call to connect() after this call will return a new PDO instance.
     *
     * @return null
     */
    public function disconnect()
    {
        unset($this->connection);
    }

    /**
     * Return a PDO instance based on the supplied connection details.
     * This object should always return the same PDO instance until ->disconnect() is called.
     *
     * @return PDO Instance of PDO connection
     */
    public function connect()
    {
        if ($this->connection) {
            return $this->connection;
        }
        
        if ($this->unix_socket) {
            $dsn = $this->type . ':unix_socket=' . $this->unix_socket . ';dbname=' . $this->database.';charset=utf8';
        } else {
            $dsn = $this->type . ':host=' . $this->server . ';dbname=' . $this->database.';charset=utf8';
        }
        
        try {
            $this->connection = new PDO($dsn, $this->user, $this->pass, $this->options);
            return $this->connection;
        } catch (PDOException $e) {
            unset($this->connection);
            throw new Ex\Database("Database connection failed ({$dsn})", $this, $e);
        }
    }
    
    public function getDataAccessor() : DataAccessInterface
    {
        return $this->dataAccess;
    }

    public function getSchemaGenerator() : SchemaGeneratorInterface
    {
        return $this->schemaGenerator;
    }
}
