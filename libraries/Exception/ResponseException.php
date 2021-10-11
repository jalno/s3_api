<?php
namespace packages\aws_s3_api\Exception;

use packages\aws_s3_api\{Exception, Request, Response};
use packages\aws_s3_api\Response\Error;

class ResponseException extends Exception {

	public static function fromError(Error $error, ?Request $request = null, ?Response $response = null): self {
		$e = new self(
			$error->getCode() ?? '',
			$error->getMessage() ?? '',
			$error
		);
		if ($request) {
			$e->setRequest($request);
		}
		if ($response) {
			$e->setResponse($response);
		}
		return $e;
	}

	protected ?Request $request = null;

	protected ?Response $response = null;

	public function setRequest(Request $request): void {
		$this->request = $request;
	}
	public function getRequest(): ?Request {
		return $this->request;
	}
	public function setResponse(Response $response): void {
		$this->response = $response;
	}
	public function getResponse(): ?Response {
		return $this->response;
	}
}
