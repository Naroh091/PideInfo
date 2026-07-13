<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// `.env` pins APP_ENV=dev, and this bootstrap runs before PHPUnit applies the <server> block
// of phpunit.dist.xml — so without this line every KernelTestCase booted the *dev* kernel
// (dev database, dev config, no test.service_container). Pinning it here is what makes
// .env.test / .env.test.local actually apply.
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
