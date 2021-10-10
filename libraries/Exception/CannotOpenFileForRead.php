<?php
namespace packages\aws_s3_api\Exception;

use Exception;
use RuntimeException;

class CannotOpenFileForRead extends RuntimeException
{
	public function __construct(string $file = "", int $code = 0, Exception $previous = null)
	{
		$message = "Cannot open $file for reading";

		parent::__construct($message, $code, $previous);
	}

}
