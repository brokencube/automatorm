<?php
namespace Automatorm\Orm;

class Collection implements \ArrayAccess, \Iterator, \Countable, \JsonSerializable
{
    // From Hodgepodge
    protected $container = [];

    //////// Interface Methods ///////
    public function jsonSerialize()
    {
        return $this->container;
    }
    
    public function offsetSet($offset, $value)
    {
        if (is_null($offset)) {
            $this->container[] = $value;
        } else {
            $this->container[$offset] = $value;
        }
    }

    public function offsetExists($offset)
    {
        return isset($this->container[$offset]);
    }

    public function offsetUnset($offset)
    {
        unset($this->container[$offset]);
    }

    public function offsetGet($offset)
    {
        return isset($this->container[$offset]) ? $this->container[$offset] : null;
    }
    
    public function rewind()
    {
        reset($this->container);
    }
    
    public function current()
    {
        return current($this->container);
    }

    public function key()
    {
        return key($this->container);
    }

    public function next()
    {
        return next($this->container);
    }
    
    public function valid()
    {
        return current($this->container) !== false;
    }
    
    public function count()
    {
        return count($this->container);
    }
    
    public function first()
    {
        return array_slice($this->container, 0, 1)[0];
    }

    public function last()
    {
        return array_slice($this->container, count($this->container) - 1, 1)[0];
    }
    
    ////////////////////
    
    public function __get($parameter)
    {
        $list = array();
        
        if ($this->container[0] instanceof Model and $this->container[0]->_data->externalKeyExists($parameter)) {
            return Data::groupJoin($this, $parameter);
        }
        
        foreach($this->container as $item) {
            $value = $item->$parameter;
            if ($value instanceof Collection) {
                $list = array_merge($list, $value->toArray());
            } else {
                $list[] = $value;
            }
        }
        
        return new static($list);
    }

    public function __call($name, $args)
    {
        // Foreign keys
        if (
            $this->container[0] instanceof Model
            and !method_exists($this->container[0], $name)
            and $this->container[0]->_data->externalKeyExists($name)
        ) {
            if (is_numeric($args[1]) && ($args[1] & Model::COUNT_ONLY))
                return Data::groupJoinCount($this, $name, $args[0]);
            return Data::groupJoin($this, $name, $args[0]);
        }

        // Otherwise...
        $list = [];
        foreach($this->container as $item) {
            $value = call_user_func_array([$item, $name], $args);
            if ($value instanceof Collection) {
                $list = array_merge($list, $value->toArray());
            } else {
                $list[] = $value;
            }
        }
        
        return new static($list);
    }

    public function __construct($array = null)
    {
        if (is_null($array)) $array = array();
        if ($array instanceof Collection) $array = $array->toArray();
        if (!is_array($array)) throw new \InvalidArgumentException('Orm\Collection::__construct() expects an array - ' . gettype($array) . ' given');
        
        $this->container = $array;
    }
    
    public function toArray($value = null, $key = 'id')
    {
        $return = array();
        
        // Empty array?
        if (!count($this->container)) return array();
        
        // If we are dealing with a collection of Model objects then user key/value to extract desired property
        if ($this->container[0] instanceof Model) {
            if(!$value) {
                foreach($this->container as $item) {
                    if ($key) {
                        $return[$item->$key] = $item;    
                    } else {
                        $return[] = $item;    
                    }
                }
                return $return;            
            }
            else
            {
                foreach($this->container as $item) {
                    if ($key) {
                        $return[$item->$key] = $item->$value;    
                    } else {
                        $return[] = $item->$value;  
                    }
                }
                return $return;
            }
        }
        
        // If we have normal objects or primatives, just return the internal container
        return $this->container;
    }
    
    //////// Collection modifiers ////////
    public function unique()
    {
        $copy = $this->container;
        $clobberlist = [];
        
        foreach($copy as $key => $obj) {
            if (in_array($obj->id, $clobberlist)) {
                unset($copy[$key]);
            } else {
                $clobberlist[] = $obj->id;
            }
        }
        
        return new static($copy);
    }
    
    public function sort($function)
    {
        $copy = $this->container;
        uasort($copy, $function);
        return new static($copy);
    }
    
    public function natSort($key = null)
    {
        if (!$key) {
            return $this->sort(function ($a, $b) {
                return strnatcasecmp((string) $a, (string) $b);
            });
        } else {
            return $this->sort(function ($a, $b) use ($key) {
                return strnatcasecmp($a->{$key}, $b->{$key});
            });            
        }
    }
    
    public function slice($start, $length = null)
    {
        return new static(array_slice($this->container, $start, $length));
    }
    
    public function reverse()
    {
        return new static(array_reverse($this->container));
    }
    
    // Merge another array into this collection
    public function add()
    {
        $args = func_get_args();
        $merge = [$this->container];
        
        $count = 1;
        foreach($args as $array)
        {
            if ($array instanceof Collection) $array = $array->container;
            if (!is_array($array)) throw new \InvalidArgumentException("Orm\Collection->add() expects argument {$count} to be an array");
            $merge[] = $array;
            $count++;
        }
        
        $copy = call_user_func_array('array_merge', $merge);
        
        return new static($copy);
    }

    public function merge($array)
    {
        return $this->add($array);
    }
    
    // Remove any items in this collection that match filter
    public function not($filter)
    {
        return $this->filter($filter, true);
    }

    public function remove($filter)
    {
        return $this->filter($filter, true);
    }

    // Only keep items that match filter
    public function filter($filter, $invert_prefix = false)
    {
        $copy = $this->container;
        
        if (is_array($filter)) {        
            // Loop over items
            foreach ($copy as $item_key => $item) {
                // Loop over filters
                foreach ($filter as $property => $value_list) {
                    // Look for special non-alphanumeric prefixes
                    preg_match('/^([^a-zA-Z0-9]*)[a-zA-Z0-9]/', $property, $prefix);
                    $prefix = $prefix[1];
                    // Strip any prefix off the front of the property name
                    $property = substr($property, strlen($prefix));
                    
                    // Invert prefix for not()
                    if ($invert_prefix) switch ($prefix) {
                        case '=': case '==': default:   $prefix = '!';  break;
                        case '!=': case '!': case '<>': $prefix = '=';  break;
                        case '<':                       $prefix = '>='; break;
                        case '<=':                      $prefix = '>';  break;
                        case '>':                       $prefix = '<='; break;
                        case '>=':                      $prefix = '<';  break;
                    }
                    
                    // Each filter can have several acceptable values -- force single item to array
                    if (!is_array($value_list)) $value_list = array($value_list);
                    // Check each value - if we find a matching value than skip to the next filter.
                    foreach ($value_list as $value) {
                        // Compare based on prefix (else == )
                        switch ($prefix) {
                            
                            case '=': case '==': default:
                                if ($item->$property == $value) {
                                    // Found match - move on to next filter
                                    continue 3; // Back to foreach $filter
                                }
                            break;

                            case '!=': case '!': case '<>':
                                if ($item->$property == $value) {
                                    // Found a negative match, remove this item and move on to next property
                                    unset($copy[$item_key]);
                                    continue 4; // Back to foreach $copy
                                }
                            break;

                            case '>':
                                if ($item->$property <= $value) {
                                    // Found a negative match, remove this item and move on to next property
                                    unset($copy[$item_key]);
                                    continue 4; // Back to foreach $copy
                                }
                            break;

                            case '>=':
                                if ($item->$property < $value) {
                                    // Found a negative match, remove this item and move on to next property
                                    unset($copy[$item_key]);
                                    continue 4; // Back to foreach $copy
                                }
                            break;

                            case '<':
                                if ($item->$property >= $value) {
                                    // Found a negative match, remove this item and move on to next property
                                    unset($copy[$item_key]);
                                    continue 4; // Back to foreach $copy
                                }
                            break;

                            case '<=':
                                if ($item->$property > $value) {
                                    // Found a negative match, remove this item and move on to next property
                                    unset($copy[$item_key]);
                                    continue 4; // Back to foreach $copy
                                }
                            break;
                        }
                    }
                    
                    switch ($prefix) {
                        // Negative cases
                        case '=': case '==': default:
                            // Failed to break loop, so the current value matches none of the
                            // values for the current filter, therefore remove the item
                            unset($copy[$item_key]);
                            continue 3; // Back to foreach $copy
                        
                        // Positive cases
                        case '<': case '<=': case '>': case '>=': case '!=': case '!': case '<>':
                            // Failed to break oop, so the current value passes.
                            // No action, keep this key, continue to next filter
                            continue 2; // Back to foreach $filter
                    }
                }
            }
        } elseif(is_callable($filter)) {
            // Loop over items
            foreach ($copy as $item_key => $item) {
                // Use the closure/callback to filter the item
                if (!$filter($item)) {
                    unset($copy[$item_key]);
                }
            }            
        } else {
            throw new \InvalidArgumentException('Orm\Collection->filter() expects an array or callable');
        }
        
        $copy = array_values($copy);
        
        return new static($copy);
    }
}
