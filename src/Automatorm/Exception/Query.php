<?php
namespace Automatorm\Exception;

use Automatorm\Database\Query;

class Query extends Database
{
	public function __construct(Query $query, \Exception $previous_exception = null)
	{
		parent::__construct('Query error: '.$query->error, $query, $previous_exception);
	}
}