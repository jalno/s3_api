<?php
namespace packages\aws_s3_api;

use packages\base\http\Response as BaseResponse;
use packages\aws_s3_api\Exception\PropertyNotFound;
use packages\aws_s3_api\Response\Error;
use RunTimeException;
use SimpleXMLElement;

class Response extends BaseResponse {

	protected ?SimpleXMLElement $parsedBody = null;

	public function getParsedBody(bool $reparse = false): ?SimpleXMLElement {
		if ($this->parsedBody !== null and !$reparse) {
			return $this->parsedBody;
		}
		if (empty($this->getBody())) {
			return null;
		}
		if ($this->getHeader('type') === null) {
			$this->setHeader('type', 'text/plain');
		}

		$file = $this->getFile();
		if (empty($file) and is_string($this->getBody()) and (
			($this->getHeader('type') == 'application/xml') or (substr($this->getBody(), 0, 5) == '<?xml')
		)) {
			$xml = simplexml_load_string($this->getBody());
			if ($xml === false) {
				throw new RunTimeException('can not parse xml');
			}
			$this->parsedBody = $xml;
		}

		return $this->parsedBody;
	}

	/**
	 * @param null|int[] $validHttpStatusCodes
	 */
	public function hasValidStatusCode(?array $validHttpStatusCodes = null): bool {
		$statusCode = $this->getStatusCode();
		if ($validHttpStatusCodes and !in_array($statusCode, $validHttpStatusCodes)) {
			return false;
		}
		return true;
	}

	public function isError(): bool {
		$parsedBody = $this->getParsedBody();
		if ($parsedBody and isset($parsedBody->Code, $parsedBody->Message)) {
			return true;
		}
		return false;
	}

	public function getError(): ?Error {
		if (!$this->isError()) {
			return null;
		}
		$parsedBody = $this->getParsedBody();
		$error = new Error($parsedBody->Code, $parsedBody->Message);
		if (isset($parsedBody->BucketName)) {
			$error->setBucketName($parsedBody->BucketName);
		}
		if (isset($parsedBody->BucketName)) {
			$error->setBucketName($parsedBody->BucketName);
		}
		if (isset($parsedBody->Resource)) {
			$error->setResource($parsedBody->Resource);
		}
		if (isset($parsedBody->RequestId)) {
			$error->setRequestId($parsedBody->RequestId);
		}
		if (isset($parsedBody->HostId)) {
			$error->setHostId($parsedBody->HostId);
		}
		return $error;
	}
}
