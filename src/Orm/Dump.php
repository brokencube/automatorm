<?php

namespace Automatorm\Orm;

class Dump
{
    public static $url_prefix = null;
    public static $id_limit = 15;
    
    public static function dump($var)
    {
        // Use specialised function to dump Orm objects
        if (is_object($var)) {
            if ($var instanceof Model) {
                return "<pre>" . static::dumpModel($var) . "</pre>\n";
            }
            
            if ($var instanceof Collection) {
                return "<pre>" . static::dumpCollection($var) . "</pre>\n";
            }
        }
        
        // Fall back to var_dump for everything else
        ob_start();
        var_dump($var);
        return "<pre>" . ob_get_clean() . "</pre>\n";
    }
    
    public static function dumpCollection(Collection $collection)
    {
        if (is_object($collection->first()) and $collection->first() instanceof Model) {
            $output = "<span><strong>Collection of ".get_class($collection->first())." Models</strong></span>\n";
        } else {
            $output = "<span><strong>Collection object:</strong></span>\n";
        }
        
        foreach ($collection as $key => $value) {
            $output .= "  " . \Automatorm\Orm\Dump::format($key, $value);
        }
        return $output;
    }
    
    public static function dumpModel(Model $model)
    {
        $data_access = function ($var) {
            return $this->$var;
        };
        
        $closure = function () use ($data_access) {
            $data = $data_access->bindTo($this->_data, $this->_data);
            $schema = $data('model');
            $seen = [];
            
            $output = "<span><strong>".get_class()."</strong></span>\n";
            $output .= "  <span><strong>id</strong></span> => ".$this->id."\n";
            $output .= "  <span><strong>connection</strong></span> => ".$this->connection->name."\n";
            $output .= "  <span><strong>table</strong></span> => ".$this->table."\n";
                        
            $output .= "  <span><strong>object_properties</strong></span> =>\n";
            foreach (get_object_vars($this) as $key => $value) {
                $output .= "    " . \Automatorm\Orm\Dump::format($key, $value, $seen);
                $seen[$key] = true;
            }
            
            $output .= "  <span><strong>dynamic_properties</strong></span> =>\n";
            foreach (get_class_methods($this) as $method) {
                if (substr($method, 0, 10) == '_property_') {
                    $key = substr($method, 10);
                    $value = $this->$method();
                    $output .= "    " . \Automatorm\Orm\Dump::format($key, $value, $seen);
                    $seen[$key] = true;
                }
            }

            $output .= "  <span><strong>data_properties</strong></span> =>\n";
            foreach ($data('data') as $key => $value) {
                $output .= "    " . \Automatorm\Orm\Dump::format($key, $value, $seen);
                $seen[$key] = true;
            }
            
            $output .= "  <span><strong>external_tables</strong></span> =>\n";
            $output .= "    <span><strong>1-1</strong></span> =>\n";
            if ($schema['one-to-one']) {
                foreach ($schema['one-to-one'] as $key => $contents) {
                    $value = $this->_data->$key;
                
                    $output .= "      " . \Automatorm\Orm\Dump::format($key, $value, $seen);
                    $seen[$key] = true;
                }
            }

            $output .= "    <span><strong>*-1</strong></span> =>\n";
            if ($schema['many-to-one']) {
                foreach ($schema['many-to-one'] as $key => $contents) {
                    $value = $this->_data->$key;
                
                    $output .= "      " . \Automatorm\Orm\Dump::format($key, $value, $seen);
                    $seen[$key] = true;
                }
            }

            $output .= "    <span><strong>1-*</strong></span> =>\n";
            if ($schema['one-to-many']) {
                foreach ($schema['one-to-many'] as $key => $contents) {
                    $ids = $this->{$key}->id;
                    $value = $this->_data->join($key, ['id' => array_slice($ids, 0, \Automatorm\Orm\Dump::$id_limit)]);
                    
                    $output .= "      " . \Automatorm\Orm\Dump::format($key, $value, $seen, count($ids));
                    
                    $seen[$key] = true;
                }
            }

            $output .= "    <span><strong>*-*</strong></span> =>\n";
            if ($schema['many-to-many']) {
                foreach ($schema['many-to-many'] as $key => $contents) {
                    $ids = $this->{$key}->id;
                    $value = $this->_data->join($key, ['id' => array_slice($ids, 0, \Automatorm\Orm\Dump::$id_limit)]);
            
                    $output .= "      " . \Automatorm\Orm\Dump::format($key, $value, $seen, count($ids));
                
                    $seen[$key] = true;
                }
            }
            
            return $output;
        };
        
        $c = $closure->bindTo($model, $model);
        return $c();
    }
    
    public static function format($key, $value, $seen = [], $collectionCount = 0)
    {
        switch (true) {
            case $value instanceof Model:
                $namespace = explode('\\', get_class($value));
                $class = array_pop($namespace);
                $table = $value::$tablename;
                $type = 'Model';

                // Show table name if Model is not subclassed
                if (get_class($value) == 'Automatorm\\Orm\\Model') {
                    $display1 = 'tablename:';
                    $display2 = $table;
                } else {
                    $display1 = implode('\\', $namespace) . '\\';
                    $display2 = $class;
                }
                
                if (static::$url_prefix) {
                    $table = $value->_data->getTable();
                    $display3 = " <a href='".static::$url_prefix."/{$table}/{$value->id}'>".$value->id."</a>";
                } else {
                    $display3 = " ".$value->id;
                }
                
                if (method_exists($value, '__toString')) {
                    $display4 = ' (' . \Automatorm\Orm\Dump::safeTruncate($value) . ')';
                }
                break;

            case $value instanceof Collection:
                $ids = [];
                foreach ($value as $obj) {
                    if ($obj instanceof Model && static::$url_prefix) {
                        $table = $obj->_data->getTable();
                        $ids[] = "<a href='".static::$url_prefix."/{$table}/{$obj->id}'>".$obj->id."</a>";
                    } else {
                        $ids[] = $obj->id;
                    }
                }
                
                if ($ids) {
                    $namespace = explode('\\', get_class($obj));
                    $class = array_pop($namespace);
                    
                    if (get_class($obj) == 'Automatorm\\Orm\\Model') {
                        $display1 = 'tablename:';
                        $display2 = $obj->_data->getTable();
                    } else {
                        $display1 = implode('\\', $namespace) . '\\';
                        $display2 = $class;
                    }
                    
                    if ($collectionCount > \Automatorm\Orm\Dump::$id_limit) {
                        $display3 = " [".implode(',', $ids).",...]";
                        $display4 = " ({$collectionCount} results total)";
                    } else {
                        $display3 = " [".implode(',', $ids)."]";
                        if (method_exists($obj, '__toString')) {
                            foreach ($value as $obj) {
                                $objstrings[] = \Automatorm\Orm\Dump::safeTruncate($obj);
                            }
                            $display4 = implode(',', $objstrings);
                            $display4 = ' (' . $display4 . ')';
                        }
                    }
                } else {
                    $display1 = 'empty';
                    $display3 = " []";
                }
                
                $type = 'Collection';
                break;
            
            case $value instanceof DateTimeInterface:
                $type = 'DateTime';
                $display3 = $value->format('Y-m-d H:i:s');
                break;
            
            case is_object($value):
                $namespace = explode('\\', get_class($value));
                $class = array_pop($namespace);
                
                $type = 'object';
                $display1 = implode('\\', $namespace) . '\\';
                $display2 = $class;
                if (method_exists($value, '__toString')) {
                    $display4 = ' (' . (string) $value . ')';
                }
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
        
        if (array_key_exists($key, $seen)) {
            return
                "<del style='color: #999999;'><strong>$key</strong> => <small>$type</small> ".
                $display1.
                $display2.
                $display3.
                $display4.
                "</del>\n";
        } else {
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
        if (strlen($string) > $length) {
            return htmlspecialchars(substr($string, 0, $length)) . '...';
        }
        return htmlspecialchars($string);
    }
}
