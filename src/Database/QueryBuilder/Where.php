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

class Where implements Renderable
{
    protected $where = [];
    protected $conjunction;
    
    public function __construct($clauses = [], $conjunction = 'AND')
    {
        if ($clauses) {
            $this->addClauses($clauses);
        }
        $this->setConjunction($conjunction);
    }
    
    public function setConjunction($conjunction)
    {
        $conjunction = strtoupper($conjunction);
        
        switch ($conjunction) {
            case 'AND':
            case 'OR':
                $this->conjunction = $conjunction;
                return;
            
            default:
                throw new Exception('Unknown Conjuction Type: ' . $conjucntion);
        }
    }
    
    public function addClauses($clauses)
    {
        foreach ($clauses as $key => $value) {
            if (is_numeric($key) && $value instanceof Renderable) {
                $this->where[] = $value;
            } else {
                [$column, $affix] = Expression::extractAffix($key);
                $this->where[] = new Expression(new Column($column), $affix, $value);
            }
        }
    }

    public function addOnClauses($clauses)
    {
        foreach ($clauses as $key => $value) {
            if (is_numeric($key) && $value instanceof Renderable) {
                $this->where[] = $value;
            } else {
                [$column, $affix] = Expression::extractAffix($key);
                $this->where[] = new Expression(new Column($column), $affix, new Column($value));
            }
        }
    }
    
    public function render(QueryBuilder $query) : string
    {
        $output = '';
        if ($this->where) {
            $strings = [];
            foreach ($this->where as $clause) {
                $strings[] = $clause->render($query);
            }
            $clauses = implode(' '.$this->conjunction.' ', $strings);
            
            $output .= "(" . $clauses . ")";
        }
        
        return $output;
    }
    
    public function hasClauses() : bool
    {
        return (bool) count($this->where);
    }
}
