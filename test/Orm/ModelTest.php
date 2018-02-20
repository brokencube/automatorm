<?php

namespace Automatorm\UnitTest\Orm;
use PHPUnit\Framework\TestCase;

use Automatorm\DataLayer\Fake;
use Automatorm\Orm\Schema;

use Automatorm\UnitTest\Fake\Project;
use Automatorm\UnitTest\Fake\Account;

require_once('FakeModels.php');

class ModelTest extends TestCase
{
    protected $connection;
    protected $schema;
    
    public function setUp()
    {
        // Create Fake Datastore
        $default = new Fake\Data(file_get_contents(__DIR__ . '/ModelTest.default.data'), 'default');
        $meta = new Fake\Data(file_get_contents(__DIR__ . '/ModelTest.meta.data'), 'meta');
        $datastore = new Fake\DataStore([$default, $meta]);
        
        // Create Fake Connections to Datastore
        $this->connection['default'] = new Fake\Connection($datastore, 'default');
        $this->connection['meta'] = new Fake\Connection($datastore, 'meta');
        
        // Create ORM Schema
        $this->schema['default'] = Schema::generate($this->connection['default'], "Automatorm\\UnitTest\\Fake");
        $this->schema['meta'] = Schema::generate($this->connection['meta'], "Automatorm\\UnitTest\\FakeMeta");
    }
    
    // ::get
    public function testGetReturnsObject()
    {
        $project = Project::get(1);
        $this->assertInstanceOf(Project::class, $project);
    }

    public function testFailedGetReturnsNull()
    {
        $project = Project::get(3);
        $this->assertNull($project);
    }

    public function testGetReturnsProperty()
    {
        $project = Project::get(1);
        $this->assertEquals($project->title, "my project");
    }

    // ::find
    public function testFindReturnsObject()
    {
        $project = Project::find(['id' => 1]);
        $this->assertInstanceOf(Project::class, $project);
    }

    public function testFailedFindReturnsNull()
    {
        $project = Project::find(['id' => 3]);
        $this->assertNull($project);
    }

    public function testFindReturnsProperty()
    {
        $project = Project::find(['id' => 1]);
        $this->assertEquals($project->title, "my project");
    }
    
    // ::countAll
    public function testCount()
    {
        $count = Project::countAll();
        $this->assertEquals($count, 2);
    }

    public function testCountWithWhere()
    {
        $count = Project::countAll(['id' => 1]);
        $this->assertEquals($count, 1);
    }
    
    // ::findAll Single
    public function testFindAllReturnsCollection()
    {
        $project = Project::findAll(['id' => 1]);
        $this->assertInstanceOf(\Automatorm\Orm\Collection::class, $project);
    }

    public function testFailedFindAllReturnsCollection()
    {
        $project = Project::findAll(['id' => 3]);
        $this->assertInstanceOf(\Automatorm\Orm\Collection::class, $project);
        $this->assertEquals(0, $project->count());
    }

    public function testFindAllReturnsCollectionOfProject()
    {
        $project = Project::findAll(['id' => 1]);
        $this->assertInstanceOf(Project::class, $project->first());
        $this->assertEquals(1, $project->count());
    }

    public function testFindAllMoreThan()
    {
        $project = Project::findAll(['>id' => 0]);
        $this->assertInstanceOf(Project::class, $project->first());
        $this->assertEquals(2, $project->count());

        $project = Project::findAll(['id>' => 0]);
        $this->assertInstanceOf(Project::class, $project->first());
        $this->assertEquals(2, $project->count());

        $project = Project::findAll(['id>' => 1]);
        $this->assertInstanceOf(Project::class, $project->first());
        $this->assertEquals(1, $project->count());

        $project = Project::findAll(['id>' => 2]);
        $this->assertNull($project->first());
        $this->assertEquals(0, $project->count());
    }

    public function testFindAllMoreThanEquals()
    {
        $project = Project::findAll(['>=id' => 1]);
        $this->assertInstanceOf(Project::class, $project->first());
        $this->assertEquals(2, $project->count());

        $project = Project::findAll(['id>=' => 1]);
        $this->assertInstanceOf(Project::class, $project->first());
        $this->assertEquals(2, $project->count());

        $project = Project::findAll(['id>=' => 2]);
        $this->assertInstanceOf(Project::class, $project->first());
        $this->assertEquals(1, $project->count());

        $project = Project::findAll(['id>=' => 3]);
        $this->assertNull($project->first());
        $this->assertEquals(0, $project->count());
    }

    public function testFindAllLessThan()
    {
        $project = Project::findAll(['<id' => 3]);
        $this->assertInstanceOf(Project::class, $project->first());
        $this->assertEquals(2, $project->count());

        $project = Project::findAll(['id<' => 3]);
        $this->assertInstanceOf(Project::class, $project->first());
        $this->assertEquals(2, $project->count());

        $project = Project::findAll(['id<' => 2]);
        $this->assertInstanceOf(Project::class, $project->first());
        $this->assertEquals(1, $project->count());

        $project = Project::findAll(['id<' => 1]);
        $this->assertNull($project->first());
        $this->assertEquals(0, $project->count());
    }

    public function testFindAllLessThanEquals()
    {
        $project = Project::findAll(['<=id' => 2]);
        $this->assertInstanceOf(Project::class, $project->first());
        $this->assertEquals(2, $project->count());
    
        $project = Project::findAll(['id<=' => 2]);
        $this->assertInstanceOf(Project::class, $project->first());
        $this->assertEquals(2, $project->count());

        $project = Project::findAll(['id<=' => 1]);
        $this->assertInstanceOf(Project::class, $project->first());
        $this->assertEquals(1, $project->count());

        $project = Project::findAll(['id<=' => 0]);
        $this->assertNull($project->first());
        $this->assertEquals(0, $project->count());
    }

    public function testFindAllEquals()
    {
        $project = Project::findAll(['id' => 2]);
        $this->assertInstanceOf(Project::class, $project->first());
        $this->assertEquals(1, $project->count());

        $project = Project::findAll(['id=' => 2]);
        $this->assertInstanceOf(Project::class, $project->first());
        $this->assertEquals(1, $project->count());

        $project = Project::findAll(['id==' => 2]);
        $this->assertInstanceOf(Project::class, $project->first());
        $this->assertEquals(1, $project->count());

        $project = Project::findAll(['=id' => 2]);
        $this->assertInstanceOf(Project::class, $project->first());
        $this->assertEquals(1, $project->count());

        $project = Project::findAll(['==id' => 2]);
        $this->assertInstanceOf(Project::class, $project->first());
        $this->assertEquals(1, $project->count());
    }

    public function testFindAllNot()
    {
        $project = Project::findAll(['!id' => 2]);
        $this->assertInstanceOf(Project::class, $project->first());
        $this->assertEquals(1, $project->count());

        $project = Project::findAll(['id!' => 2]);
        $this->assertInstanceOf(Project::class, $project->first());
        $this->assertEquals(1, $project->count());

        $project = Project::findAll(['id<>' => 2]);
        $this->assertInstanceOf(Project::class, $project->first());
        $this->assertEquals(1, $project->count());

        $project = Project::findAll(['<>id' => 2]);
        $this->assertInstanceOf(Project::class, $project->first());
        $this->assertEquals(1, $project->count());

        $project = Project::findAll(['!=id' => 2]);
        $this->assertInstanceOf(Project::class, $project->first());
        $this->assertEquals(1, $project->count());

        $project = Project::findAll(['id!=' => 2]);
        $this->assertInstanceOf(Project::class, $project->first());
        $this->assertEquals(1, $project->count());
    }

    public function testFailedFindAllReturnsEmptyCollection()
    {
        $project = Project::findAll(['id' => 3]);
        $this->assertNull($project->first());
        $this->assertEquals(0, $project->count());
    }

    public function testFindAllReturnsCollectionOfProperties()
    {
        $project = Project::findAll(['id' => 1]);
        $this->assertInstanceOf(\Automatorm\Orm\Collection::class, $project->title);
        $this->assertEquals(1, $project->title->count());
        $this->assertEquals($project->first()->title, "my project");
        $this->assertEquals($project->title->first(), "my project");
    }
    
    // ::findAll Many
    public function testUnboundFindAllReturnsCollectionOfAll()
    {
        $project = Project::findAll();
        $this->assertInstanceOf(\Automatorm\Orm\Collection::class, $project);
        $this->assertEquals(2, $project->count());
        $this->assertInstanceOf(Project::class, $project->first(), 'First object is not instance of Automatorm\UnitTest\Fake\Project');
        $this->assertInstanceOf(Project::class, $project->last(), 'Last object is not instance of Automatorm\UnitTest\Fake\Project');
        $this->assertEquals(1, $project->first()->id);
        $this->assertEquals(2, $project->last()->id);
    }
    
    // insert
    public function testSimpleCreate()
    {
        $db = Project::newData();
        $db->title = 'Insert';
        $db->date_created = new \DateTime('2017-09-05T20:13:14+01:00');
        $project = Project::commitNew($db);

        $this->assertInstanceOf(Project::class, $project);
        $this->assertEquals(3, $project->id);
        $this->assertEquals("Insert", $project->title);
        $this->assertEquals("2017-09-05T20:13:14+01:00", $project->date_created->format('c'));
    }
    
    public function testSimpleUpdate()
    {
        $project = Project::get(1);
        $db = $project->data();
        $db->title = 'Updated';
        $project->commit($db);
        
        // Check original reference is updated
        $this->assertInstanceOf(Project::class, $project);
        $this->assertEquals('Updated', $project->title);
        $this->assertEquals('blah', $project->description);
        
        // Check still works after getting from another source
        $project = Project::find(['id' => 1]);
        $this->assertInstanceOf(Project::class, $project);
        $this->assertEquals('Updated', $project->title);
        $this->assertEquals('blah', $project->description);

        // Check other items unaffected
        $project2 = Project::get(2);
        $this->assertInstanceOf(Project::class, $project2);
        $this->assertEquals('my project 2', $project2->title);
        $this->assertEquals('blah2', $project2->description);
    }

    // M2M
    public function testM2M()
    {
        $project = Project::get(1);
        $accounts = $project->account_project;
        
        $this->assertInstanceOf(\Automatorm\Orm\Collection::class, $accounts);
        $this->assertInstanceOf(Account::class, $accounts[0]);
        $this->assertEquals($accounts->first()->id, "1");
    }
    
    // CrossSchema
    public function testCrossSchema()
    {
        $project = Project::get(1);
        $name = $project->project_type->name;
        
        $this->assertEquals($name, "normal");
    }
}
