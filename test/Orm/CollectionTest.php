<?php

namespace Automatorm\UnitTest\Orm;

use Automatorm\Orm\Collection;

class CollectionTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @dataProvider providerTestArray
     */
    public function testArray($array)
    {
        $collection = new Collection($array);
        $array2 = $collection->toArray();
        $this->assertEquals($array, $array2);
    }
    
    public function providerTestArray()
    {
        return [
            [ [0,0,0] ],
            [ [1] ],
            [ [] ],
            [ ['hello', 'world', 1] ],
            [ [[[1]]] ]
        ];
    }

    public function testCount()
    {
        $collection = new Collection([1,2,3]);
        $this->assertEquals(3, $collection->count());
    }
    
    /**
     * @dataProvider providerTestFirst
     */
    public function testFirst($array, $result)
    {
        $collection = new Collection($array);
        $this->assertEquals($result, $collection->first());
    }

    public function providerTestFirst()
    {
        return [
            [ [1,2,3], 1 ],
            [ [1,2,3,2,1], 1 ],
            [ ['a','b','b'], 'a' ],
        ];
    }

    /**
     * @dataProvider providerTestLast
     */
    public function testLast($array, $result)
    {
        $collection = new Collection($array);
        $this->assertEquals($result, $collection->last());
    }

    public function providerTestLast()
    {
        return [
            [ [1,2,3], 3 ],
            [ [1,2,3,2,1], 1 ],
            [ ['a','b','b'], 'b' ],
        ];
    }
    
    /**
     * @dataProvider providerTestUnique
     */
    public function testUnique($array, $result)
    {
        $collection = new Collection($array);
        $this->assertInstanceOf(Collection::class, $collection->unique());
        $this->assertNotSame($collection, $collection->unique());
        $this->assertEquals($result, $collection->unique()->toArray());
    }

    public function providerTestUnique()
    {
        return [
            [ [1,2,3], [1,2,3] ],
            [ [1,2,3,2,1], [1,2,3] ],
            [ ['a','b','b'], ['a','b'] ],
        ];
    }

    /**
     * @dataProvider providerTestReverse
     */
    public function testReverse($array, $result)
    {
        $collection = new Collection($array);
        $this->assertInstanceOf(Collection::class, $collection->reverse());
        $this->assertNotSame($collection, $collection->reverse());
        $this->assertEquals($result, $collection->reverse()->toArray());
    }
    
    public function providerTestReverse()
    {
        return [
            [ [1,2,3], [3,2,1] ],
            [ [1,2,3,2,1], [1,2,3,2,1] ],
            [ ['a','b',null], [null,'b','a'] ],
        ];
    }
    
    /**
     * @dataProvider providerTestSlice
     */
    public function testSlice($array, $a, $b, $result)
    {
        $collection = new Collection($array);
        $this->assertInstanceOf(Collection::class, $collection->slice($a, $b));
        $this->assertEquals($result, $collection->slice($a, $b)->toArray());
    }
    
    public function providerTestSlice()
    {
        return [
            [ [0,1,2], 0, 0, [] ],
            [ [0,1,2], 0, 3, [0,1,2] ],
            [ [0,1,2], 0, 2, [0,1] ],
            [ [0,1,2], 1, 1, [1] ],
            [ [0,1,2], 1, 0, [] ],
            [ [0,1,2], 3, 3, [] ],
            [ [0,1,2], 2, 3, [2] ],
            [ [0,1,2], 0, -1, [0,1] ],
        ];
    }

    /**
     * @dataProvider providerTestFilter
     */
    public function testFilter($array, $filter, $result)
    {
        $collection = new Collection($array);
        $this->assertInstanceOf(Collection::class, $collection->filter($filter));
        $this->assertEquals($result, $collection->filter($filter)->toArray());
    }
    
    public function providerTestFilter()
    {
        return [
            [ [(object) ['id' => 1]], ['id' => 1], [(object) ['id' => 1]] ],
            [ [(object) ['id' => 1]], ['id' => 2], [] ],
            [ [(object) ['id' => 1]], ['!id' => 2], [(object) ['id' => 1]] ],
            [ [(object) ['id' => 1]], ['!id' => 1], [] ],
            [ [(object) ['id' => 2]], ['id>' => 1], [(object) ['id' => 2]] ],
            [ [(object) ['id' => 2]], ['id>' => 2], [] ],
            [ [(object) ['id' => 1]], ['id<' => 2], [(object) ['id' => 1]] ],
            [ [(object) ['id' => 1], (object) ['id' => 2], (object) ['id' => 3], (object) ['id' => 4]], ['id>' => 2], [(object) ['id' => 3], (object) ['id' => 4]] ],
            [ [(object) ['id' => 1], (object) ['id' => 2], (object) ['id' => 3], (object) ['id' => 4]], ['id>=' => 3], [(object) ['id' => 3], (object) ['id' => 4]] ],
            [ [(object) ['id' => 1], (object) ['id' => 2], (object) ['id' => 3], (object) ['id' => 4]], ['id<' => 2], [(object) ['id' => 1]] ],
            [ [(object) ['id' => 1], (object) ['id' => 2], (object) ['id' => 3], (object) ['id' => 4]], ['id<=' => 3], [(object) ['id' => 1], (object) ['id' => 2], (object) ['id' => 3]] ],
        ];
    }
}
