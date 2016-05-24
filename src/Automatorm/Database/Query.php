<?php
namespace Automatorm\Database;

use Automatorm\Exception;

class Query implements \Psr\Log\LoggerAwareInterface
{
    use \Psr\Log\LoggerAwareTrait;
    
    protected $db;      // Connection object
    
    protected $sql = []; // Array of SQL queries to run
    protected $lock = false;
    protected $debug;

    // Readonly access to object properties
    public function __get($var) {
        switch($var) {
            case 'name':
            case 'mysql':
            case 'sql':
            case 'debug':
            case 'lock':
                return $this->{$var};
        }
        return $this->debug[$var];
    }
    
    static public function run($sql, $connection = 'default')
    {
        return static::create($connection, $sql)->execute();
    }
    
    // Create a new query container
    public function __construct($connection = 'default', $sql = null)
    {
        if ($connection instanceof Connection) {
            $this->db = $connection;
        } elseif (is_string($connection)) {
            $this->db = Connection::autoconnect($connection);
        } else {
            throw new Ex\Database('Unknown connection', $connection);
        }
        
        if ($sql) $this->sql($sql);
        
        // Default Logger
        $this->logger = $this->db->getLogger();
    }
    
    // Add arbitary SQL to the query queue
    public function query($sql, $data = [])
    {
        if ($sql instanceof QueryBuilder) {
            $this->sql[] = Sql::build($sql);
        }
        if ($sql instanceof Sql) {
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
        return $this->db->quote($string);
    }
    
    public function transaction()
    {
        $this->db->beginTransaction();
    }
    
    public function commit()
    {
        $this->db->commit();
    }
    
    public function execute()
    {
        // We are only allowed to execute each Query object once!
        if ($this->lock) throw new Exception\Database('QUERY_LOCKED', "This query has already been executed", $this);
        $this->lock = true;
        
        $count = 0;
        $return = [];
        
        try {
            foreach($this->sql as $query) {
                $time = microtime(true);
                $result = $query->execute($this->db);
                if ($result->columnCount()) {
                    $return[$count] = $result->fetchAll(\PDO::FETCH_ASSOC);    
                } else {
                    $return[$count] = [];
                }
                
                // Store some useful data about this set of results
                $this->debug[$count]['insert_id'] = $this->db->lastInsertId();
                $this->debug[$count]['affected_rows'] = $result->rowCount();
                $this->debug[$count]['time'] = microtime(true) - $time;
                $count++;
            }
        }
        catch (\PDOException $e) {
            $this->debug[$count]['time'] = microtime(true) - $time;
            
            throw new Exception\Query($this, $e);
        }
        finally {
            // Log the query with Psr3 Logger
            $this->logQuery($this);
        }
        
        // Finally, return the results of the query
        return $return;
    }
    
    public function logQuery(Query $query)
    {
        if ($this->disabled) return;
        
        $count = 0;
        foreach($query->sql as $sql)
        {
            $preview = Log::format(substr($sql->sql,0,100),true);
            $time = number_format($query->debug[0]['time'] * 1000, 2);
            
            $message = "{$time}ms Con:{$query->name} | $preview";
            $this->logger->notice(
                $message,
                [
                    'query' => $sql->sql,
                    'data' => $sql->data,
                    'debug' => $query->debug[$count]
                ]
            );
        }
    }
}