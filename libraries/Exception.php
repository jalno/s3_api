<?php
namespace packages\aws_s3_api;

use packages\aws_s3_api\Response\Error;
use Throwable;

class Exception extends \Exception {
	public static function fromError(Error $error): self {
		return new self(
			$error->getCode() ?? '',
			$error->getMessage() ?? '',
			$error
		);
	}

	/**
	 * @param string|int $code
	 */
	public function __construct($code, string $message = '', ?Throwable $previous = null) {
		parent::__construct($message, (int) $code, $previous);
		$this->code = $code;
		$this->message = $message;
	}
}
