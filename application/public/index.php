<?php
ob_start();

include("../init.php");
session_start();
decode_gurl($webroot);

$user_name = 'Книжные полки';
if (isset($_GET['login_uuid'])) {
	$_SESSION['user_uuid'] = $_GET['login_uuid'];
}

if (isset($_GET['delete_uuid'])) {
	$uu = $_GET['delete_uuid'];
	$stmt = $dbh->prepare("DELETE FROM fav_users WHERE user_uuid=:uuid");
	$stmt->bindParam(":uuid", $uu);
	$stmt->execute();
	$st = $dbh->prepare("DELETE FROM fav WHERE user_uuid=:uuid");
	$st->bindParam(":uuid", $uu);
	$st->execute();
}

if (isset($_GET['new_uuid'])) {
	$nname = trim($_GET['new_uuid']);
	if ($nname !== '') {
		$stmt = $dbh->prepare("INSERT INTO fav_users (user_uuid, name) VALUES (uuid_generate_v1(), :name)");
		$stmt->bindParam(":name", $nname);
		$stmt->execute();

		$stmt = $dbh->prepare("SELECT user_uuid FROM fav_users WHERE name=:name LIMIT 1");
		$stmt->bindParam(":name", $nname);
		$stmt->execute();
		$r = $stmt->fetch();
		$user_uuid = $r->user_uuid;
		$user_name = $nname;
		$_SESSION['user_uuid'] = $user_uuid;
	}
}

if (isset($_SESSION['user_uuid'])) {
	$user_uuid = $_SESSION['user_uuid'];
	$stmt = $dbh->prepare("SELECT * FROM fav_users WHERE user_uuid=:uuid");
	$stmt->bindParam(":uuid", $user_uuid);
	try {
		$stmt->execute();
		$user = $stmt->fetch();
	} catch (PDOException $e) {
		//
	}
	
	if (isset($user->name)) {
		$user_name = $user->name;

		foreach (array_keys(FAV_COLUMNS) as $type) {
			if (isset($_GET["fav_$type"])) {
				fav_set($user_uuid, $type, intval($_GET["fav_$type"]), true);
			}
			if (isset($_GET["unfav_$type"])) {
				fav_set($user_uuid, $type, intval($_GET["unfav_$type"]), false);
			}
		}
	} else {
		unset($_SESSION['user_uuid']);
		$user_uuid = '';
		$user_name = 'Книжные полки';
	}
} else {
	$user_uuid = '';
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

