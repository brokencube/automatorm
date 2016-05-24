<?php
namespace automatorm\Database;

use automatorm\Exception as Ex;

class Connection implements \Psr\Log\LoggerAwareInterface
{
    use \Psr\Log\LoggerAwareTrait;
    public function getLogger()
    {
        return $this->logger;
    }
    
    protected static $connections = array();
    protected static $details = array();
    
    /************************
     * CONNECTION FUNCTIONS *
     ************************/
    public static function register(array $db, $name = 'default', array $options = null, \Psr\Log\LoggerInterface $logger = null)
    {
        if (key_exists($name, self::$details)) throw new Ex\Database("Database connection '{$name}' already registered", $name);
        return self::$details[$name] = new static($db, $name, $options, $logger);
    }

    public static function registerPDO(\PDO $pdo, $name = 'default', \Psr\Log\LoggerInterface $logger = null)
    {
        if (key_exists($name, self::$details)) throw new Ex\Database("Database connection '{$name}' already registered", $name);
        return self::$details[$name] = new static($pdo, $name, null, $logger);
    }

    public static function get($name = 'default')
    {
        if (!self::$details[$name]) throw new Ex\Database("Database connection '$name' not registered.", $name);
        return self::$details[$name];
    }

    public static function autoconnect($name = 'default')
    {
        return static::get($name)->connect();
    }
    
    public function __get($var)
    {
        if (property_exists($this, $var)) return $this->{$var};
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
    
    protected function __construct($details, $name = 'default', array $options = null, \Psr\Log\LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        
        if ($details instanceof \PDO)
        {
            $this->name = $name;
            $this->connection = $details;
            return;
        }
        
        if (is_array($details))
        {
            $this->name = $name;
            $this->server = $details['server'];
            $this->user = $details['user'];
            $this->pass = $details['pass'];
            $this->database = $details['database'];
            $this->options = $options ?: [
                \PDO::ATTR_PERSISTENT => true,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
            ];
            $this->type = $details['type'];
        }
        
        throw new Ex\Database("Not enough details to construct Database object", $details);
    }
    
    public function disconnect()
    {
        unset($this->connection);
    }
    
    public function connect()
    {
        if ($this->connection) return $this->connection;
        
        $dsn = $this->type . ':host=' . $this->server . ';dbname=' . $this->database.';charset=utf8';
        try {
            $this->connection = new \PDO($dsn, $this->user, $this->pass, $this->options);
        } catch (\PDOException $e) {
            unset ($this->connection);
            throw new Ex\Database("Database connection failed ({$dsn})", $this, $e);
        }
        
        return $this->connection;
    }
}
