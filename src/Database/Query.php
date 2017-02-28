<?php
namespace Automatorm\Database;

use Automatorm\Exception;
use Automatorm\Interfaces\Connection as ConnectionInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

class Query implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    
    protected $connection;      // Connection object
    protected $error;
    protected $sql = []; // Array of SQL queries to run
    protected $lock = false;
    protected $debug;

    // Readonly access to object properties
    public function __get($var)
    {
        switch ($var) {
            case 'connection':
            case 'error':
            case 'sql':
            case 'debug':
            case 'lock':
                return $this->{$var};
        }
        return $this->debug[$var];
    }
    
    public static function run($sql, $connection = 'default')
    {
        $query = new static($connection, $sql);
        return $query->execute();
    }
    
    // Create a new query container
    public function __construct($connection = 'default', $sql = null)
    {
        if ($connection instanceof ConnectionInterface) {
            $this->connection = $connection;
        } elseif (is_string($connection)) {
            $this->connection = Connection::get($connection);
        } else {
            throw new Exception\Database('Unknown connection', $connection);
        }
        
        if ($sql) {
            $this->sql($sql);
        }
        
        // Default Logger
        $this->logger = $this->connection->getLogger();
    }
    
    // Add arbitary SQL to the query queue
    public function sql($sql, $data = [])
    {
        if ($sql instanceof QueryBuilder) {
            $this->sql[] = Sql::build($sql);
        } elseif ($sql instanceof Sql) {
            $this->sql[] = $sql;
        } else {
            $this->sql[] = new Sql(trim($sql), $data);
        }
        
        return $this;
    }
    
    ////////////////
    
    public function insertId($position = 0)
    {
        return $this->debug[$position]['insert_id'];
    }
    
    public function affectedRows($position = 0)
    {
        return $this->debug[$position]['affected_rows'];
    }
    
    /////////////////
    
    public function escape($string)
    {
        $pdo = $this->connection->connect();
        return $pdo->quote($string);
    }
    
    public function transaction()
    {
        $pdo = $this->connection->connect();
        $pdo->beginTransaction();
    }
    
    public function commit()
    {
        $pdo = $this->connection->connect();
        $pdo->commit();
    }
    
    public function execute()
    {
        $pdo = $this->connection->connect();
        
        // We are only allowed to execute each Query object once!
        if ($this->lock) {
            throw new Exception\Database("QUERY_LOCKED: This query has already been executed", $this);
        }
        $this->lock = true;
        
        $count = 0;
        $return = [];
        
        try {
            foreach ($this->sql as $query) {
                $time = microtime(true);
                $result = $query->execute($pdo);
                if ($result->columnCount()) {
                    $return[$count] = $result->fetchAll(\PDO::FETCH_ASSOC);
                } else {
                    $return[$count] = [];
                }
                
                // Store some useful data about this set of results
                $this->debug[$count]['insert_id'] = $pdo->lastInsertId();
                $this->debug[$count]['affected_rows'] = $result->rowCount();
                $this->debug[$count]['time'] = microtime(true) - $time;
                $count++;
            }
        } catch (\PDOException $e) {
            $this->debug[$count]['time'] = microtime(true) - $time;
            $this->error = $e->getMessage();
            throw new Exception\Query($this, $e);
        } finally {
            // Log the query with Psr3 Logger
            $this->logQuery($this);
        }
        
        // Finally, return the results of the query
        return $return;
    }
    
    public function logQuery(Query $query)
    {
        if (!$this->logger) {
            return;
        }
        
        $count = 0;
        foreach ($query->sql as $sql) {
            $preview = preg_replace('/\s+/m', ' ', substr($sql->sql, 0, 100));
            $time = number_format($query->debug[0]['time'] * 1000, 2);
            
            $message = "{$time}ms Con:{$query->connection->name} | $preview";
            $this->logger->notice(
                $message,
                [
                    'query' => $sql->sql,
                    'data' => $sql->data,
                    'debug' => $query->debug[$count],
                    'error' => $query->error
                ]
            );
        }
    }
}
