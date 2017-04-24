<?php
namespace Automatorm\Orm;

use Automatorm\Exception;

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
    
    /**
     * Return number of items in array
     * @return int Number of items in array
     */
    
    public function count()
    {
        return count($this->container);
    }
    
    /**
     * Return first item specified in array (regardless of key) or null if empty array
     * @return mixed First item or null
     */
    public function first()
    {
        if (!count($this->container)) {
            return null;
        }
        return array_slice($this->container, 0, 1)[0];
    }

    /**
     * Return last item specified in array (regardless of key) or null if empty array
     * @return mixed Last item or null
     */
    public function last()
    {
        if (!count($this->container)) {
            return null;
        }
        return array_slice($this->container, count($this->container) - 1, 1)[0];
    }
    
    ////////////////////
    
    public function __get($parameter)
    {
        $list = array();
        
        if ($this->container[0] instanceof Model and $this->container[0]->_data->externalKeyExists($parameter)) {
            return Data::groupJoin($this, $parameter);
        }
        
        foreach ($this->container as $item) {
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
        // If we use Model::COUNT_ONLY on empty container, return 0
        if (count($this->container) == 0 && is_numeric($args[1]) && ($args[1] & Model::COUNT_ONLY)) {
            return 0;
        }
        
        // Foreign keys
        if ($this->container[0] instanceof Model
            and !method_exists($this->container[0], $name)
            and $this->container[0]->_data->externalKeyExists($name)
        ) {
            if (is_numeric($args[1]) && ($args[1] & Model::COUNT_ONLY)) {
                return Data::groupJoinCount($this, $name, $args[0]);
            }
            return Data::groupJoin($this, $name, $args[0]);
        }
        
        // Otherwise...
        $list = [];
        foreach ($this->container as $item) {
            $value = call_user_func_array([$item, $name], $args);
            if ($value instanceof Collection) {
                $list = array_merge($list, $value->toArray());
            } else {
                $list[] = $value;
            }
        }
        
        // Return new list or count depending on options passed
        if (is_numeric($args[1]) && ($args[1] & Model::COUNT_ONLY)) {
            return count($list);
        } else {
            return new static($list);
        }
    }
    
    public function __set($name, $arg)
    {
        throw new Exception\Collection('Cannot directly set properties on a collection', ['name' => $name, 'value' => $arg]);
    }

    public function __construct($array = null)
    {
        if (is_null($array)) {
            $array = array();
        }
        if ($array instanceof Collection) {
            $array = $array->toArray();
        }
        if (!is_array($array)) {
            throw new \InvalidArgumentException('Orm\Collection::__construct() expects an array - ' . gettype($array) . ' given');
        }
        
        $this->container = $array;
    }
    
    
    /**
     * Return a plain PHP array version of the internal container
     * Additional options for the normal case of a Collection of Model objects
     *
     * @param $value For Collections of Models, return the specified property name instead of the Model object as the "Value"
     * @param $key For Collections of Models, return the specified property name as the "Key"
     * @return array Plain Array version of collection
     */
    public function toArray($value = null, $key = 'id')
    {
        // Empty array?
        if (!count($this->container)) {
            return [];
        }
        
        // If we are not dealing with a collection of Model objects, just return the internal container
        if (!$this->container[0] instanceof Model) {
            return $this->container;
        }
        
        // If we are dealing with a collection of Model objects then user key/value to extract desired property
        $return = [];
        if ($this->container[0] instanceof Model) {
            if (!$value) {
                foreach ($this->container as $item) {
                    if ($key) {
                        $return[$item->$key] = $item;
                    } else {
                        $return[] = $item;
                    }
                }
                return $return;
            } else {
                foreach ($this->container as $item) {
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
    /**
     * Return a new Collection containing one copy of each unique value in the original Container
     *
     * @return self New Collection containing only the unique values available in the original container
     */
    public function unique()
    {
        $copy = $this->container;
        $clobberlist = [];
        $modelclobberlist = [];
        
        foreach ($copy as $key => $obj) {
            if ($obj instanceof Model) {
                if (in_array($obj->id, $modelclobberlist)) {
                    unset($copy[$key]);
                } else {
                    $modelclobberlist[] = $obj->id;
                }
            } else {
                if (in_array($obj, $clobberlist)) {
                    unset($copy[$key]);
                } else {
                    $clobberlist[] = $obj;
                }
            }
        }
        
        return new static($copy);
    }
    
    /**
     * Return a new sorted Collection using provided sort function (through uasort())
     *
     * @param callable $function Callable to use to sort the array
     * @return self New Collection containing the sorted items
     */
    public function sort(callable $function)
    {
        $copy = $this->container;
        uasort($copy, $function);
        return new static($copy);
    }
    
    /**
     * Return a new sorted Collection using the already ordered list of Ids
     * Only works for collections containing Model objects
     *
     * @param callable $function Callable to use to sort the array
     * @return self New Collection containing the sorted items
     */
    public function sortById(array $listOfIds)
    {
        if (!$this->first() instanceof Model) {
            throw new Exception\BaseException('sortById can only be called on collections of Model objects');
        }
        $order = array_values($listOfIds);
        
        return $this->sort(function ($a, $b) use ($order) {
            return array_search($a->id, $order) - array_search($b->id, $order);
        });
    }

    /**
     * Return a new sorted Collection using provided sort function (through uasort() + strnatcasecmp())
     *
     * @param callable $key Specify the property on the Collection items to sort by -
     *                      otherwise, objects will be sorted by their toString representation
     * @return self New Collection containing the sorted items
     */
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
        foreach ($args as $array) {
            if ($array instanceof Collection) {
                $array = $array->container;
            }
            if (!is_array($array)) {
                throw new \InvalidArgumentException("Orm\Collection->add() expects argument {$count} to be an array");
            }
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
    
    public function map(callable $function)
    {
        return new static(array_map($function, $this->container));
    }
    
    public function extractAffix($propertyName, $invert = false)
    {
        $parts = [];
        // Look for special non-alphanumeric affixes
        preg_match('/^([!=<>%#]*)([^!=<>%#]+)([!=<>%#]*)$/', $propertyName, $parts);
        $affix = $parts[1] ?: $parts[3];
        // Strip any affix from the property name
        $property = $parts[2];
        
        // Invert affix for not()
        if ($invert) {
            switch ($affix) {
                case '=':
                case '==':
                default:
                    $affix = '!';
                    break;
                case '!=':
                case '!':
                case '<>':
                    $affix = '=';
                    break;
                case '<':
                    $affix = '>=';
                    break;
                case '<=':
                    $affix = '>';
                    break;
                case '>':
                    $affix = '<=';
                    break;
                case '>=':
                    $affix = '<';
                    break;
            }
        }
        
        // These affixes only work in SQL land
        switch ($affix) {
            case '%':
            case '!%':
            case '%!':
            case '#':
                throw new Exception\BaseException('UNSUPPORTED_AFFIX_TYPE');
        }
        
        return [$affix, $property];
    }

    // Only keep items that match filter
    public function filter($filter, $invertAffix = false)
    {
        $copy = $this->container;
        
        if (is_array($filter)) {
            // Loop over items
            foreach ($copy as $itemKey => $item) {
                // Loop over filters
                foreach ($filter as $property => $valueList) {
                    list($affix, $property) = $this->extractAffix($property, $invertAffix);
                    
                    // Each filter can have several acceptable values -- force single item to array
                    if (!is_array($valueList)) {
                        $valueList = array($valueList);
                    }
                    
                    // Check each value - if we find a matching value than skip to the next filter.
                    foreach ($valueList as $value) {
                        // Compare based on affix (else == )
                        switch ($affix) {
                            case '=':
                            case '==':
                            default:
                                if ($item->$property == $value) {
                                    // Found match - move on to next filter
                                    continue 3; // Back to foreach $filter
                                }
                                break;

                            case '!=':
                            case '!':
                            case '<>':
                                if ($item->$property == $value) {
                                    // Found a negative match, remove this item and move on to next property
                                    unset($copy[$itemKey]);
                                    continue 4; // Back to foreach $copy
                                }
                                break;

                            case '>':
                                if ($item->$property <= $value) {
                                    // Found a negative match, remove this item and move on to next property
                                    unset($copy[$itemKey]);
                                    continue 4; // Back to foreach $copy
                                }
                                break;

                            case '>=':
                                if ($item->$property < $value) {
                                    // Found a negative match, remove this item and move on to next property
                                    unset($copy[$itemKey]);
                                    continue 4; // Back to foreach $copy
                                }
                                break;

                            case '<':
                                if ($item->$property >= $value) {
                                    // Found a negative match, remove this item and move on to next property
                                    unset($copy[$itemKey]);
                                    continue 4; // Back to foreach $copy
                                }
                                break;

                            case '<=':
                                if ($item->$property > $value) {
                                    // Found a negative match, remove this item and move on to next property
                                    unset($copy[$itemKey]);
                                    continue 4; // Back to foreach $copy
                                }
                                break;
                        }
                    }
                    
                    switch ($affix) {
                        // Negative cases
                        case '=':
                        case '==':
                        default:
                            // Failed to break loop, so the current value matches none of the
                            // values for the current filter, therefore remove the item
                            unset($copy[$itemKey]);
                            continue 3; // Back to foreach $copy
                        
                        // Positive cases
                        case '<':
                        case '<=':
                        case '>':
                        case '>=':
                        case '!=':
                        case '!':
                        case '<>':
                            // Failed to break oop, so the current value passes.
                            // No action, keep this key, continue to next filter
                            continue 2; // Back to foreach $filter
                    }
                }
            }
        } elseif (is_callable($filter)) {
            // Loop over items
            foreach ($copy as $itemKey => $item) {
                // Use the closure/callback to filter the item
                if (!$filter($item)) {
                    unset($copy[$itemKey]);
                }
            }
        } else {
            throw new \InvalidArgumentException('Orm\Collection->filter() expects an array or callable');
        }
        
        $copy = array_values($copy);
        
        return new static($copy);
    }
}
