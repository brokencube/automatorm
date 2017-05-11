<?php

namespace Automatorm\UnitTest\Orm;

use Automatorm\Fake;
use Automatorm\Orm\Schema;

require_once('FakeModels.php');

class ModelTest extends \PHPUnit_Framework_TestCase
{
    protected $connection;
    protected $schema;
    
    public function setUp()
    {
        $testdata = <<<TEST
project|id:pk:int, description:text, date_created:date, account_id:int
    1,"my project","2016-01-01",2
    2,"my project 2","2016-01-02",2
    account_id->account|id

account|id:pk:int, first_name:text, last_name:text
    1,"nik","barham"
    2,"craig","king"

account_project|account_id:pk:int, project_id:pk:int
    1,1
    account_id->account|id
    project_id->project|id    
TEST;

        $this->connection = new Fake\Connection($testdata);
        $this->schema = Schema::generate($this->connection, "Automatorm\\UnitTest\\Fake");
    }
    
    // ::get
    public function testGetReturnsObject()
    {
        $project = \Automatorm\UnitTest\Fake\Project::get(1);
        $this->assertInstanceOf(\Automatorm\UnitTest\Fake\Project::class, $project);
    }

    public function testFailedGetReturnsNull()
    {
        $project = \Automatorm\UnitTest\Fake\Project::get(3);
        $this->assertNull($project);
    }

    public function testGetReturnsProperty()
    {
        $project = \Automatorm\UnitTest\Fake\Project::get(1);
        $this->assertEquals($project->description, "my project");
    }

    // ::find
    public function testFindReturnsObject()
    {
        $project = \Automatorm\UnitTest\Fake\Project::find(['id' => 1]);
        $this->assertInstanceOf(\Automatorm\UnitTest\Fake\Project::class, $project);
    }

    public function testFailedFindReturnsNull()
    {
        $project = \Automatorm\UnitTest\Fake\Project::find(['id' => 3]);
        $this->assertNull($project);
    }

    public function testFindReturnsProperty()
    {
        $project = \Automatorm\UnitTest\Fake\Project::find(['id' => 1]);
        $this->assertEquals($project->description, "my project");
    }
    
    // ::findAll Single
    public function testFindAllReturnsCollection()
    {
        $project = \Automatorm\UnitTest\Fake\Project::findAll(['id' => 1]);
        $this->assertInstanceOf(\Automatorm\Orm\Collection::class, $project);
    }

    public function testFailedFindAllReturnsCollection()
    {
        $project = \Automatorm\UnitTest\Fake\Project::findAll(['id' => 3]);
        $this->assertInstanceOf(\Automatorm\Orm\Collection::class, $project);
    }

    public function testFindAllReturnsCollectionOfProject()
    {
        $project = \Automatorm\UnitTest\Fake\Project::findAll(['id' => 1]);
        $this->assertInstanceOf(\Automatorm\UnitTest\Fake\Project::class, $project->first());
        $this->assertEquals(1, $project->count());
    }

    public function testFailedFindAllReturnsEmptyCollection()
    {
        $project = \Automatorm\UnitTest\Fake\Project::findAll(['id' => 3]);
        $this->assertNull($project->first());
        $this->assertEquals(0, $project->count());
    }

    public function testFindAllReturnsCollectionOfProperties()
    {
        $project = \Automatorm\UnitTest\Fake\Project::findAll(['id' => 1]);
        $this->assertInstanceOf(\Automatorm\Orm\Collection::class, $project->description);
        $this->assertEquals(1, $project->description->count());
        $this->assertEquals($project->first()->description, "my project");
        $this->assertEquals($project->description->first(), "my project");
    }
    
    // ::findAll Many
    public function testUnboundFindAllReturnsCollectionOfAll()
    {
        $project = \Automatorm\UnitTest\Fake\Project::findAll();
        $this->assertInstanceOf(\Automatorm\Orm\Collection::class, $project);
        $this->assertEquals(2, $project->count());
        $this->assertInstanceOf(\Automatorm\UnitTest\Fake\Project::class, $project->first(), 'First object is not instance of Automatorm\UnitTest\Fake\Project');
        $this->assertInstanceOf(\Automatorm\UnitTest\Fake\Project::class, $project->last(), 'Last object is not instance of Automatorm\UnitTest\Fake\Project');
        $this->assertEquals(1, $project->first()->id);
        $this->assertEquals(2, $project->last()->id);
    }
}
