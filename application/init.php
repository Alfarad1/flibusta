<?php
define('ROOT_PATH', '/application/');
define('RECORDS_PAGE', 10);
define('BOOKS_PAGE', 10);
define('AUTHORS_PAGE', 50);
define('SERIES_PAGE', 50);
define('OPDS_FEED_COUNT', 100);
define('COUNT_BOOKS', true);
define('FB2C_PATH', '/opt/fb2c/fb2c');
define('CONVERT_FORMATS', [
	'epub' => 'application/epub+zip',
	'azw3' => 'application/x-mobi8-ebook',
	'mobi' => 'application/x-mobipocket-ebook',
]);
include(ROOT_PATH . 'functions.php');
include(ROOT_PATH . 'dbinit.php');
include_once(ROOT_PATH . 'webroot.php');
session_set_cookie_params(3600 * 24 * 31 * 12,"/");
#session_start();

error_reporting(E_ALL);

$cdt = date('Y-m-d H:i:s');

