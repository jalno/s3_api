<?php
namespace packages\s3_api;

/**
 * Shortcuts to often used access control privileges
 */
class Acl
{
	const ACL_PRIVATE = 'private';

	const ACL_PUBLIC_READ = 'public-read';

	const ACL_PUBLIC_READ_WRITE = 'public-read-write';

	const ACL_AUTHENTICATED_READ = 'authenticated-read';

	const ACL_BUCKET_OWNER_READ = 'bucket-owner-read';

	const ACL_BUCKET_OWNER_FULL_CONTROL = 'bucket-owner-full-control';
}
