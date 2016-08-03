<?php

namespace Automatorm\UnitTest\Database;
use Automatorm\Database\QueryBuilder;

class QueryBuilderTest extends \PHPUnit_Framework_TestCase
{
    public function testSimplestCase()
    {
        $qb = QueryBuilder::select('test', ['id']);
        list($sql, $data) = $qb->resolve();
        
        $this->assertEquals('SELECT `id` FROM `test`', $sql);
        $this->assertEquals(0, count($data));
    }
}
    