<?php
namespace packages\s3_api\Exception;

use Exception;

/**
 * Invalid Amazon S3 secret key
 */
class InvalidSecretKey extends ConfigurationError
{
	public function __construct(string $message = "", int $code = 0, Exception $previous = null)
	{
		if (empty($message))
		{
			$message = 'The Amazon S3 Secret Key provided is invalid';
		}

		parent::__construct($message, $code, $previous);
	}

}
