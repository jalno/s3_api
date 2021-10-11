<?php
namespace packages\aws_s3_api;

use packages\base\{Date, HTTP, IO\File};
use packages\aws_s3_api\Response\Error;

class Request
{
	/**
	 * The HTTP verb to use
	 *
	 * @var  string
	 */
	private $verb = 'GET';

	/**
	 * The bucket we are using
	 *
	 * @var  string
	 */
	private $bucket = '';

	/**
	 * The object URI, relative to the bucket's root
	 *
	 * @var  string
	 */
	private $uri = '';

	/**
	 * The remote resource we are querying
	 *
	 * @var  string
	 */
	private $resource = '';

	/**
	 * Query string parameters
	 *
	 * @var  array
	 */
	private $parameters = [];

	/**
	 * Amazon-specific headers to pass to the request
	 *
	 * @var  array
	 */
	private $amzHeaders = [];

	/**
	 * Regular HTTP headers to send in the request
	 *
	 * @var  array
	 */
	private $headers = [
		'Host'         => '',
		'Date'         => '',
		'Content-MD5'  => '',
		'Content-Type' => '',
	];

	/**
	 * Input data for the request
	 *
	 * @var  Input
	 */
	private $input = null;

	/**
	 * The file resource we are writing data to
	 *
	 * @var File\Local $file
	 */
	private $file = null;

	/**
	 * The Amazon S3 configuration object
	 *
	 * @var Configuration
	 */
	private $configuration = null;

	/**
	 * The response object
	 *
	 * @var  Response
	 */
	private $response = null;

	/**
	 * The location of the CA certificate cache. It can be a file or a directory.
	 *
	 * @var  string|null
	 */
	private $caCertLocation = null;

	/**
	 * Constructor
	 *
	 * @param   string         $verb           HTTP verb, e.g. 'POST'
	 * @param   string         $bucket         Bucket name, e.g. 'example-bucket'
	 * @param   string         $uri            Object URI
	 * @param   Configuration  $configuration  The Amazon S3 configuration object to use
	 *
	 * @return  void
	 */
	public function __construct(string $verb, string $bucket, string $uri, Configuration $configuration)
	{
		$this->verb          = $verb;
		$this->bucket        = $bucket;
		$this->uri           = '/';
		$this->configuration = $configuration;

		if (!empty($uri))
		{
			$this->uri = '/' . str_replace('%2F', '/', rawurlencode($uri));
		}

		$this->headers['Host'] = $this->getHostName($configuration, $this->bucket);
		$this->resource        = $this->uri;

		if (($this->bucket !== '') && $configuration->getUseLegacyPathStyle())
		{
			$this->resource = '/' . $this->bucket . $this->uri;

			$this->uri = $this->resource;
		}

		// The date must always be added as a header
		$this->headers['Date'] = gmdate('D, d M Y H:i:s O');

		// If there is a security token we need to set up the X-Amz-Security-Token header
		$token = $this->configuration->getToken();

		if (!empty($token))
		{
			$this->setAmzHeader('x-amz-security-token', $token);
		}

	}

	/**
	 * Get the input object
	 *
	 * @return  Input|null
	 */
	public function getInput(): ?Input
	{
		return $this->input;
	}

	/**
	 * Set the input object
	 *
	 * @param   Input  $input
	 *
	 * @return  void
	 */
	public function setInput(Input $input): void
	{
		$this->input = $input;
	}

	/**
	 * Set a request parameter
	 *
	 * @param   string       $key    The parameter name
	 * @param   string|int|null  $value  The parameter value
	 *
	 * @return  void
	 */
	public function setParameter(string $key, $value): void
	{
		$this->parameters[$key] = $value;
	}

	/**
	 * Set a request header
	 *
	 * @param   string  $key    The header name
	 * @param   string|int  $value  The header value
	 *
	 * @return  void
	 */
	public function setHeader(string $key, $value): void
	{
		$this->headers[$key] = $value;
	}

	/**
	 * Set an x-amz-meta-* header
	 *
	 * @param   string  $key    The header name
	 * @param   string  $value  The header value
	 *
	 * @return  void
	 */
	public function setAmzHeader(string $key, string $value): void
	{
		$this->amzHeaders[$key] = $value;
	}

	/**
	 * Get the HTTP verb of this request
	 *
	 * @return  string
	 */
	public function getVerb(): string
	{
		return $this->verb;
	}

	/**
	 * Get the S3 bucket's name
	 *
	 * @return  string
	 */
	public function getBucket(): string
	{
		return $this->bucket;
	}

	/**
	 * Get the absolute URI of the resource we're accessing
	 *
	 * @return  string
	 */
	public function getResource(): string
	{
		return $this->resource;
	}

	/**
	 * Get the parameters array
	 *
	 * @return  array
	 */
	public function getParameters(): array
	{
		return $this->parameters;
	}

	/**
	 * Get the Amazon headers array
	 *
	 * @return  array
	 */
	public function getAmzHeaders(): array
	{
		return $this->amzHeaders;
	}

	/**
	 * Get the other headers array
	 *
	 * @return  array
	 */
	public function getHeaders(): array
	{
		return $this->headers;
	}

	/**
	 * Get a reference to the Amazon configuration object
	 *
	 * @return  Configuration
	 */
	public function getConfiguration(): Configuration
	{
		return $this->configuration;
	}

	/**
	 * Get the file pointer resource (for PUT and POST requests)
	 *
	 * @return  File\Local|null
	 */
	public function getFile(): ?File\Local
	{
		return $this->file;
	}

	/**
	 * Set the data resource as a file pointer
	 *
	 * @param File\Local $file
	 */
	public function setFile(?File\Local $file = null): void
	{
		$this->file = $file;
	}

	/**
	 * Get the certificate authority location
	 *
	 * @return  string|null
	 */
	public function getCaCertLocation(): ?string
	{
		return $this->caCertLocation ?: null;
	}

	/**
	 * @param   null|string  $caCertLocation
	 */
	public function setCaCertLocation(?string $caCertLocation): void
	{
		if (empty($caCertLocation))
		{
			$caCertLocation = null;
		}

		if (!is_null($caCertLocation) && !is_file($caCertLocation) && !is_dir($caCertLocation))
		{
			$caCertLocation = null;
		}

		$this->caCertLocation = $caCertLocation;
	}

	/**
	 * Get a pre-signed URL for the request.
	 *
	 * Typically used to pre-sign GET requests to objects, i.e. give shareable pre-authorized URLs for downloading
	 * private or otherwise inaccessible files from S3.
	 *
	 * @param   int|null  $lifetime  Lifetime in seconds
	 * @param   bool      $https     Use HTTPS ($hostBucket should be false for SSL verification)?
	 *
	 * @return  string  The authenticated URL, complete with signature
	 */
	public function getAuthenticatedURL(?int $lifetime = null, bool $https = false): string
	{
		$this->processParametersIntoResource();
		$signer = Signature::getSignatureObject($this, $this->configuration->getSignatureMethod());

		return $signer->getAuthenticatedURL($lifetime, $https);
	}

	/**
	 * Get the S3 response
	 *
	 * @return  Response
	 */
	public function getResponse(): Response
	{
		$this->processParametersIntoResource();

		$schema = $this->configuration->isSSL() ? 'https://' : 'http://';

		// Very special case. IF the URI ends in /?location AND the region is us-east-1 (Host is
		// s3-external-1.amazonaws.com) THEN the host MUST become s3.amazonaws.com for the request to work. This is case
		// of us not knowing the region of the bucket, therefore having to use a special endpoint which lets us query
		// the region of the bucket without knowing its region. See
		// http://stackoverflow.com/questions/27091816/retrieve-buckets-objects-without-knowing-buckets-region-with-aws-s3-rest-api
		if ((substr($this->uri, -10) == '/?location') && ($this->headers['Host'] == 's3-external-1.amazonaws.com'))
		{
			$this->headers['Host'] = 's3.amazonaws.com';
		}

		$requestParams = [
			'curl_options' => [],
			'headers' => [],
			'allow_redirects' => true,
			'base_uri' => $schema . $this->headers['Host'],
		];

		$file = $this->getFile();
		if ($file) {
			$requestParams['save_as'] = $file;
		}

		$signer = Signature::getSignatureObject($this, $this->configuration->getSignatureMethod());
		$signer->preProcessHeaders($this->headers, $this->amzHeaders);

		$requestParams['headers'] = [
			'Authorization' => $signer->getAuthorizationHeader(),
		];
		foreach (array_merge($this->headers, $this->amzHeaders) as $header => $value) {
			if (strlen($value) > 0) {
				$requestParams['headers'][$header] = $value;
			}
		}

		if ($this->configuration->isSSL())
		{
			/**
			 * Verify the host name in the certificate and the certificate itself.
			 *
			 * Caveat: if your bucket contains dots in the name we have to turn off host verification due to the way the
			 * S3 SSL certificates are set up.
			 */
			$isAmazonS3  = (substr($this->headers['Host'], -14) == '.amazonaws.com') ||
				substr($this->headers['Host'], -16) == 'amazonaws.com.cn';
			$tooManyDots = substr_count($this->headers['Host'], '.') > 4;

			$requestParams['ssl_verify'] = $isAmazonS3 and $tooManyDots;
		}

		if (in_array(strtoupper($this->verb), ['PUT', 'POST'])) {
			if (!($this->input instanceof Input)) {
				$this->input = new Input();
			}
			$size = $this->input->getSize();
			$type = $this->input->getInputType();

			if ($type == Input::INPUT_DATA) {
				$data = $this->input->getDataReference();
				if (strlen($data)) {
					$requestParams['body'] = $data;
				}
			} else {
				$file = $this->input->getFile();
				if ($file) {
					$requestParams['body'] = $file;
				}
			}
		} elseif (in_array(strtoupper($this->verb), ['HEAD', 'DELETE'])) {
			$requestParams['curl_options'][CURLOPT_NOBODY] = true;
		}

		$httpResponse = null;
		try {
			$httpResponse = (new HTTP\Client($requestParams))->request(
				$this->verb,
				$this->uri
			);
		} catch (HTTP\ResponseException $e) {
			$httpResponse = $e->getResponse();
		}

		$this->response = new Response(
			$httpResponse->getStatusCode(),
			$httpResponse->getHeaders()
		);
		$this->response->setBody('');
		$this->response->setPrimaryIP($httpResponse->getPrimaryIP());

		$file = $httpResponse->getFile();
		if ($file) {
			$this->response->setFile($file);
		} else {
			$body = $httpResponse->getBody();
			if ($body) {
				$this->response->setBody($body);
			}
		}
		$this->response->getParsedBody();
		foreach ($this->response->getHeaders() as $header => $value) {
			$header = strtolower($header);
			if ($header == 'last-modified') {
				$this->response->setHeader('time', Date::strtotime($value));
			} elseif ($header == 'content-length') {
				$this->response->setHeader('size', (string) $value);
			} elseif ($header == 'content-type') {
				$this->response->setHeader('type', $value);
			} elseif ($header == 'etag') {
				$this->response->setHeader(
					'hash',
					((is_array($value) and isset($value[0]) and $value[0] == '"') ? substr($value[0], 1, -1) : $value)
				);
			} else {
				$this->response->setHeader(strtolower($header), is_numeric($value) ? (int) $value : $value);

				if (preg_match('/^x-amz-meta-.*$/', $header)) {
					$this->setHeader($header, is_numeric($value) ? (int) $value : $value);
				}
			}
		}

		return $this->response;
	}

	/**
	 * Processes $this->parameters as a query string into $this->resource
	 *
	 * @return  void
	 */
	private function processParametersIntoResource(): void
	{
		if (count($this->parameters))
		{
			$query = substr($this->uri, -1) !== '?' ? '?' : '&';

			ksort($this->parameters);

			foreach ($this->parameters as $var => $value)
			{
				if ($value == null || $value == '')
				{
					$query .= $var . '&';
				}
				else
				{
					// Parameters must be URL-encoded
					$query .= $var . '=' . rawurlencode($value) . '&';
				}
			}

			$query     = substr($query, 0, -1);
			$this->uri .= $query;

			if (array_key_exists('acl', $this->parameters) or
				array_key_exists('location', $this->parameters) or
				array_key_exists('torrent', $this->parameters) or
				array_key_exists('logging', $this->parameters) or
				array_key_exists('uploads', $this->parameters) or
				array_key_exists('uploadId', $this->parameters) or
				array_key_exists('partNumber', $this->parameters)
			)
			{
				$this->resource .= $query;
			}
		}
	}

	/**
	 * Get the region-specific hostname for an operation given a configuration and a bucket name. This ensures we can
	 * always use an HTTPS connection, even with buckets containing dots in their names, without SSL certificate host
	 * name validation issues.
	 *
	 * Please note that this requires the pathStyle flag to be set in Configuration because Amazon RECOMMENDS using the
	 * virtual-hosted style request where applicable. See http://docs.aws.amazon.com/AmazonS3/latest/API/APIRest.html
	 * Quoting this documentation:
	 * "Although the path-style is still supported for legacy applications, we recommend using the virtual-hosted style
	 * where applicable."
	 *
	 * @param   Configuration  $configuration
	 * @param   string         $bucket
	 *
	 * @return  string
	 */
	private function getHostName(Configuration $configuration, string $bucket): string
	{
		// http://docs.aws.amazon.com/general/latest/gr/rande.html#s3_region
		$endpoint = $configuration->getEndpoint();
		$region   = $configuration->getRegion();

		// If it's a bucket in China we need to use a different endpoint
		if (($endpoint == 's3.amazonaws.com') && (substr($region, 0, 3) == 'cn-'))
		{
			$endpoint = 'amazonaws.com.cn';
		}

		/**
		 * If there is no bucket we use the default endpoint, whatever it is. For Amazon S3 this format is only used
		 * when we are making account-level, cross-region requests, e.g. list all buckets. For S3-compatible APIs it
		 * depends on the API, but generally it's just for listing available buckets.
		 */
		if (empty($bucket))
		{
			return $endpoint;
		}

		/**
		 * Are we using v2 signatures? In this case we use the endpoint defined by the user without translating it.
		 */
		if ($configuration->getSignatureMethod() != 'v4')
		{
			// Legacy path style: the hostname is the endpoint
			if ($configuration->getUseLegacyPathStyle())
			{
				return $endpoint;
			}

			// Virtual hosting style: the hostname is the bucket, dot and endpoint.
			return $bucket . '.' . $endpoint;
		}

		/**
		 * When using the Amazon S3 with the v4 signature API we have to use a different hostname per region. The
		 * mapping can be found in https://docs.aws.amazon.com/general/latest/gr/s3.html#s3_region
		 *
		 * This means changing the endpoint to s3.REGION.amazonaws.com with the following exceptions:
		 * For China: s3.REGION.amazonaws.com.cn
		 *
		 * v4 signing does NOT support non-Amazon endpoints.
		 */

		// Most endpoints: s3-REGION.amazonaws.com
		$regionalEndpoint = $region . '.amazonaws.com';

		// Exception: China
		if (substr($region, 0, 3) == 'cn-')
		{
			// Chinese endpoint, e.g.: s3.cn-north-1.amazonaws.com.cn
			$regionalEndpoint = $regionalEndpoint . '.cn';
		}

		// If dual-stack URLs are enabled then prepend the endpoint
		if ($configuration->getDualstackUrl())
		{
			$endpoint = 's3.dualstack.' . $regionalEndpoint;
		}
		else
		{
			$endpoint = 's3.' . $regionalEndpoint;
		}

		// Legacy path style access: return just the endpoint
		if ($configuration->getUseLegacyPathStyle())
		{
			return $endpoint;
		}

		// Recommended virtual hosting access: bucket, dot, endpoint.
		return $bucket . '.' . $endpoint;
	}
}
