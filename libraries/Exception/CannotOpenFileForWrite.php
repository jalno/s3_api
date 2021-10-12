<?php
namespace packages\s3_api\Exception;

use Exception;
use RuntimeException;

class CannotOpenFileForWrite extends RuntimeException
{
	public function __construct(string $file = "", int $code = 0, Exception $previous = null)
	{
		$message = "Cannot open file: '{$file}' for writing";

		parent::__construct($message, $code, $previous);
	}

}
