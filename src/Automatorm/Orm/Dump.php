<?php

namespace Automatorm\Orm;

class Dump
{
    static $url_prefix = null;
    
    public static function dump(Model $model)
    {
        $dump = new static();
        return $dump->_dump($model);
    }
    
    public function _dump(Model $model)
    {
        $data_access = function ($var) {
            return $this->$var;
        };
        
        $closure = function () use ($data_access){
            $data = $data_access->bindTo($this->_data, $this->_data);
            $schema = $data('model');
            $seen = [];
            
            $output = "<pre>";
            $output .= "<span><strong>".get_class()."</strong></span>\n";
            $output .= "  <span><strong>id</strong></span> => ".$this->id."\n";
            $output .= "  <span><strong>connection</strong></span> => ".$this->database."\n";
            $output .= "  <span><strong>table</strong></span> => ".$this->table."\n";
                        
            $output .= "  <span><strong>object_properties</strong></span> =>\n";
            foreach (get_object_vars($this) as $key => $value)
            {
                $output .= "    " . \Automatorm\Orm\Dump::format($key, $value, $seen);
                $seen[$key] = true;
            }
            
            $output .= "  <span><strong>dynamic_properties</strong></span> =>\n";
            foreach (get_class_methods($this) as $method)
            {
                if (substr($method, 0, 10) == '_property_')
                {
                    $key = substr($method, 10);
                    $value = $this->$method();
                    $output .= "    " . \Automatorm\Orm\Dump::format($key, $value, $seen);
                    $seen[$key] = true;
                }
            }

            $output .= "  <span><strong>data_properties</strong></span> =>\n";
            foreach ($data('data') as $key => $value)
            {
                $output .= "    " . \Automatorm\Orm\Dump::format($key, $value, $seen);
                $seen[$key] = true;
            }
            
            $output .= "  <span><strong>external_tables</strong></span> =>\n";
            $output .= "    <span><strong>1-1</strong></span> =>\n";
            if ($schema['one-to-one']) foreach ($schema['one-to-one'] as $key => $contents)
            {
                $value = $this->_data->$key;
                
                $output .= "      " . \Automatorm\Orm\Dump::format($key, $value, $seen);
                $seen[$key] = true;
            }

            $output .= "    <span><strong>*-1</strong></span> =>\n";
            if ($schema['many-to-one']) foreach ($schema['many-to-one'] as $key => $contents)
            {
                $value = $this->_data->$key;
                
                $output .= "      " . \Automatorm\Orm\Dump::format($key, $value, $seen);
                $seen[$key] = true;
            }

            $output .= "    <span><strong>1-*</strong></span> =>\n";
            if ($schema['one-to-many']) foreach ($schema['one-to-many'] as $key => $contents)
            {
                $value = $this->_data->$key;
                
                $output .= "      " . \Automatorm\Orm\Dump::format($key, $value, $seen);
                $seen[$key] = true;
            }

            $output .= "    <span><strong>*-*</strong></span> =>\n";
            if ($schema['many-to-many']) foreach ($schema['many-to-many'] as $key => $contents)
            {
                $value = $this->_data->$key;
                
                $output .= "      " . \Automatorm\Orm\Dump::format($key, $value, $seen);
                $seen[$key] = true;
            }
            
            $output .= "</pre>\n";
            
            return $output;
        };
        
        $c = $closure->bindTo($model, $model);
        return $c();    
    }
    
    public static function format($key, $value, $seen)
    {
        switch (true)
        {                
            case $value instanceof Model:
                $namespace = explode('\\', get_class($value));
                $class = array_pop($namespace);
                $table = $value::$tablename;

                $type = 'Model';
                $display1 = implode('\\', $namespace) . '\\';
                $display2 = $class;
                if (static::$url_prefix) {
                    $table = $value->_data->getTable();
                    $display3 = " <a href='".static::$url_prefix."/{$table}/{$value->id}'>".$value->id."</a>";
                } else {
                    $display3 = " ".$value->id;
                }
                
                if (method_exists($value, '__toString')) $display4 = ' (' . \Automatorm\Orm\Dump::safeTruncate($value) . ')';
                
            break;

            case $value instanceof Collection:
                $ids = [];
                foreach ($value as $obj)
                {
                    if ($obj instanceof Model && static::$url_prefix) {
                        $table = $obj->_data->getTable();
                        $ids[] = "<a href='".static::$url_prefix."/{$table}/{$obj->id}'>".$obj->id."</a>";
                    } else {
                        $ids[] = $obj->id;
                    }
                    
                }
                
                if ($ids)
                {
                    $namespace = explode('\\', get_class($obj));
                    $class = array_pop($namespace);                    
                    $display1 = implode('\\', $namespace) . '\\';
                    $display2 = $class;
                    $display3 = " [".implode(',', $ids)."]";
                    if (method_exists($obj, '__toString') && count($ids) < 30)
                    {                        
                        foreach ($value as $obj) $objstrings[] = \Automatorm\Orm\Dump::safeTruncate($obj);
                        $display4 = ' (' . implode(',', $objstrings) . ')';
                    }
                }
                else
                {
                    $display1 = 'empty';                    
                    $display3 = " []";
                }
                
                $type = 'Collection';
                
            break;
            
            case $value instanceof Time:
                $type = 'DateTime';
                $display3 = $value->format('Y-m-d H:i:s');
            break;
            
            case is_object($value):
                $namespace = explode('\\', get_class($value));
                $class = array_pop($namespace);
                
                $type = 'object';
                $display1 = implode('\\', $namespace) . '\\';
                $display2 = $class;
                if (method_exists($value, '__toString')) $display4 = ' (' . (string) $value . ')';
            break;
            
            case is_bool($value):
                $type = 'boolean';
                $display3 = $value?'true':'false';
            break;
            
            case is_null($value):
                $type = 'null';
                $display3 = "NULL";
            break;

            case is_string($value):
                $type = 'string';
                $display3 = '"' . $value .'"';
            break;
            
            default:
                $type = gettype($value);
                $display3 = $value;
            break;
        }
        
        $string = "";
        
        if (array_key_exists($key, $seen))
        {
            return
                "<del style='color: #999999;'><strong>$key</strong> => <small>$type</small> ".
                $display1.
                $display2.
                $display3.
                $display4.
                "</del>\n";   
        }
        else
        {
            return "<strong>$key</strong> => <small>$type</small> ".
            "<span style='color: #999999;'>$display1</span>".
            "<span style='color: #000077;'>$display2</span>".
            "<span style='color: #cc0000;'>$display3</span>".
            "<span style='color: #007700;'>$display4</span>".
            "\n";
        }
    }
    
    public static function safeTruncate($string, $length = 50)
    {
        if (strlen($string) > $length) return htmlspecialchars(substr($string,0,$length)) . '...';
        return htmlspecialchars($string);        
    }
}