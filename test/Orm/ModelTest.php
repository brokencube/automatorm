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
        $this->schema = Schema::generate($this->connection, "Automatorm\\UnitTest\\Fake", true);
    }
    
    public function testGetReturnsProperty()
    {
        $project = \Automatorm\UnitTest\Fake\Project::get(1);
        $this->assertEquals($project->description, "my project");
    }
    
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
}
