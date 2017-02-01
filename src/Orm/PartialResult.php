<?php
namespace Automatorm\Orm;

use Automatorm\Exception;
use Automatorm\Database\Query;
use Automatorm\Database\QueryBuilder;

class PartialResult
{
    protected $source;
    protected $schema;
    protected $sourceSchema;
    protected $currentSchema;
    protected $currentTable;
    protected $database;
    protected $route;
    protected $multiresult = false;
    protected $resolution = null;

    protected $query;
    protected $tableCount;
    
    public function __construct(Model $source)
    {
        $this->source = $source;
        $this->schema = $source->_data->getSchema();
        $this->sourceSchema = $this->currentSchema = $source->_data->getModel();
        $this->currentTable = $this->sourceSchema['table_name'];
        $this->database = $source->_data->getDatabase();
        
        $this->query = QueryBuilder::select([$this->currentTable => 't1']);
        $this->query->where(['t1.id' => $source->id]);
        $this->tableCount = 1;
    }
    
    public function __call($var, $args)
    {
        $obj = $this->resolve();
        
        return call_user_func_array(array($obj, $var), $args);
    }
    
    public function __get($var)
    {
        if ($this->resolution) return $this->resolution->{$var};
        if ($var == '_') return $this->resolve();
        
        if (array_key_exists($var, $this->currentSchema['columns']))
        {
            // We have column data, resolve!
            return $this->resolve($var, true);
        }
        
        if (array_key_exists($var, $this->currentSchema['one-to-many']))
        {
            return $this->push12M($var, $this->currentSchema['one-to-many'][$var]);
        }

        if (array_key_exists($var, $this->currentSchema['many-to-one']))
        {
            return $this->pushM21($var, $this->currentSchema['many-to-one'][$var]);
        }
        
        if (array_key_exists($var, $this->currentSchema['many-to-many']))
        {
            return $this->pushM2M($var, $this->currentSchema['many-to-many'][$var]);
        }
        
        return $this->resolve($var);
    }
    
    public function resolve($var = null, $column = false)
    {
        // If we are explicitly looking for column data, just return the result
        if (!$this->resolution && $column) {
            return $this->resolveState($var);
        }
        
        // If we are not looking for column data, return the list of 'id's, and use that to get a list of Models
        if (!$this->resolution)
        {
            // Resolve down to a real Model object, then call __get on it.
            $ids = $this->resolveState('id');
            
            $results = Model::factoryObjectCache($ids, $this->currentTable, $this->database);
            
            // If we should be returning a group of results, but have a single object, wrap in Collection
            if ($this->multiresult && !$results instanceof Collection) {
                $results = new Collection([$results]);
            }
            
            // Visa-versa, if we have a Collection containing 1 object, and were expecting a single object, unwrap the Collection.
            if (!$this->multiresult && $results instanceof Collection && $results->count() == 1) {
                $results = $results[0];
            }
            
            $this->resolution = $results;
        }
        
        if (!is_null($var))
        {
            return $this->resolution->{$var};    
        }
        
        return $this->resolution;
    }
    
    public function resolveState($column = 'id')
    {
        $columns = [
            't' . $this->tableCount . '.id',
            't' . $this->tableCount . '.' . $column
        ];
        
        $this->query
            ->columns($columns)
            ->groupBy('t' . $this->tableCount . '.id')
        ;
        
        $query = new Query($this->database);
        $query->sql($this->query);
        list($rows) = $query->execute();
        
        foreach($rows as $row) $return[] = $row[$column];
        return $return;
    }
    
    public function push12M($col, $target)
    {
        $t1 = Schema::underscoreCase($this->currentTable);
        $t2 = Schema::underscoreCase($target['table']);
        $t1a = 't' . $this->tableCount;
        $t2a = 't' . ++$this->tableCount;
        
        $this->query
            ->join([$t2 => $t2a])
            ->joinOn(["{$t1a}.id" => "{$t2a}." . $target['column_name']])
        ;
        
        $this->next($target['table']);
        $this->multiresult = true;
        
        return $this;
    }

    public function pushM21($col, $target)
    {
        $t1 = Schema::underscoreCase($this->currentTable);
        $t2 = Schema::underscoreCase($target);
        $t1a = 't' . $this->tableCount;
        $t2a = 't' . ++$this->tableCount;
        
        $this->query
            ->join([$t2 => $t2a])
            ->joinOn(["{$t2a}.id" => "{$t1a}." . $col . "_id"])
        ;
        
        $this->next($target);
        
        return $this;
    }
    
    public function pushM2M($col, $target)
    {
        $t1 = Schema::underscoreCase($this->currentTable);
        $t2 = Schema::underscoreCase($target['pivot']);
        $t3 = Schema::underscoreCase($target['connections'][0]['table']);
        $t1a = 't' . $this->tableCount;
        $t2a = 't' . ++$this->tableCount;
        $t3a = 't' . ++$this->tableCount;

        $this->query
            ->join([$t2 => $t2a])
            ->joinOn(["{$t1a}.id" => "{$t2a}." . $target['id']])
            ->join([$t3 => $t3a])
            ->joinOn(["{$t2a}." . $target['connections'][0]['column'] => "{$t3a}.id"])
        ;

        $this->next(Schema::underscoreCase($target['connections'][0]['table']));
        $this->multiresult = true;

        return $this;
    }
    
    public function next($tablename)
    {
        $this->currentSchema = $this->schema->getTable($tablename);
        $this->currentTable = $tablename;
    }
}
