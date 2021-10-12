<?php
namespace packages\s3_api\Exception;

use Exception;

/**
 * Invalid Amazon S3 endpoint
 */
class InvalidEndpoint extends ConfigurationError
{
	public function __construct(string $message = "", int $code = 0, Exception $previous = null)
	{
		if (empty($message))
		{
			$message = 'The custom S3 endpoint provided is invalid. Do NOT include the protocol (http:// or https://). Valid examples are s3.example.com and www.example.com/s3Api';
		}

		parent::__construct($message, $code, $previous);
	}

}
