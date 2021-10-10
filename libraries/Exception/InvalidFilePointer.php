<?php
namespace packages\aws_s3_api\Exception;

use Exception;
use InvalidArgumentException;

class InvalidFilePointer extends InvalidArgumentException
{
	public function __construct(string $message = "", int $code = 0, Exception $previous = null)
	{
		if (empty($message))
		{
			$message = 'The specified file pointer is not a valid stream resource';
		}

		parent::__construct($message, $code, $previous);
	}

}
