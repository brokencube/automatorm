<?php

namespace Automatorm\UnitTest\Database;

use Automatorm\Database\QueryBuilder;
use Automatorm\Exception\QueryBuilder as QueryBuilderException;
use PHPUnit\Framework\TestCase;

class QueryBuilderTest extends TestCase
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
        $qb = QueryBuilder::count('test');
        list($sql, $data) = $qb->resolve();
        
        $this->assertEquals('SELECT COUNT(*) AS `count` FROM `test`', $sql);
        $this->assertEquals(0, count($data));
    }

    public function testSimpleInsert()
    {
        $qb = QueryBuilder::insert('test', ['id' => 1, 'value' => 'foo']);
        list($sql, $data) = $qb->resolve();
        
        $this->assertEquals('INSERT INTO `test` (`id`, `value`) VALUES (?, ?)', $sql);
        $this->assertEquals(2, count($data));
        $this->assertEquals(1, $data[0]);
        $this->assertEquals('foo', $data[1]);
    }
    
    public function testSimpleInsertIgnore()
    {
        $qb = QueryBuilder::insert('test', ['id' => 1, 'value' => 'foo'], true);
        list($sql, $data) = $qb->resolve();
        
        $this->assertEquals('INSERT IGNORE INTO `test` (`id`, `value`) VALUES (?, ?)', $sql);
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

        $this->assertEquals('SELECT `id` FROM `test` AS `t` JOIN `join_table` AS `jt`', $sql);
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

        $this->assertEquals('SELECT `id` FROM `test` AS `t` JOIN `join_table` AS `jt` ON `jt`.`id` = `t`.`id` AND `jt`.`id` = ? WHERE `t`.`id` = ?', $sql);
        $this->assertEquals(2, count($data));
        $this->assertEquals(1, $data[0]);
        $this->assertEquals(2, $data[1]);
    }

    public function testColumnName()
    {
        $qb = QueryBuilder::select(['test' => 't'], ['id' => 'tid']);
        list($sql, $data) = $qb->resolve();
        
        $this->assertEquals('SELECT `id` AS `tid` FROM `test` AS `t`', $sql);
        $this->assertEquals(0, count($data));
    }

    public function testDoubleWrappedColumnName()
    {
        $qb = QueryBuilder::select(['test' => 't'], [['id' => 'tid']]);
        list($sql, $data) = $qb->resolve();
        
        $this->assertEquals('SELECT `id` AS `tid` FROM `test` AS `t`', $sql);
        $this->assertEquals(0, count($data));
    }

    public function testColumnNameBackticks()
    {
        $qb = QueryBuilder::select(['test' => 't'], ['`t`.id']);
        list($sql, $data) = $qb->resolve();
        
        $this->assertEquals('SELECT `t`.`id` FROM `test` AS `t`', $sql);
        $this->assertEquals(0, count($data));
    }

    public function testLessComplexColumnName()
    {
        $qb = QueryBuilder::select(['test' => 't'], [['t', 'id']]);
        list($sql, $data) = $qb->resolve();
        
        $this->assertEquals('SELECT `t`.`id` FROM `test` AS `t`', $sql);
        $this->assertEquals(0, count($data));
    }

    public function testComplexColumnName()
    {
        $qb = QueryBuilder::select(['test' => 't'], [['t', 'id' => 'tid']]);
        list($sql, $data) = $qb->resolve();
        
        $this->assertEquals('SELECT `t`.`id` AS `tid` FROM `test` AS `t`', $sql);
        $this->assertEquals(0, count($data));
    }

    public function testExtraComplexColumnName()
    {
        $qb = QueryBuilder::select(['test' => 't'], [['database', 't', 'id' => 'tid']]);
        list($sql, $data) = $qb->resolve();
        
        $this->assertEquals('SELECT `database`.`t`.`id` AS `tid` FROM `test` AS `t`', $sql);
        $this->assertEquals(0, count($data));
    }
    
    public function testComplexTableName()
    {
        $qb = QueryBuilder::select(['schema', 'test' => 't'], ['id']);
        list($sql, $data) = $qb->resolve();
        
        $this->assertEquals('SELECT `id` FROM `schema`.`test` AS `t`', $sql);
        $this->assertEquals(0, count($data));
    }

    public function testMoreComplexTableName()
    {
        $qb = QueryBuilder::select(['database', 'schema', 'test' => 't'], ['id']);
        list($sql, $data) = $qb->resolve();
        
        $this->assertEquals('SELECT `id` FROM `database`.`schema`.`test` AS `t`', $sql);
        $this->assertEquals(0, count($data));
    }

    public function testComplexTableNameException()
    {
        $this->expectException(QueryBuilderException::class);
        
        $qb = QueryBuilder::select(['undefined', 'database', 'schema', 'test' => 't'], ['id']);
        list($sql, $data) = $qb->resolve();
    }

    public function testUnknownJoinType()
    {
        $this->expectException(QueryBuilderException::class);
        
        $qb = QueryBuilder::select('test', ['id'])
            ->join('join_table', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        list($sql, $data) = $qb->resolve();
    }
    
    public function testInClause()
    {
        $qb = QueryBuilder::select(['database', 'schema', 'test' => 't'], ['id']);
        $qb->where(['t.data' => [1,2,3]]);
        list($sql, $data) = $qb->resolve();
        
        $this->assertEquals('SELECT `id` FROM `database`.`schema`.`test` AS `t` WHERE `t`.`data` in (?,?,?)', $sql);
        $this->assertEquals(3, count($data));
    }

    public function testInBlankClause()
    {
        $qb = QueryBuilder::select(['database', 'schema', 'test' => 't'], ['id']);
        $qb->where(['t.data' => []]);
        list($sql, $data) = $qb->resolve();
        
        $this->assertEquals('SELECT `id` FROM `database`.`schema`.`test` AS `t` WHERE false', $sql);
        $this->assertEquals(0, count($data));
    }

    public function testNotInBlankClause()
    {
        $qb = QueryBuilder::select(['database', 'schema', 'test' => 't'], ['id']);
        $qb->where(['!t.data' => []]);
        list($sql, $data) = $qb->resolve();
        
        $this->assertEquals('SELECT `id` FROM `database`.`schema`.`test` AS `t` WHERE true', $sql);
        $this->assertEquals(0, count($data));
    }
}
