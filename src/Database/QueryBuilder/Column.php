<?php
/**
 * Query Builder - build SQL queries programatically
 *
 * @package Automatorm\Database
 */

namespace Automatorm\Database\QueryBuilder;

use Automatorm\Database\SqlString;
use Automatorm\Database\QueryBuilder;
use Automatorm\Database\Interfaces\Renderable;
use Automatorm\Exception;

class Column implements Renderable
{
    protected $sqlstring;
    
    protected $database;
    protected $schema;
    protected $column;
    protected $alias;
    
    public function __construct($column)
    {
        // Cowardly refuse to process SqlStrings
        if ($column instanceof SqlString) {
            $this->column = (string) $column;
            return;
        }
        
        if (is_string($column)) {
            preg_match('/^
                (?:`(.+?)`|(\S+?))
                (?:\.(?:`(.+?)`|(\S+?)))?
                (?:\.(?:`(.+?)`|(\S+?)))?
                (?:\s+(?:[Aa][Ss]\s+)?(?:`(.+?)`|(\S+?)))?
                \s*$/x',
                $column, $columnparts
            );
            
            // Normalise output
            $columnparts += array_fill(0, 9, null);
            
            if ($columnparts[5] ?: $columnparts[6]) {
                $this->database = $this->escape($columnparts[1] ?: $columnparts[2]);
                $this->schema = $this->escape($columnparts[3] ?: $columnparts[4]);
                $this->column = $this->escape($columnparts[5] ?: $columnparts[6]);
            }
            elseif ($columnparts[3] ?: $columnparts[4]) {
                $this->schema = $this->escape($columnparts[1] ?: $columnparts[2]);
                $this->column = $this->escape($columnparts[3] ?: $columnparts[4]);
            }
            elseif ($columnparts[1] ?: $columnparts[2]) {
                $this->column = $this->escape($columnparts[1] ?: $columnparts[2]);
            } else {
                throw new Exception\QueryBuilder('Column Regex did not match', $column);
            }
            if ($columnparts[7] ?: $columnparts[8]) {
                $this->alias = $this->escape($columnparts[7] ?: $columnparts[8]);
            }
            
            // Special case *
            if ($this->column === '`*`') {
                $this->column = '*';
            }
            
            return;
        }
        
        if (is_array($column)) {
            switch (count($column)) {
                case 1:
                    [$key] = array_keys($column);
                    if (!is_numeric($key)) {
                        $this->alias = $this->escape($column[$key]);
                    }
                    $this->column = $this->escape(is_numeric($key) ? $column[$key] : $key);
                    break;
                case 2:
                    [$key, $key2] = array_keys($column);
                    if (!is_numeric($key2)) {
                        $this->alias = $this->escape($column[$key2]);
                    }
                    $this->schema = $this->escape($column[$key]);
                    $this->column = $this->escape(is_numeric($key2) ? $column[$key2] : $key2);
                    break;
                case 3:
                    [$key, $key2, $key3] = array_keys($column);
                    if (!is_numeric($key3)) {
                        $this->alias = $this->escape($column[$key3]);
                    }
                    $this->database = $this->escape($column[$key]);
                    $this->schema = $this->escape($column[$key2]);
                    $this->column = $this->escape(is_numeric($key3) ? $column[$key3] : $key3);
                    break;
                default:
                    throw new Exception\QueryBuilder('Incorrect number of array elements for column name', $table);
            }
            
            // Special case *
            if ($this->column === '`*`') {
                $this->column = '*';
            }
            
            return;
        }
        
        throw new Exception\QueryBuilder('Cannot resolve column name', $column);
    }

    public function escape(string $name) : string
    {
        if (!$name) {
            return '';
        }
        return '`' . str_replace('`', '``', $name) . '`'; 
    }
    
    public function getRenderedName() : string
    {
        if ($this->database) {
            return $this->database . "." . $this->schema . "." . $this->column;
        }
        if ($this->schema) {
            return $this->schema . "." . $this->column;
        }
        return $this->column;
    }
    
    public function render(QueryBuilder $query) : string
    {
        return $this->getRenderedName() . ($this->alias ? " AS " . $this->alias : "");
    }

    public function renderFunction(QueryBuilder $query, string $function) : string
    {
        return $function . '(' . $this->getRenderedName() . ")" . ($this->alias ? " AS " . $this->alias : "");
    }
}
