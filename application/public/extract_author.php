<?php
include('../init.php');
header('Cache-Control: public, max-age=86400');

function lastm($path) {
	$fmtimestamp = filemtime($path);
	if(isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && $fmtimestamp <= strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
		header($_SERVER['SERVER_PROTOCOL'] . ' 304 Not Modified');
		die();
	} else {
		header("Expires: " . gmdate("D, d M Y H:i:s", filemtime($path) + 60*60*24) . " GMT");
		header("Last-Modified: " . gmdate("D, d M Y H:i:s", filemtime($path)) . " GMT");

		readfile($path);
	}
}

$id = intval($_GET['id'] ?? 0);

header("Content-type: image/jpeg");

if (file_exists(ROOT_PATH . "cache/authors/$id.jpg")) {
	lastm(ROOT_PATH . "cache/authors/$id.jpg");
	die();
}

$stmt = $dbh->prepare("SELECT file FROM libapics WHERE AvtorId=:id LIMIT 1");
$stmt->execute([':id' => $id]);
$f = $stmt->fetch();

$attached = ROOT_PATH . "cache/lib.a.attached.zip";
if (isset($f->file) && is_file($attached)) {
	$zip = new ZipArchive();
	if ($zip->open($attached) === true) {
		$data = $zip->getFromName($f->file);
		$zip->close();
		if ($data !== false && strlen($data) > 0) {
			@file_put_contents(ROOT_PATH . "cache/authors/$id.jpg", $data);
			echo $data;
			die();
		}
	}
}

header("Content-type: image/png");
readfile(ROOT_PATH . 'public/i/default_avatar.png');
