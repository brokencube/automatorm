<?php
/**
 * Query Builder - build SQL queries programatically
 *
 * @package Automatorm\Database
 */

namespace Automatorm\Database\QueryBuilder;

use Automatorm\Database\QueryBuilder;
use Automatorm\Database\Interfaces\Renderable;
use Automatorm\Exception;

class Table implements Renderable
{
    protected $database;
    protected $schema;
    protected $table;
    protected $alias;
    
    public function __construct($table)
    {
        if (is_string($table)) {
            preg_match('/^
                (?:`(.+?)`|(\S+?))
                (?:\.(?:`(.+?)`|(\S+?)))?
                (?:\.(?:`(.+?)`|(\S+?)))?
                (?:\s+(?:[Aa][Ss]\s+)?(?:`(.+?)`|(\S+?)))?
                \s*$/x',
                $table, $columnparts
            );
            
            // Normalise output
            $columnparts += array_fill(0, 9, null);
            
            if ($columnparts[5] ?: $columnparts[6]) {
                $this->database = $this->escape($columnparts[1] ?: $columnparts[2]);
                $this->schema = $this->escape($columnparts[3] ?: $columnparts[4]);
                $this->table = $this->escape($columnparts[5] ?: $columnparts[6]);
            }
            elseif ($columnparts[3] ?: $columnparts[4]) {
                $this->schema = $this->escape($columnparts[1] ?: $columnparts[2]);
                $this->table = $this->escape($columnparts[3] ?: $columnparts[4]);
            }
            elseif ($columnparts[1] ?: $columnparts[2]) {
                $this->table = $this->escape($columnparts[1] ?: $columnparts[2]);
            } else {
                throw new Exception\QueryBuilder('Table Regex did not match', $table);
            }
            if ($columnparts[7] ?: $columnparts[8]) {
                $this->alias = $this->escape($columnparts[7] ?: $columnparts[8]);
            }
            return;
        }
        
        if (is_array($table)) {
            switch (count($table)) {
                case 1:
                    list($key) = array_keys($table);
                    if (!is_numeric($key)) {
                        $this->alias = $this->escape($table[$key]);
                    }
                    $this->table = $this->escape(is_numeric($key) ? $table[$key] : $key);
                    break;
                case 2:
                    list($key, $key2) = array_keys($table);
                    if (!is_numeric($key2)) {
                        $this->alias = $this->escape($table[$key2]);
                    }
                    $this->schema = $this->escape($table[$key]);
                    $this->table = $this->escape(is_numeric($key2) ? $table[$key2] : $key2);
                    break;
                case 3:
                    list($key, $key2, $key3) = array_keys($table);
                    if (!is_numeric($key3)) {
                        $this->alias = $this->escape($table[$key3]);
                    }
                    $this->database = $this->escape($table[$key]);
                    $this->schema = $this->escape($table[$key2]);
                    $this->table = $this->escape(is_numeric($key3) ? $table[$key3] : $key3);
                    break;
                default:
                    throw new Exception\QueryBuilder('Incorrect number of array elements for table name', $table);
            }
            return;
        }

        throw new Exception\QueryBuilder('Cannot resolve table name', $table);
    }
    
    public function escape(string $name) : string
    {
        if (!$name) {
            return '';
        }
        return '`' . str_replace('`', '``', $name) . '`'; 
    }
    
    public function render(QueryBuilder $query) : string
    {
        if ($this->database) {
            return $this->database . "." . $this->schema . "." . $this->table . ($this->alias ? " AS " . $this->alias : "");
        }
        if ($this->schema) {
            return $this->schema . "." . $this->table . ($this->alias ? " AS " . $this->alias : "");
        }
        return $this->table . ($this->alias ? " AS " . $this->alias : "");
    }
}
