<?php
namespace packages\aws_s3_api;

use SimpleXMLElement;
use packages\base\http\Response as BaseResponse;
use packages\aws_s3_api\Exception\PropertyNotFound;
use packages\aws_s3_api\Response\Error;

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
			$this->parsedBody = simplexml_load_string($this->getBody());
		}

		return $this->parsedBody;
	}
}
