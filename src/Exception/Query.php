<?php
namespace Automatorm\Exception;

use Automatorm\Database\Query as Q;

class Query extends Database
{
    public function __construct(Q $query, \Exception $previous_exception = null)
    {
        parent::__construct('Query error: '.$query->error, $query, $previous_exception);
    }
}
