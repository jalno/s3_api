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

	public function __construct(string $code, string $message = '', ?Throwable $previous = null) {
		$this->code = $code;
		$this->message = $message;
		parent::__construct($message, $code, $previous);
	}
}
