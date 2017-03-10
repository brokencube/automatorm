<?php
namespace Automatorm\Database;

use Automatorm\Exception as Ex;
use Automatorm\Orm\DataAccess;
use Automatorm\Orm\SchemaGenerator;
use Automatorm\Interfaces\Connection as ConnectionInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use PDO;
use PDOException;

class Connection implements LoggerAwareInterface, ConnectionInterface
{
    use LoggerAwareTrait;
    public function getLogger()
    {
        return $this->logger;
    }
    
    protected static $connections = [];
    protected static $details = [];
    
    /************************
     * CONNECTION FUNCTIONS *
     ************************/
    public static function register(array $db, $name = 'default', array $options = null, LoggerInterface $logger = null)
    {
        if (key_exists($name, self::$details)) {
            throw new Ex\Database("Database connection '{$name}' already registered", $name);
        }
        return self::$details[$name] = new static($db, $name, $options, $logger);
    }

    public static function registerPDO(PDO $pdo, $name = 'default', LoggerInterface $logger = null)
    {
        if (key_exists($name, self::$details)) {
            throw new Ex\Database("Database connection '{$name}' already registered", $name);
        }
        return self::$details[$name] = new static($pdo, $name, null, $logger);
    }

    public static function get($name = 'default')
    {
        if (!self::$details[$name]) {
            throw new Ex\Database("Database connection '$name' not registered.", $name);
        }
        return self::$details[$name];
    }

    public static function autoconnect($name = 'default')
    {
        return static::get($name)->connect();
    }
    
    public function __get($var)
    {
        if (property_exists($this, $var)) {
            return $this->{$var};
        }
        return null;
    }
   
    protected $name;
    protected $type;
    protected $user;
    protected $pass;
    protected $server;
    protected $database;
    protected $options;
    protected $connection;
    
    protected $schemaGenerator;
    protected $dataAccess;
    
    protected function __construct($details, $name = 'default', array $options = null, LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        
        if ($details instanceof PDO) {
            $this->name = $name;
            $this->connection = $details;
            return;
        }
        
        if (is_array($details)) {
            $this->name = $name;
            $this->unix_socket = $details['unix_socket'];
            $this->server = $details['server'];
            $this->user = $details['user'];
            $this->pass = $details['pass'];
            $this->database = $details['database'];
            $this->options = $options ?: [
                PDO::ATTR_PERSISTENT => true,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ];
            $this->type = $details['type'] ?: 'mysql';
            
            if ($this->unix_socket && $this->host) {
                throw new Ex\Database("Must use host OR unix_socket - both supplied", $details);
            }
            
            $this->schemaGenerator = new SchemaGenerator($this);
            $this->dataAccess = new DataAccess($this);
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
    
    public function getDataAccessor()
    {
        return $this->dataAccess;
    }

    public function getSchemaGenerator()
    {
        return $this->schemaGenerator;
    }
}
