<?php
ob_start();

include("../init.php");
session_start();
decode_gurl($webroot);

user_init();

// Без JS кнопки «в избранное» — обычные ссылки ?fav_book=ID / ?unfav_book=ID
if ($user_uuid !== '') {
	foreach (array_keys(FAV_COLUMNS) as $type) {
		if (isset($_GET["fav_$type"])) {
			fav_set($user_uuid, $type, intval($_GET["fav_$type"]), true);
		}
		if (isset($_GET["unfav_$type"])) {
			fav_set($user_uuid, $type, intval($_GET["unfav_$type"]), false);
		}
	}
}

if (isset($_GET['page'])) {
	$page = max(0, intval($_GET['page']));
} else {
	$page = 0;
}

$start = $page * RECORDS_PAGE;
$lang = 'ru';
$filter = "";

if ($url->mod == 'opds') {
	include(ROOT_PATH . "/opds/index.php");
} else {
	include(ROOT_PATH . "renderer.php");
}

