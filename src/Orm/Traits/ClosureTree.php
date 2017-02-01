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
		$query = new Query(static::getConnection());
		$query->sql(QueryBuilder::insert($this->closureTable, ['parent_id' => $this->id, 'child_id' => $this->id, 'depth' => 0]));
		$query->execute();
	}
	
	public function addParent(self $parent)
	{
		$table = $this->closureTable;
		
		$query = new Query(static::getConnection());
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
		
		$query = new Query(static::getConnection());
		$query->sql(" 
			DELETE FROM $table WHERE id IN(
				SELECT a.id FROM (
					SELECT rel.id FROM $table p, $table rel, $table c
					WHERE p.parent_id = rel.parent_id and c.child_id = rel.child_id
					AND p.child_id = {$parent->id} AND c.parent_id = {$this->id}
				) as a
			);
		");
		$query->execute();
	}

	public function getFullTree()
	{
		$table = $this->closureTable;
		
		// Query to find root node, and all direct child/parent relationships
		$query = new Query(static::getConnection());
		$query->sql("
			SELECT parent_id as id FROM $table WHERE child_id = {$this->id} ORDER BY depth DESC LIMIT 1;
		");
		$query->sql("
			SELECT parent_id, child_id FROM $table as t WHERE t.parent_id IN (
				SELECT child_id FROM $table WHERE parent_id = (
					SELECT parent_id FROM $table WHERE child_id = {$this->id} ORDER BY depth DESC LIMIT 1
				)
			) AND t.depth = 1;
		");
		list($root, $results) = $query->execute();
		
		// Root Folder Id
		$rootid = $root[0]['id'];
		
		// "Find" all nodes in this tree to so all node objects are in instance cache
		$ids = [$rootid];
		foreach ($results as $row) $ids[] = $row['child_id'];
		
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
		$query = new Query(static::getConnection());
		$query->sql(
			QueryBuilder::select($this->closureTable, ['parent_id'])->where(['child_id' => $this->id, 'depth' => 1])
		);
		list($results) = $query->execute();
		
		$parents = [];
		foreach ($results as $row) $parents[] = $row['parent_id'];
		
		return static::findAll(['id' => $parents]);
	}
	
	public function getChildren()
	{
		// Find all direct child/parent relationships
		$query = new Query(static::getConnection());
		$query->sql(
			QueryBuilder::select($this->closureTable, ['child_id'])->where(['parent_id' => $this->id, 'depth' => 1])
		);
		list($results) = $query->execute();
		
		$children = [];
		foreach ($results as $row) $children[] = $row['child_id'];
		
		return static::findAll(['id' => $children]);		
	}
	
	public function _property_parents()
	{
		return static::getParents();
	}
	
	public function _property_children()
	{
		return static::getChildren();
	}
}

