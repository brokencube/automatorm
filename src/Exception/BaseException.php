<?php
namespace Automatorm\Exception;

class BaseException extends \Exception implements \JsonSerializable
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
	
	public function jsonSerialize()
	{
		$response = [
			'code' => $this->getCode(),
			'message' => $this->getMessage(),
			'file' => $this->getFile(),
			'line' => $this->getLine(),
			'trace' => $this->getTrace(),
			'data' => $this->getData()
		];
		
		if ($previous = $this->getPrevious()) {
			if ($previous instanceof \JsonSerializable) {
				$response['previous'] = $previous;
			} else {
				$response['previous'] = $previous->getMessage();
			}
		}
		
		return $response;
	}
}