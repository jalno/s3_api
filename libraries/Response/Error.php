<?php
namespace packages\s3_api\Response;

use packages\base\view\Error as BaseError;

class Error extends BaseError {

	public function __construct(?string $code = null, ?string $message = null) {
		$this->code = $code;
		$this->message = $message;
	}

	public function setBucketName(string $bucketName): void {
		$this->setData($bucketName, 'BucketName');
	}
	public function getBucketName(): ?string {
		return $this->getData('BucketName');
	}
	public function setResource(string $resource): void {
		$this->setData($resource, 'Resource');
	}
	public function getResource(): ?string {
		return $this->getData('Resource');
	}
	public function setRequestId(string $requestId): void {
		$this->setData($requestId, 'RequestId');
	}
	public function getRequestId(): ?string {
		return $this->getData('RequestId');
	}
	public function setHostId(string $hostId): void {
		$this->setData($hostId, 'HostId');
	}
	public function getHostId(): ?string {
		return $this->getData('HostId');
	}

}
