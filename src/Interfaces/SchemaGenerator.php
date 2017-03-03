<?php
namespace Automatorm\Interfaces;

use Automatorm\Interfaces\Connection as ConnectionInterface;

interface SchemaGenerator
{
    public function __construct(ConnectionInterface $connection);
    
    public function generate();
}
