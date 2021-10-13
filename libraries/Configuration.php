<?php
namespace packages\s3_api;

use packages\base\Loader;

/**
 * Holds the Amazon S3 confiugration credentials
 */
class Configuration {

	/**
	 * print credentials data like key and secret, ... in var_dump or print_r
	 */
	protected $printCredentials = false;

	/**
	 * Access Key
	 *
	 * @var string $access
	 */
	protected $access = '';

	/**
	 * Secret Key
	 *
	 * @var string $secret
	 */
	protected $secret = '';

	/**
	 * Security token. This is only required with temporary credentials provisioned by an EC2 instance.
	 *
	 * @var string $token
	 */
	protected $token = '';

	/**
	 * Signature calculation method ('v2' or 'v4')
	 *
	 * @var string $signatureMethod
	 */
	protected $signatureMethod = 'v2';

	/**
	 * AWS region, used for v4 signatures
	 *
	 * @var string $region
	 */
	protected $region = 'us-east-1';

	/**
	 * Should I use SSL (HTTPS) to communicate to Amazon S3?
	 *
	 * @var bool $useSSL
	 */
	protected $useSSL = true;

	/**
	 * Should we use the dualstack URL (which will ship traffic over ipv6 in most cases). For more information on these
	 * endpoints please read https://docs.aws.amazon.com/AmazonS3/latest/dev/dual-stack-endpoints.html
	 *
	 * @var bool $useDualstackUrl
	 */
	protected $useDualstackUrl = false;

	/**
	 * Should I use legacy, path-style access to the bucket? When it's turned off (default) we use virtual hosting style
	 * paths which are RECOMMENDED BY AMAZON per http://docs.aws.amazon.com/AmazonS3/latest/API/APIRest.html
	 *
	 * @var bool $useLegacyPathStyle
	 */
	protected $useLegacyPathStyle = false;

	/**
	 * Amazon S3 endpoint. You can use a custom endpoint with v2 signatures to access third party services which offer
	 * S3 compatibility, e.g. OwnCloud, Google Storage etc.
	 *
	 * @var string $endpoint
	 */
	protected $endpoint = 's3.amazonaws.com';

	/**
	 * Public constructor
	 *
	 * @param string 		$access           Amazon S3 Access Key
	 * @param string  		$secret           Amazon S3 Secret Key
	 * @param string  		$signatureMethod  Signature method (v2 or v4)
	 * @param string|null	$region           Region, only required for v4 signatures
	 * @param string|null	$endpoint           Region, only required for v4 signatures
	 */
	public function __construct(string $access, string $secret, string $signatureMethod = 'v2', ?string $region = null, ?string $endpoint = null) {
		$this->setAccess($access);
		$this->setSecret($secret);
		$this->setSignatureMethod($signatureMethod);
		if ($region !== null) {
			$this->setRegion($region);
		}
		if ($endpoint !== null) {
			$this->setEndpoint($endpoint);
		}
		$this->printCredentials = Loader::isDebug();
	}

	/**
	 * This magic method indicates which properites shown in export of var_dump or print_r
	 *
	 * removes credentials data in dumping by $printCredentials flags status
	 *
	 * @return array
	 */
	public function __debugInfo(): array {
		$properties = get_object_vars($this);
		if (!$this->printCredentials) {
			foreach (['access', 'secret', 'token', 'region', 'endpoint'] as $property) {
				$properties[$property] = '*******';
			}
		}
		return $properties;
	}

	/**
	 * Get the Amazon access key
	 *
	 * @return string
	 */
	public function getAccess(): string {
		return $this->access;
	}

	/**
	 * Set the Amazon access key
	 *
	 * @param string  $access  The access key to set
	 *
	 * @throws Exception\InvalidAccessKey
	 */
	public function setAccess(string $access): void {
		if (empty($access))
		{
			throw new Exception\InvalidAccessKey;
		}

		$this->access = $access;
	}

	/**
	 * Get the Amazon secret key
	 *
	 * @return string
	 */
	public function getSecret(): string {
		return $this->secret;
	}

	/**
	 * Set the Amazon secret key
	 *
	 * @param   string  $secret  The secret key to set
	 *
	 * @throws  Exception\InvalidSecretKey
	 */
	public function setSecret(string $secret): void {
		if (empty($secret))
		{
			throw new Exception\InvalidSecretKey;
		}

		$this->secret = $secret;
	}

	/**
	 * Return the security token. Only for temporary credentials provisioned through an EC2 instance.
	 *
	 * @return  string
	 */
	public function getToken(): string {
		return $this->token;
	}

	/**
	 * Set the security token. Only for temporary credentials provisioned through an EC2 instance.
	 *
	 * @param   string  $token
	 */
	public function setToken(string $token): void {
		$this->token = $token;
	}

	/**
	 * Get the signature method to use
	 *
	 * @return  string
	 */
	public function getSignatureMethod(): string {
		return $this->signatureMethod;
	}

	/**
	 * Set the signature method to use
	 *
	 * @param   string  $signatureMethod  One of v2 or v4
	 *
	 * @throws  Exception\InvalidSignatureMethod
	 */
	public function setSignatureMethod(string $signatureMethod): void {
		$signatureMethod = strtolower($signatureMethod);
		$signatureMethod = trim($signatureMethod);

		if (!in_array($signatureMethod, ['v2', 'v4']))
		{
			throw new Exception\InvalidSignatureMethod;
		}

		// If you switch to v2 signatures we unset the region.
		if ($signatureMethod == 'v2')
		{
			$this->setRegion('');

			/**
			 * If we are using Amazon S3 proper (not a custom endpoint) we have to set path style access to false.
			 * Amazon S3 does not support v2 signatures with path style access at all (it returns an error telling
			 * us to use the virtual hosting endpoint BUCKETNAME.s3.amazonaws.com).
			 */
			if (strpos($this->endpoint, 'amazonaws.com') !== false)
			{
				$this->setUseLegacyPathStyle(false);
			}

		}

		$this->signatureMethod = $signatureMethod;
	}

	/**
	 * Get the Amazon S3 region
	 *
	 * @return  string
	 */
	public function getRegion(): string {
		return $this->region;
	}

	/**
	 * Set the Amazon S3 region
	 *
	 * @param   string  $region
	 */
	public function setRegion(string $region): void {
		/**
		 * You can only leave the region empty if you're using v2 signatures. Anything else gets you an exception.
		 */
		if (empty($region) && ($this->signatureMethod == 'v4'))
		{
			throw new Exception\InvalidRegion;
		}

		/**
		 * Setting a Chinese-looking region force-changes the endpoint but ONLY if you were using the original Amazon S3
		 * endpoint. If you're using a custom endpoint and provide a region with 'cn-' in its name we don't override
		 * your custom endpoint.
		 */
		if (($this->endpoint == 's3.amazonaws.com') && (substr($region, 0, 3) == 'cn-'))
		{
			$this->setEndpoint('amazonaws.com.cn');
		}

		$this->region = $region;
	}

	/**
	 * Is the connection to be made over HTTPS?
	 *
	 * @return  bool
	 */
	public function isSSL(): bool {
		return $this->useSSL;
	}

	/**
	 * Set the connection SSL preference
	 *
	 * @param   bool  $useSSL  True to use HTTPS
	 */
	public function setSSL(bool $useSSL): void {
		$this->useSSL = $useSSL ? true : false;
	}

	/**
	 * Get the Amazon S3 endpoint
	 *
	 * @return  string
	 */
	public function getEndpoint(): string {
		return $this->endpoint;
	}

	/**
	 * Set the Amazon S3 endpoint. Do NOT use a protocol
	 *
	 * @param   string  $endpoint  Custom endpoint, e.g. 's3.example.com' or 'www.example.com/s3api'
	 */
	public function setEndpoint(string $endpoint): void {
		if (stristr($endpoint, '://'))
		{
			throw new Exception\InvalidEndpoint;
		}

		/**
		 * If you set a custom endpoint we have to switch to v2 signatures since our v4 implementation only supports
		 * Amazon endpoints.
		 */
		if ((strpos($endpoint, 'amazonaws.com') === false))
		{
			$this->setSignatureMethod('v2');
		}

		$this->endpoint = $endpoint;
	}

	/**
	 * Should I use legacy, path-style access to the bucket? You should only use it with custom endpoints. Amazon itself
	 * is currently deprecating support for path-style access but has extended the migration date to an unknown
	 * time https://aws.amazon.com/blogs/aws/amazon-s3-path-deprecation-plan-the-rest-of-the-story/
	 *
	 * @return  bool
	 */
	public function getUseLegacyPathStyle(): bool {
		return $this->useLegacyPathStyle;
	}

	/**
	 * Set the flag for using legacy, path-style access to the bucket
	 *
	 * @param   bool  $useLegacyPathStyle
	 */
	public function setUseLegacyPathStyle(bool $useLegacyPathStyle): void {
		$this->useLegacyPathStyle = $useLegacyPathStyle;

		/**
		 * If we are using Amazon S3 proper (not a custom endpoint) we have to set path style access to false.
		 * Amazon S3 does not support v2 signatures with path style access at all (it returns an error telling
		 * us to use the virtual hosting endpoint BUCKETNAME.s3.amazonaws.com).
		 */
		if ((strpos($this->endpoint, 'amazonaws.com') !== false) && ($this->signatureMethod == 'v2'))
		{
			$this->useLegacyPathStyle = false;
		}
	}

	/**
	 * Should we use the dualstack URL (which will ship traffic over ipv6 in most cases). For more information on these
	 * endpoints please read https://docs.aws.amazon.com/AmazonS3/latest/dev/dual-stack-endpoints.html
	 *
	 * @return  bool
	 */
	public function getDualstackUrl(): bool {
		return $this->useDualstackUrl;
	}

	/**
	 * Set the flag for using legacy, path-style access to the bucket
	 *
	 * @param   bool  $useDualstackUrl
	 */
	public function setUseDualstackUrl(bool $useDualstackUrl): void {
		$this->useDualstackUrl = $useDualstackUrl;
	}
}
