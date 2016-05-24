<?php
namespace Automatorm\Exception;

class BaseException extends \Exception
{
    protected $data;
    
	public function __construct($message = '', $data = null, \Exception $previous_exception = null)
	{
		parent::__construct($message, 0, $previous_exception);
		$this->data = $data;
	}
    
	public function getData()
	{
		return $this->data;
	}
}