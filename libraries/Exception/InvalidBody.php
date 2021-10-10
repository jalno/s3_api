<?php
namespace packages\aws_s3_api\Exception;

use Exception;
use RuntimeException;

/**
 * Invalid response body type
 */
class InvalidBody extends RuntimeException
{
	public function __construct(string $message = "", int $code = 0, Exception $previous = null)
	{
		if (empty($message))
		{
			$message = 'Invalid response body type';
		}

		parent::__construct($message, $code, $previous);
	}

}
