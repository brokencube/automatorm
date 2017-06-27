<?php
namespace Automatorm\Orm\Traits;

use Automatorm\Database\Query;
use Automatorm\Database\QueryBuilder;
use Automatorm\Orm\Collection;

/**
 * Expected closureTable structure:
 * Create Table closure (
 *   id INT NOT NULL AUTO_INCREMENT,
 *   parent_id INT NOT NULL,
 *   child_id INT NOT NULL,
 *   depth INT NOT NULL
 * )
 */
trait ClosureTree
{
    private $closureTable = null;
    protected function setClosureTable($table)
    {
        $this->closureTable = $table;
        
        // [FIXME] Check Sanity of selected table in Schema:: based on above structure
    }
    
    public function createInitialClosure()
    {
        $query = new Query($this->getConnection());
        $query->sql(QueryBuilder::insert($this->closureTable, ['parent_id' => $this->id, 'child_id' => $this->id, 'depth' => 0]));
        $query->execute();
    }
    
    public function addParent(self $parent)
    {
        $table = $this->closureTable;
        
        $query = new Query($this->getConnection());
        $query->sql(" 
            INSERT INTO $table (parent_id, child_id, depth)
            SELECT p.parent_id, c.child_id, p.depth+c.depth+1
            FROM $table p, $table c
            WHERE p.child_id = {$parent->id} AND c.parent_id = {$this->id}
        ");
        $query->execute();
    }

    public function removeParent(self $parent)
    {
        $table = $this->closureTable;
        
        // Find closure entries that refer to the parent id from this id.
        $findIds = QueryBuilder::select([$table => 'p'], [['rel', 'id']])
            ->join([$table => 'rel'])
                ->joinOn(['p.parent_id' => 'rel.parent_id'])
            ->join([$table => 'c'])
                ->joinOn(['c.child_id' => 'rel.child_id'])
            ->where(['p.child' => $parent->id, 'c.parent_id' => $this->id])
        ;
        
        // Run query to remove all of those ids
        $query = new Query($this->getConnection());
        $query->sql(
            QueryBuilder::delete($table)->where(['id' => $findIds])
        );
        $query->execute();
    }

    public function getFullTree()
    {
        $table = $this->closureTable;

        // Find the root node from the supplied node_id
        $innerQuery = QueryBuilder::select($table, ['parent_id' => 'id'])
            ->where(['child_id' => $this->id])
            ->orderBy('depth', 'desc')
            ->limit(1);

        // Find all the ancestors of the root node above - i.e. find all nodes in this graph 
        $middleQuery = QueryBuilder::select($table, ['child_id'])
            ->where(['parent_id' => $innerQuery]);

        // Find all depth 1 connections within the graph found above
        $outerQuery = QueryBuilder::select($table, ['parent_id', 'child_id'])
            ->where(['parent_id' => $middleQuery])
            ->where(['depth' => 1]);
        
        $query = new Query($this->getConnection());
        $query->sql($innerQuery); // Root node
        $query->sql($outerQuery); // All length 1 connections
        list($root, $results) = $query->execute();
        
        // Root Folder Id
        $rootid = $root[0]['id'];
        
        // "Find" all nodes in this tree to so all node objects are in instance cache
        $ids = [$rootid];
        foreach ($results as $row) {
            $ids[] = $row['child_id'];
        }
        
        // Reset children and parents arrays on all nodes
        foreach (static::findAll(['id' => $ids]) as $node) {
            $node->children = new Collection();
            $node->parents = new Collection();
        }
        
        // Foreach child/parent relationship, set the children/parents properties on the relevant objects.
        foreach ($results as $row) {
            $parent = static::get($row['parent_id']);
            $child = static::get($row['child_id']);
            $parent->children[] = $child;
            $child->parents[] = $parent;
        }
        
        // Return the root node
        return static::get($rootid);
    }
    
    public function changeParent(self $oldparent, self $newparent)
    {
        $this->removeParent($oldparent);
        $this->addParent($newparent);
    }
    
    public function getParents()
    {
        // Find all direct parent/child relationships
        $query = new Query($this->getConnection());
        $query->sql(
            QueryBuilder::select($this->closureTable, ['parent_id'])->where(['child_id' => $this->id, 'depth' => 1])
        );
        list($results) = $query->execute();
        
        $parents = [];
        foreach ($results as $row) {
            $parents[] = $row['parent_id'];
        }
        
        return static::findAll(['id' => $parents]);
    }
    
    public function getChildren()
    {
        // Find all direct child/parent relationships
        $query = new Query($this->getConnection());
        $query->sql(
            QueryBuilder::select($this->closureTable, ['child_id'])->where(['parent_id' => $this->id, 'depth' => 1])
        );
        list($results) = $query->execute();
        
        $children = [];
        foreach ($results as $row) {
            $children[] = $row['child_id'];
        }
        
        return static::findAll(['id' => $children]);
    }
}
