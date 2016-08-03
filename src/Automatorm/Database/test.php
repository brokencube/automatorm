<?php

require_once "QueryBuilder.php";

$qb = Automatorm\Database\QueryBuilder::select('test',['id']);
list ($results, $data) = $qb->resolve();

echo $results;
var_dump($data);
