<?php
namespace Automatorm\Orm;

use HodgePodge\Common;
use HodgePodge\Core;
use HodgePodge\Core\Query;
use Automatorm\Exception;

class PartialResult
{
    protected $source;
    protected $sourceSchema;
    protected $currentSchema;
    protected $currentTable;
    protected $database;
    protected $route;
    protected $multiresult = false;
    protected $resolution = null;
    
    public function __construct(Model $source)
    {
        $this->source = $source;
        $this->schema = $source->_data->getSchema();
        $this->sourceSchema = $this->currentSchema = $source->_data->getModel();
        $this->currentTable = $this->currentSchema['table'];
        $this->database = $source->_data->getDatabase();
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
            return $this->resolve($var);
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
    
    public function resolve($var = null)
    {
        if (!$this->resolution)
        {
            // Resolve down to a real Model object, then call __get on it.
            $ids = $this->resolveState();
            
            $results = Model::factoryObjectCache($ids, $this->currentTable, $this->database);
            
            if ($this->multiresult && !$results instanceof Collection)
            {
                $results = new Collection([$results]);
            }
            
            if (!$this->multiresult && $results instanceof Collection && $results->count() == 1)
            {
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
    
    protected $joinCount = 0;

    public function resolveState()
    {
        $key = 'join_' . $this->joinCount;
        $select = 
            'Select distinct '.$key.'.id as id from `' . $this->sourceSchema['table_name'] . '` as join_0 ';
            
        $where =
            'Where join_0.id = ' . $this->source->id;
            
        array_unshift($this->route, $select);
        array_push($this->route, $where);
        
        $query = new Query($this->database);
        $query->sql(
            implode(' ', $this->route)
        );
        list($ids) = $query->execute();
        
        foreach($ids as $id)
        {
            $return[] = $id['id'];
        }
        
        return $return;
    }
    
    public function push12M($col, $target)
    {
        $key = 'join_' . $this->joinCount;
        $key2 = 'join_' . ++$this->joinCount;
        
        $this->route[] =
            'Join `' . Schema::underscoreCase($target['table']) . '` as ' . $key2 . ' on ' . $key . '.id = ' . $key2 . '.`' . $target['column_name'] . '` ';
        ;
        
        $this->next($target['table']);
        $this->multiresult = true;
        
        return $this;
    }

    public function pushM21($col, $target)
    {
        $key = 'join_' . $this->joinCount;
        $key2 = 'join_' . ++$this->joinCount;
        
        $this->route[] =
            'Join `' . Schema::underscoreCase($target) . '` as ' . $key2 . ' on ' . $key2 . '.id = ' . $key . '.`' . $col . '_id` ';
        ;
        
        $this->next($target);
        
        return $this;
    }
    
    public function pushM2M($col, $target)
    {
        $key = 'join_' . $this->joinCount;
        $key2 = 'join_' . ++$this->joinCount;
        $key2a = $key2 . 'a';
        
        $this->route[] =
            'Join `' . Schema::underscoreCase($target['pivot']) . '` as ' . $key2a . ' on ' . $key . '.id = ' . $key2a . '.`' . $target['id'] . '` ';
        ;
        $this->route[] =
            'Join `' . Schema::underscoreCase($target['connections'][0]['table']) . '` as ' . $key2 . ' on ' . $key2 . '.id = ' . $key2a . '.`' . $target['connections'][0]['column'] . '` ';
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
