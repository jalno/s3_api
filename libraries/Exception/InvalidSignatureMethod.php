<?php
namespace packages\s3_api\Exception;

use Exception;

/**
 * Invalid Amazon S3 signature method
 */
class InvalidSignatureMethod extends ConfigurationError
{
	public function __construct(string $message = "", int $code = 0, Exception $previous = null)
	{
		if (empty($message))
		{
			$message = 'The Amazon S3 signature method provided is invalid. Only v2 and v4 signatures are supported.';
		}

		parent::__construct($message, $code, $previous);
	}

}
