<?php
namespace packages\s3_api;

use InvalidArgumentException;
use packages\base\{Date, IO\Buffer, IO\File};
use packages\s3_api\Exception\CannotDeleteFile;
use packages\s3_api\Exception\CannotGetBucket;
use packages\s3_api\Exception\CannotGetFile;
use packages\s3_api\Exception\CannotListBuckets;
use packages\s3_api\Exception\CannotOpenFileForWrite;
use packages\s3_api\Exception\CannotPutFile;

class Connector
{
	/**
	 * Amazon S3 configuration object
	 *
	 * @var Configuration $configuration
	 */
	private $configuration = null;

	/**
	 * Connector constructor.
	 *
	 * @param   Configuration  $configuration  The configuration object to use
	 */
	public function __construct(Configuration $configuration)
	{
		$this->configuration = $configuration;
	}

	/**
	 * Put an object to Amazon S3, i.e. upload a file. If the object already exists it will be overwritten.
	 *
	 * @param   Input   $input           Input object
	 * @param   string  $bucket          Bucket name. If you're using v4 signatures it MUST be on the region defined.
	 * @param   string  $uri             Object URI. Think of it as the absolute path of the file in the bucket.
	 * @param   string  $acl             ACL constant, by default the object is private (visible only to the uploading
	 *                                   user)
	 * @param   array<string, string>   $requestHeaders  Array of request headers
	 *
	 * @return  void
	 *
	 * @throws CannotPutFile If the upload is not possible
	 */
	public function putObject(Input $input, string $bucket, string $uri, string $acl = Acl::ACL_PRIVATE, array $requestHeaders = []): void
	{
		$request = new Request('PUT', $bucket, $uri, $this->configuration);
		$request->setInput($input);

		// Custom request headers (Content-Type, Content-Disposition, Content-Encoding)
		if (count($requestHeaders))
		{
			foreach ($requestHeaders as $h => $v)
			{
				if (strtolower(substr($h, 0, 6)) == 'x-amz-')
				{
					$request->setAmzHeader(strtolower($h), $v);
				}
				else
				{
					$request->setHeader($h, $v);
				}
			}
		}

		if (isset($requestHeaders['Content-Type']))
		{
			$input->setType($requestHeaders['Content-Type']);
		}

		if ($input->getInputType() != Input::INPUT_DIRECTORY and
			($input->getSize() <= 0 or (
				($input->getInputType() == Input::INPUT_DATA) and (!strlen($input->getDataReference())
			)))
		) {
			throw new CannotPutFile(0, 'Missing input parameters');
		}

		// We need to post with Content-Length and Content-Type, MD5 is optional
		$request->setHeader('Content-Type', $input->getType());
		$request->setHeader('Content-Length', (string) $input->getSize());

		if ($input->getMd5sum())
		{
			$request->setHeader('Content-MD5', $input->getMd5sum());
		}

		$request->setAmzHeader('x-amz-acl', $acl);

		$response = $request->getResponse();

		if (!$response->hasValidStatusCode([200])) {
			$e = new CannotPutFile(
				$response->getStatusCode(),
				sprintf(
					__METHOD__ . "(): Unexpected HTTP status [%s] \n\nDebug info:\nrequest: %s \n",
					$response->getStatusCode(), print_r($request, true)
				)
			);
			$e->setRequest($request);
			$e->setResponse($response);
			throw $e;
		} elseif ($response->isError()) {
			throw CannotPutFile::fromError($response->getError(), $request, $response);
		}

		$parsedBody = $response->getParsedBody();
		if ($parsedBody and isset($parsedBody->CanonicalRequest)) {
			// For some reason, trying to single part upload files on some hosts comes back with an inexplicable
			// error from Amazon that we need to set Content-Length:5242880,5242880 instead of
			// Content-Length:5242880 which is AGAINST Amazon's documentation. In this case we pass the header
			// 'workaround-braindead-error-from-amazon' and retry. Uh, OK?

			$amazonsCanonicalRequest = (string) $parsedBody->CanonicalRequest;
			$lines                   = explode("\n", $amazonsCanonicalRequest);

			foreach ($lines as $line) {
				if (substr($line, 0, 15) != 'content-length:') {
					continue;
				}
				$exploded = explode(":", $line);
				$junk = $exploded[0];
				$stupidAmazonDefinedContentLength = $exploded[1];

				if (strpos($stupidAmazonDefinedContentLength, ',') !== false)
				{
					if (!isset($requestHeaders['workaround-braindead-error-from-amazon']))
					{
						$requestHeaders['workaround-braindead-error-from-amazon'] = 'you can\'t fix stupid';

						$this->putObject($input, $bucket, $uri, $acl, $requestHeaders);

						return;
					}
				}
			}
		}
	}

	/**
	 * Get (download) an object
	 *
	 * @param   string                	$bucket  Bucket name
	 * @param   string                	$uri     Object URI
	 * @param   string|File\Local|null  $saveTo  path to file or a jalno local file object or null
	 * @param   int|null              	$from    Start of the download range, null to download the entire object
	 * @param   int|null              	$to      End of the download range, null to download the entire object
	 *
	 * @return  string|null  No return if $saveTo is specified; data as string otherwise
	 *
	 */
	public function getObject(string $bucket, string $uri, $saveTo = null, ?int $from = null, ?int $to = null): ?string
	{
		$request = new Request('GET', $bucket, $uri, clone $this->configuration);

		if ($saveTo) {
			$file = null;
			if (is_string($saveTo)) {
				$file = new File\Local($saveTo);
			} elseif ($saveTo instanceof File\Local) {
				$file = &$saveTo;
			} else {
				throw new InvalidArgumentException(sprintf(
					__METHOD__ . "(): The given \$saveTo is not valid, value: %s", print_r($saveTo, true)
				));
			}
			$request->setFile($file);
		}

		// Set the range header
		if ((!empty($from) and !empty($to)) or (!is_null($from) and !empty($to))) {
			$request->setHeader('Range', "bytes=$from-$to");
		}

		$response = $request->getResponse();

		if (!$response->hasValidStatusCode([200, 206])) {
			$e = new CannotGetFile(
				$response->getStatusCode(),
				sprintf(
					__METHOD__ . "(): Unexpected HTTP status [%s] \n\nDebug info:\nrequest: %s \n",
					$response->getStatusCode(), print_r($request, true)
				)
			);
			$e->setRequest($request);
			$e->setResponse($response);
			throw $e;
		} elseif ($response->isError()) {
			throw CannotGetFile::fromError($response->getError(), $request, $response);
		}

		return empty($saveTo) ? $response->getBody() : null;
	}

	/**
	 * Get information about an object.
	 *
	 * @param   string                $bucket  Bucket name
	 * @param   string                $uri     Object URI
	 *
	 * @return  array{
	 * 	"accept-ranges": string,
	 * 	"content-length": string,
	 * 	"content-security-policy"?: string,
	 * 	"content-type": string,
	 * 	"etag": string,
	 * 	"last-modified": string,
	 * 	"server"?: string,
	 * 	"strict-transport-security"?: string,
	 * 	"vary": string,
	 * 	"x-amz-request-id": string,
	 * 	"x-content-type-options"?: string,
	 * 	"x-xss-protection"?: string,
	 * 	"date": int,
	 * 	"size": int,
	 * 	"type": string,
	 * 	"hash": string,
	 * }  The headers returned by Amazon S3
	 *
	 * @throws  CannotGetFile  If the file does not exist
	 * @see     https://docs.aws.amazon.com/AmazonS3/latest/API/API_HeadObject.html
	 */
	public function headObject(string $bucket, string $uri): array
	{
		$request = new Request('HEAD', $bucket, $uri, clone $this->configuration);

		$response = $request->getResponse();

		if (!$response->hasValidStatusCode([200, 206])) {
			$e = new CannotGetFile(
				$response->getStatusCode(),
				sprintf(
					__METHOD__ . "(): Unexpected HTTP status [%s] \n\nDebug info:\nrequest: %s \n",
					$response->getStatusCode(), print_r($request, true)
				)
			);
			$e->setRequest($request);
			$e->setResponse($response);
			throw $e;
		} elseif ($response->isError()) {
			throw CannotGetFile::fromError($response->getError(), $request, $response);
		}

		if ($response->getHeader('date')) {
			$response->setHeader('date', Date\Gregorian::strtotime($response->getHeader('date')));
		}
		if ($response->getHeader('last-modified')) {
			$response->setHeader('last-modified', Date\Gregorian::strtotime($response->getHeader('last-modified')));
		}

		/**
		 * @var mixed
		 */
		$result = $response->getHeaders();
		return $result;
	}


	/**
	 * Delete an object
	 *
	 * @param   string  $bucket  Bucket name
	 * @param   string  $uri     Object URI
	 *
	 * @return  void
	 */
	public function deleteObject(string $bucket, string $uri): void
	{
		$request  = new Request('DELETE', $bucket, $uri, clone $this->configuration);
		$response = $request->getResponse();

		if (!$response->hasValidStatusCode([200, 204])) {
			$e = new CannotDeleteFile(
				$response->getStatusCode(),
				sprintf(
					__METHOD__ . "(): Unexpected HTTP status [%s] \n\nDebug info:\nrequest: %s \n",
					$response->getStatusCode(), print_r($request, true)
				)
			);
			$e->setRequest($request);
			$e->setResponse($response);
			throw $e;
		} elseif ($response->isError()) {
			throw CannotDeleteFile::fromError($response->getError(), $request, $response);
		}
	}

	/**
	 * Get a query string authenticated URL
	 *
	 * @param   string    $bucket    Bucket name
	 * @param   string    $uri       Object URI
	 * @param   int|null  $lifetime  Lifetime in seconds
	 * @param   bool      $https     Use HTTPS ($hostBucket should be false for SSL verification)?
	 *
	 * @return  string
	 */
	public function getAuthenticatedURL(string $bucket, string $uri, ?int $lifetime = null, bool $https = false): string
	{
		// Get a request from the URI and bucket
		$questionmarkPos = strpos($uri, '?');
		$query           = '';

		if ($questionmarkPos !== false)
		{
			$query = substr($uri, $questionmarkPos + 1);
			$uri   = substr($uri, 0, $questionmarkPos);
		}


		/**
		 * !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
		 * !!!!             DO NOT TOUCH THIS CODE. YOU WILL BREAK PRE-SIGNED URLS WITH v4 SIGNATURES.              !!!!
		 * !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
		 *
		 * The following two lines seem weird and possibly extraneous at first glance. However, they are VERY important.
		 * If you remove them pre-signed URLs for v4 signatures will break! That's because pre-signed URLs with v4
		 * signatures follow different rules than with v2 signatures.
		 *
		 * Authenticated (pre-signed) URLs are always made against the generic S3 region endpoint, not the bucket's
		 * virtual-hosting-style domain name. The bucket is always the first component of the path.
		 *
		 * For example, given a bucket called foobar and an object baz.txt in it we are pre-signing the URL
		 * https://s3-eu-west-1.amazonaws.com/foobar/baz.txt, not
		 * https://foobar.s3-eu-west-1.amazonaws.com/foobar/baz.txt (as we'd be doing with v2 signatures).
		 *
		 * The problem is that the Request object needs to be created before we can convey the intent (regular request
		 * or generation of a pre-signed URL). As a result its constructor creates the (immutable) request URI solely
		 * based on whether the Configuration object's getUseLegacyPathStyle() returns false or not.
		 *
		 * Since we want to request URI to contain the bucket name we need to tell the Request object's constructor that
		 * we are creating a Request object for path-style access, i.e. the useLegacyPathStyle flag in the Configuration
		 * object is true. Naturally, the default behavior being virtual-hosting-style access to buckets, this flag is
		 * most likely **false**.
		 *
		 * Therefore we need to clone the Configuration object, set the flag to true and create a Request object using
		 * the falsified Configuration object.
		 *
		 * Note that v2 signatures are not affected. In v2 we are always appending the bucket name to the path, despite
		 * the fact that we include the bucket name in the domain name.
		 *
		 * !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
		 * !!!!             DO NOT TOUCH THIS CODE. YOU WILL BREAK PRE-SIGNED URLS WITH v4 SIGNATURES.              !!!!
		 * !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
		 */
		$newConfig = clone $this->configuration;
		$newConfig->setUseLegacyPathStyle(true);

		// Create the request object.
		$uri     = str_replace('%2F', '/', rawurlencode($uri));
		$request = new Request('GET', $bucket, $uri, $newConfig);

		if ($query)
		{
			parse_str($query, $parameters);

			if (count($parameters))
			{
				foreach ($parameters as $k => $v)
				{
					$request->setParameter($k, $v);
				}
			}
		}

		// Get the signed URI from the Request object
		return $request->getAuthenticatedURL($lifetime, $https);
	}

	/**
	 * Get the location (region) of a bucket. You need this to use the V4 API on that bucket!
	 *
	 * @param   string  $bucket  Bucket name
	 *
	 * @return  string
	 */
	public function getBucketLocation(string $bucket): string
	{
		$request = new Request('GET', $bucket, '', $this->configuration);
		$request->setParameter('location', null);

		$response = $request->getResponse();

		if (!$response->hasValidStatusCode([200])) {
			$e = new CannotGetBucket(
				$response->getStatusCode(),
				sprintf(
					__METHOD__ . "(): Unexpected HTTP status [%s] \n\nDebug info:\nrequest: %s \n",
					$response->getStatusCode(), print_r($request, true)
				)
			);
			$e->setRequest($request);
			$e->setResponse($response);
			throw $e;
		} elseif ($response->isError()) {
			throw CannotGetBucket::fromError($response->getError(), $request, $response);
		}

		$result = 'us-east-1';

		if ($response->getBody())
		{
			$result = (string) $response->getBody();
		}

		switch ($result)
		{
			// "EU" is an alias for 'eu-west-1', however the canonical location name you MUST use is 'eu-west-1'
			case 'EU':
			case 'eu':
				$result = 'eu-west-1';
				break;

			// If the bucket location is 'us-east-1' you get an empty string. @#$%^&*()!!
			case '':
				$result = 'us-east-1';
				break;
		}

		return $result;
	}

	/**
	 * Get the contents of a bucket
	 *
	 * If maxKeys is null this method will loop through truncated result sets
	 *
	 * @param   string       $bucket                Bucket name
	 * @param   string|null  $prefix                Prefix (directory)
	 * @param   string|null  $marker                Marker (last file listed)
	 * @param   int|null     $maxKeys               Maximum number of keys ("files" and "directories") to return
	 * @param   string       $delimiter             Delimiter, typically "/"
	 * @param   bool         $returnCommonPrefixes  Set to true to return CommonPrefixes
	 *
	 * @return  array<string, array{
	 * 	"name": string,
	 * 	"time": int,
	 * 	"size": int,
	 * 	"hash": string
	 * }|array{
	 * 	"prefix": string
	 * }>
	 */
	public function getBucket(string $bucket, ?string $prefix = null, ?string $marker = null, ?int $maxKeys = null, string $delimiter = '/', bool $returnCommonPrefixes = false): array
	{
		$configuration = clone $this->configuration;
		$configuration->setRegion('us-east-1');

		$request = new Request('GET', $bucket, '', $configuration);

		if (!empty($prefix))
		{
			$request->setParameter('prefix', $prefix);
		}

		if (!empty($marker))
		{
			$request->setParameter('marker', $marker);
		}

		if (!empty($maxKeys))
		{
			$request->setParameter('max-keys', $maxKeys);
		}

		if (!empty($delimiter))
		{
			$request->setParameter('delimiter', $delimiter);
		}

		$response = $request->getResponse();

		if (!$response->hasValidStatusCode([200])) {
			$e = new CannotGetBucket(
				$response->getStatusCode(),
				sprintf(__METHOD__ . "(): [%s] %s", $response->getStatusCode(), "Unexpected status code")
			);
			$e->setRequest($request);
			$e->setResponse($response);
			throw $e;
		} elseif ($response->isError()) {
			throw CannotGetBucket::fromError($response->getError(), $request, $response);
		}

		$results = [];

		$nextMarker = null;

		$parsedBody = $response->getParsedBody();
		if ($parsedBody and isset($parsedBody->Contents))
		{
			foreach ($parsedBody->Contents as $c)
			{
				$results[(string) $c->Key] = [
					'name' => (string) $c->Key,
					'time' => Date\Gregorian::strtotime((string) $c->LastModified),
					'size' => (int) $c->Size,
					'hash' => substr((string) $c->ETag, 1, -1),
				];

				$nextMarker = (string) $c->Key;
			}
		}

		if ($returnCommonPrefixes && $parsedBody && isset($parsedBody->CommonPrefixes))
		{
			foreach ($parsedBody->CommonPrefixes as $c)
			{
				$results[(string) $c->Prefix] = ['prefix' => (string) $c->Prefix];
			}
		}

		if ($parsedBody && isset($parsedBody->IsTruncated) &&
			((string) $parsedBody->IsTruncated == 'false')
		)
		{
			return $results;
		}

		if ($parsedBody && isset($parsedBody->NextMarker))
		{
			$nextMarker = (string) $parsedBody->NextMarker;
		}

		// Is it a truncated result?
		$isTruncated = ($nextMarker !== null) && ((string) $parsedBody->IsTruncated == 'true');
		// Is this a truncated result and no maxKeys specified?
		$isTruncatedAndNoMaxKeys = ($maxKeys == null) && $isTruncated;
		// Is this a truncated result with less keys than the specified maxKeys; and common prefixes found but not returned to the caller?
		$isTruncatedAndNeedsContinue = ($maxKeys != null) && $isTruncated && (count($results) < $maxKeys);

		// Loop through truncated results if maxKeys isn't specified
		if ($isTruncatedAndNoMaxKeys || $isTruncatedAndNeedsContinue)
		{
			do
			{
				$request = new Request('GET', $bucket, '', $this->configuration);

				if (!empty($prefix))
				{
					$request->setParameter('prefix', $prefix);
				}

				$request->setParameter('marker', $nextMarker);

				if (!empty($delimiter))
				{
					$request->setParameter('delimiter', $delimiter);
				}

				try
				{
					$response = $request->getResponse();
				}
				catch (\Exception $e)
				{
					break;
				}

				if ($parsedBody && isset($parsedBody->Contents))
				{
					foreach ($parsedBody->Contents as $c)
					{
						$results[(string) $c->Key] = [
							'name' => (string) $c->Key,
							'time' => Date\Gregorian::strtotime((string) $c->LastModified),
							'size' => (int) $c->Size,
							'hash' => substr((string) $c->ETag, 1, -1),
						];

						$nextMarker = (string) $c->Key;
					}
				}

				if ($returnCommonPrefixes && $parsedBody && isset($parsedBody->CommonPrefixes))
				{
					foreach ($parsedBody->CommonPrefixes as $c)
					{
						$results[(string) $c->Prefix] = ['prefix' => (string) $c->Prefix];
					}
				}

				if ($parsedBody && isset($parsedBody->NextMarker))
				{
					$nextMarker = (string) $parsedBody->NextMarker;
				}

				$continueCondition = false;

				if ($isTruncatedAndNoMaxKeys)
				{
					$continueCondition = $isTruncated;
				}

				if ($isTruncatedAndNeedsContinue)
				{
					$continueCondition = $isTruncated && (count($results) < $maxKeys);
				}
			} while ($continueCondition);
		}

		if (!is_null($maxKeys))
		{
			$results = array_splice($results, 0, $maxKeys);
		}

		return $results;
	}

	/**
	 * Get a list of buckets
	 *
	 * @return array{"owner":null|array<int,array{"id":string,"name":string}>,"buckets":array<int,array{"name":string,"time":int}>}
	 */
	public function listBuckets(): array
	{
		// When listing buckets with the AWSv4 signature method we MUST set the region to us-east-1. Don't ask...
		$configuration = clone $this->configuration;
		$configuration->setRegion('us-east-1');

		$request  = new Request('GET', '', '', $configuration);
		$response = $request->getResponse();

		if (!$response->hasValidStatusCode([200])) {
			$e = new CannotListBuckets(
				$response->getStatusCode(),
				sprintf(
					__METHOD__ . "(): Unexpected HTTP status [%s] \n\nDebug info:\nrequest: %s \n",
					$response->getStatusCode(), print_r($request, true)
				)
			);
			$e->setRequest($request);
			$e->setResponse($response);
			throw $e;
		} elseif ($response->isError()) {
			throw CannotListBuckets::fromError($response->getError(), $request, $response);
		}

		/**
		 * @var mixed
		 */
		$result = [
			'owner' => null,
			'buckets' => [],
		];

		$parsedBody = $response->getParsedBody();
		if (empty($parsedBody)) {
			return $result;
		}

		if (isset($parsedBody->Owner, $parsedBody->Owner->ID, $parsedBody->Owner->DisplayName)) {
			$result['owner'] = [
				'id'   => (string) $parsedBody->Owner->ID,
				'name' => (string) $parsedBody->Owner->DisplayName,
			];
		}

		if (isset($parsedBody->Buckets) and $parsedBody->Buckets) {
			foreach ($parsedBody->Buckets->Bucket as $b) {
				$result['buckets'][] = [
					'name' => (string) $b->Name,
					'time' => Date\Gregorian::strtotime((string) $b->CreationDate),
				];
			}
		}
		return $result;
	}

	/**
	 * Start a multipart upload of an object
	 *
	 * @param   Input   $input           Input data
	 * @param   string  $bucket          Bucket name
	 * @param   string  $uri             Object URI
	 * @param   string  $acl             ACL constant
	 * @param   array<string, string>   $requestHeaders  Array of request headers
	 *
	 * @return  string  The upload session ID (UploadId)
	 */
	public function startMultipart(Input $input, string $bucket, string $uri, string $acl = Acl::ACL_PRIVATE, array $requestHeaders = []): ?string
	{
		$request = new Request('POST', $bucket, $uri, $this->configuration);
		$request->setParameter('uploads', '');

		// Custom request headers (Content-Type, Content-Disposition, Content-Encoding)
		if (is_array($requestHeaders))
		{
			foreach ($requestHeaders as $h => $v)
			{
				if (strtolower(substr($h, 0, 6)) == 'x-amz-')
				{
					$request->setAmzHeader(strtolower($h), $v);
				}
				else
				{
					$request->setHeader($h, $v);
				}
			}
		}

		$request->setAmzHeader('x-amz-acl', $acl);

		if (isset($requestHeaders['Content-Type']))
		{
			$input->setType($requestHeaders['Content-Type']);
		}

		$request->setHeader('Content-Type', $input->getType());

		$response = $request->getResponse();

		if (!$response->hasValidStatusCode([200])) {
			$e = new CannotPutFile(
				$response->getStatusCode(),
				sprintf(
					__METHOD__ . "(): Unexpected HTTP status [%s] \n\nDebug info:\nrequest: %s \n",
					$response->getStatusCode(), print_r($request, true)
				)
			);
			$e->setRequest($request);
			$e->setResponse($response);
			throw $e;
		} elseif ($response->isError()) {
			throw CannotPutFile::fromError($response->getError(), $request, $response);
		}

		$parsedBody = $response->getParsedBody();

		return $parsedBody ? (string) $parsedBody->UploadId : null;
	}

	/**
	 * Uploads a part of a multipart object upload
	 *
	 * @param   Input   $input           Input data. You MUST specify the UploadID and PartNumber
	 * @param   string  $bucket          Bucket name
	 * @param   string  $uri             Object URI
	 * @param   array<string, string>   $requestHeaders  Array of request headers or content type as a string
	 * @param   int     $chunkSize       Size of each upload chunk, in bytes. It cannot be less than 5242880 bytes (5Mb)
	 *
	 * @return  null|string  The ETag of the upload part of null if we have ran out of parts to upload
	 */
	public function uploadMultipart(Input $input, string $bucket, string $uri, array $requestHeaders = [], int $chunkSize = 5242880): ?string
	{
		if ($chunkSize < 5242880)
		{
			$chunkSize = 5242880;
		}

		// We need a valid UploadID and PartNumber
		$UploadID   = $input->getUploadID();
		$PartNumber = $input->getPartNumber();

		if (empty($UploadID))
		{
			throw new CannotPutFile(
				0,
				__METHOD__ . '(): No UploadID specified'
			);
		}

		if (empty($PartNumber))
		{
			throw new CannotPutFile(
				0,
				__METHOD__ . '(): No PartNumber specified'
			);
		}

		$UploadID   = urlencode($UploadID);
		$PartNumber = (int) $PartNumber;

		$request = new Request('PUT', $bucket, $uri, $this->configuration);
		$request->setParameter('partNumber', $PartNumber);
		$request->setParameter('uploadId', $UploadID);
		$request->setInput($input);

		// Full data length
		$totalSize = $input->getSize();

		// No Content-Type for multipart uploads
		$input->setType(null);

		// Calculate part offset
		$partOffset = $chunkSize * ($PartNumber - 1);

		if ($partOffset > $totalSize)
		{
			// This is to signify that we ran out of parts ;)
			return null;
		}

		// How many parts are there?
		$totalParts = floor($totalSize / $chunkSize);

		if ($totalParts * $chunkSize < $totalSize)
		{
			$totalParts++;
		}

		// Calculate Content-Length
		$size = $chunkSize;

		if ($PartNumber >= $totalParts)
		{
			$size = $totalSize - ($PartNumber - 1) * $chunkSize;
		}

		if ($size <= 0)
		{
			// This is to signify that we ran out of parts ;)
			return null;
		}

		$input->setSize($size);

		switch ($input->getInputType())
		{
			case Input::INPUT_DATA:
				$input->setData(substr($input->getData(), ($PartNumber - 1) * $chunkSize, $input->getSize()));
				break;

			case Input::INPUT_FILE:
				$file = $input->getFile();
				$fp = fopen($file->getPath(), 'r');
				if ($fp === false) {
					throw new Exception("can not open file {$file->getPath()} for read");
				}
				fseek($fp, ($PartNumber - 1) * $chunkSize);
				$data = fread($fp, $size);
				if ($data === false) {
					throw new Exception("can not read file {$file->getPath()}");
				}
				$input->setData($data);
				break;
		}

		// Custom request headers (Content-Type, Content-Disposition, Content-Encoding)
		if (is_array($requestHeaders))
		{
			foreach ($requestHeaders as $h => $v)
			{
				if (strtolower(substr($h, 0, 6)) == 'x-amz-')
				{
					$request->setAmzHeader(strtolower($h), $v);
				}
				else
				{
					$request->setHeader($h, $v);
				}
			}
		}

		$request->setHeader('Content-Length', (string) $input->getSize());

		if ($input->getInputType() === Input::INPUT_DATA)
		{
			$request->setHeader('Content-Type', "application/x-www-form-urlencoded");
		}

		$response = $request->getResponse();

		if (!$response->hasValidStatusCode([200])) {
			$e = new CannotPutFile(
				$response->getStatusCode(),
				sprintf(
					__METHOD__ . "(): Unexpected HTTP status [%s] \n\nDebug info:\nrequest: %s \n",
					$response->getStatusCode(), print_r($request, true)
				)
			);
			$e->setRequest($request);
			$e->setResponse($response);
			throw $e;
		} elseif ($response->isError()) {
			throw CannotPutFile::fromError($response->getError(), $request, $response);
		}

		$parsedBody = $response->getParsedBody();
		if ($parsedBody) {
			// For some moronic reason, trying to multipart upload files on some hosts comes back with a crazy
			// error from Amazon that we need to set Content-Length:5242880,5242880 instead of
			// Content-Length:5242880 which is AGAINST Amazon's documentation. In this case we pass the header
			// 'workaround-broken-content-length' and retry. Whatever.
			if (isset($parsedBody->CanonicalRequest))
			{
				$amazonsCanonicalRequest = (string) $parsedBody->CanonicalRequest;
				$lines                   = explode("\n", $amazonsCanonicalRequest);

				foreach ($lines as $line)
				{
					if (substr($line, 0, 15) != 'content-length:')
					{
						continue;
					}

					[$junk, $stupidAmazonDefinedContentLength] = explode(":", $line);

					if (strpos($stupidAmazonDefinedContentLength, ',') !== false)
					{
						if (!isset($requestHeaders['workaround-broken-content-length']))
						{
							$requestHeaders['workaround-broken-content-length'] = '1';

							// This is required to reset the input size to its default value. If you don't do that
							// only one part will ever be uploaded. Oops!
							$input->setSize(-1);

							return $this->uploadMultipart($input, $bucket, $uri, $requestHeaders, $chunkSize);
						}
					}
				}
			}
		} else {
			throw new CannotPutFile(
				$response->getStatusCode(),
				sprintf(__METHOD__ . "(): Can not parse result, Debug info:\n%s", print_r($request, true))
			);
		}


		// Return the ETag header
		return $response->getHeader('hash');
	}

	/**
	 * Finalizes the multi-part upload. The $input object should contain two keys, etags an array of ETags of the
	 * uploaded parts and UploadID the multipart upload ID.
	 *
	 * @param   Input   $input   The array of input elements
	 * @param   string  $bucket  The bucket where the object is being stored
	 * @param   string  $uri     The key (path) to the object
	 *
	 * @return  void
	 */
	public function finalizeMultipart(Input $input, string $bucket, string $uri): void
	{
		$etags    = $input->getEtags();
		$UploadID = $input->getUploadID();

		if (empty($etags))
		{
			throw new CannotPutFile(
				'0',
				__METHOD__ . '(): No ETags array specified'
			);
		}

		if (empty($UploadID))
		{
			throw new CannotPutFile(
				'0',
				__METHOD__ . '(): No UploadID specified'
			);
		}

		// Create the message
		$message = "<CompleteMultipartUpload>\n";
		$part    = 0;

		foreach ($etags as $etag)
		{
			$part++;
			$message .= "\t<Part>\n\t\t<PartNumber>$part</PartNumber>\n\t\t<ETag>\"$etag\"</ETag>\n\t</Part>\n";
		}

		$message .= "</CompleteMultipartUpload>";

		// Get a request query
		$reqInput = Input::createFromData($message);

		$request = new Request('POST', $bucket, $uri, $this->configuration);
		$request->setParameter('uploadId', $UploadID);
		$request->setInput($reqInput);

		// Do post
		$request->setHeader('Content-Type', 'application/xml'); // Even though the Amazon API doc doesn't mention it, it's required... :(

		$response = $request->getResponse();

		if (!$response->hasValidStatusCode([200])) {
			$e = new CannotPutFile(
				$response->getStatusCode(),
				sprintf(
					__METHOD__ . "(): Unexpected HTTP status [%s] \n\nDebug info:\nrequest: %s \n",
					$response->getStatusCode(), print_r($request, true)
				)
			);
			$e->setRequest($request);
			$e->setResponse($response);
			throw $e;
		} elseif ($response->isError()) {
			throw CannotPutFile::fromError($response->getError(), $request, $response);
		}
	}

	/**
	 * Returns the configuration object
	 *
	 * @return  Configuration
	 */
	public function getConfiguration(): Configuration
	{
		return $this->configuration;
	}
}
