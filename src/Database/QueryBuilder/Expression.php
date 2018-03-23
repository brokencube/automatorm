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

class Expression implements Renderable
{
    const PLACEHOLDER = '?';
    
    protected $column;
    protected $comparitor;
    protected $value;
    protected $placeholder;
    protected $count = false;
    
    public function __construct(Column $column, $affix, $value)
    {
        $this->column = $column;
        $this->comparitor = '=';
        $this->value = $value;
        $this->placeholder = static::PLACEHOLDER;
        
        // Special case for # "count" clause
        if (strpos($affix, '#') !== false) {
            // Excise the # from the affix
            $affix = substr($affix, 0, strpos($affix, '#')) . substr($affix, strpos($affix, '#') + 1);
            $this->count = true;
        }

        // Set comparitor
        switch ($affix) {
            case '=':
            case '!=':
            case '>':
            case '<':
            case '>=':
            case '<=':
                $this->comparitor = $affix;
                break;
            case '!':
                $this->comparitor = '!=';
                break;
            case '%':
                $this->comparitor = 'like';
                break;
            case '!%':
            case '%!':
                $this->comparitor = 'not like';
                break;
        }
        
        // Special cases for null values in expressions
        if (is_null($value)) {
            if ($affix == '!') {
                $this->comparitor = 'is not null';
            } else {
               $this->comparitor = 'is null'; 
            }
        }
        
        // Special case for in clauses
        if (is_array($value)) {
            // Special case for empty in clauses
            if (!count($value)) {
                $this->column = null;
                $this->comparitor = $affix === '!' ? "true" : "false";
            } else {
                $this->comparitor = $affix === '!' ? "not in" : "in";
                $this->placeholder = '(' . implode(',', array_fill(0, count($value), '?')) . ')';
            }
        }
        
        if ($value instanceof QueryBuilder) {
            // MySQL doesn't support in with limits - so special case limit 1 to use =
            // Of course, MySQL is SOL if limit > 1
            if ($value->limit === 1) {
                $this->comparitor = $affix === '!' ? "<>" : "=";
            } else {
                $this->comparitor = $affix === '!' ? "not in" : "in";
            }
        }
        
    }
    
    public function render(QueryBuilder $query) : string
    {
        // Special case for tautological or contractdictory expressions (true / false)
        if (!$this->column) {
            return $this->comparitor;
        }
        
        if ($this->count) {
            $column = $this->column->renderFunction($query, 'count');
        } else {
            $column = $this->column->render($query);
        }
        
        if ($this->value instanceof Renderable) {
            return $column . " " . $this->comparitor . " " . $this->value->render($query);
        }
        
        if (isset($this->value)) {
            if (is_array($this->value)) {
                $query->addData($this->value);
            } else {
                $query->addData([$this->value]);
            }
            return $column . " " . $this->comparitor . " " . $this->placeholder;
        }
        
        // Special case for null values - use "is null" / "is not null"
        return $column . $this->comparitor;
    }
    
    public static function extractAffix(string $column) : array
    {
        preg_match('/^([!=<>%#]*)([^!=<>%#]+)([!=<>%#]*)$/', $column, $parts);
        $affix = (string) $parts[1] . (string) $parts[3];
        $column = $parts[2];
        
        return [$column, $affix];
    }
}
