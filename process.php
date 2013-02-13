<?php

require 'vendor/autoload.php';

use S3hoist\Process;

ini_set('error_reporting', E_ALL);

/* Settings */
define('BUCKET', 'your_bucket');
define('S3_URL', 'http://' . BUCKET . '.s3.amazonaws.com');
define('SERVER_URL', 'http://www.yourdomain.com');
define('AWS_ACCESS_KEY', 'your_aws_access_key');
define('AWS_SECRET_KEY', 'your_aws_secret_key');

new Process(AWS_ACCESS_KEY, AWS_SECRET_KEY);
