<?php

namespace Automatorm\UnitTest\Database;
use Automatorm\Database\QueryBuilder;

class QueryBuilderTest extends \PHPUnit_Framework_TestCase
{
    public function testSimpleSelect()
    {
        $qb = QueryBuilder::select('test', ['id']);
        list($sql, $data) = $qb->resolve();
        
        $this->assertEquals('SELECT `id` FROM `test`', $sql);
        $this->assertEquals(0, count($data));
    }

    public function testSimpleCount()
    {
        $qb = QueryBuilder::count('test', '*');
        list($sql, $data) = $qb->resolve();
        
        $this->assertEquals('SELECT COUNT(*) as count FROM `test`', $sql);
        $this->assertEquals(0, count($data));
    }

    public function testSimpleInsert()
    {
        $qb = QueryBuilder::insert('test', ['id' => 1, 'value' => 'foo']);
        list($sql, $data) = $qb->resolve();
        
        $this->assertEquals('INSERT INTO `test` SET `id` = ?, `value` = ?', $sql);
        $this->assertEquals(2, count($data));
        $this->assertEquals(1, $data[0]);
        $this->assertEquals('foo', $data[1]);
    }
    
    public function testSimpleUpdate()
    {
        $qb = QueryBuilder::update('test', ['id' => 1, 'value' => 'foo']);
        list($sql, $data) = $qb->resolve();
        
        $this->assertEquals('UPDATE `test` SET `id` = ?, `value` = ?', $sql);
        $this->assertEquals(2, count($data));
        $this->assertEquals(1, $data[0]);
        $this->assertEquals('foo', $data[1]);
    }

    public function testSimpleDelete()
    {
        $qb = QueryBuilder::delete('test', ['id' => 1]);
        list($sql, $data) = $qb->resolve();
        
        $this->assertEquals('DELETE FROM `test` WHERE `id` = ?', $sql);
        $this->assertEquals(1, count($data));
        $this->assertEquals(1, $data[0]);
    }
    
    public function testSimpleJoin()
    {
        $qb = QueryBuilder::select('test', ['id'])
            ->join('join_table');
        list($sql, $data) = $qb->resolve();

        $this->assertEquals('SELECT `id` FROM `test` JOIN `join_table`', $sql);
        $this->assertEquals(0, count($data));
    }

    public function testSimpleLeftJoin()
    {
        $qb = QueryBuilder::select('test', ['id'])
            ->join('join_table', 'left');
        list($sql, $data) = $qb->resolve();

        $this->assertEquals('SELECT `id` FROM `test` LEFT JOIN `join_table`', $sql);
        $this->assertEquals(0, count($data));
    }

    public function testSimpleJoinWithAliases()
    {
        $qb = QueryBuilder::select(['test' => 't'], ['id'])
            ->join(['join_table' => 'jt']);
        list($sql, $data) = $qb->resolve();

        $this->assertEquals('SELECT `id` FROM `test` as `t` JOIN `join_table` as `jt`', $sql);
        $this->assertEquals(0, count($data));
    }

    public function testJoinOnClauses()
    {
        $qb = QueryBuilder::select(['test' => 't'], ['id'])
            ->join(['join_table' => 'jt'])
                ->joinOn(['jt.id' => 't.id'])
                ->joinWhere(['jt.id' => 1])
            ->where(['t.id' => 2])
        ;
        list($sql, $data) = $qb->resolve();

        $this->assertEquals('SELECT `id` FROM `test` as `t` JOIN `join_table` as `jt` ON `jt`.`id` = ? AND `jt`.`id` = `t`.`id` WHERE `t`.`id` = ?', $sql);
        $this->assertEquals(2, count($data));
        $this->assertEquals(1, $data[0]);
        $this->assertEquals(2, $data[1]);
    }
    
    public function testComplexTableName()
    {
        $qb = QueryBuilder::select(['schema', 'test' => 't'], ['id']);
        list($sql, $data) = $qb->resolve();

        $this->assertEquals('SELECT `id` FROM `schema`.`test` as `t`', $sql);
        $this->assertEquals(0, count($data));
        
    }

    public function testMoreComplexTableName()
    {
        $qb = QueryBuilder::select(['database', 'schema', 'test' => 't'], ['id']);
        list($sql, $data) = $qb->resolve();

        $this->assertEquals('SELECT `id` FROM `database`.`schema`.`test` as `t`', $sql);
        $this->assertEquals(0, count($data));
    }
}
    