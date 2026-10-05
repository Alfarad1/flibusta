<?php
include('../init.php');

$id = intval($_GET['id'] ?? 0);
$fmt = $_GET['fmt'] ?? '';
if ($id <= 0 || !isset(CONVERT_FORMATS[$fmt])) {
	http_response_code(400);
	die('Bad request');
}

$stmt = $dbh->prepare("SELECT b.Title, b.filetype,
	(SELECT CONCAT(an.LastName, ' ', an.FirstName) FROM libavtor a
		JOIN libavtorname an USING(AvtorId) WHERE a.BookId=b.BookId LIMIT 1) author_name
	FROM libbook b WHERE b.BookId=:id");
$stmt->execute([':id' => $id]);
$book = $stmt->fetch();
if (!$book || trim($book->filetype) != 'fb2') {
	http_response_code(404);
	die('Конвертация доступна только для FB2');
}

$stmt = $dbh->prepare("SELECT filename FROM book_zip WHERE :id BETWEEN start_id AND end_id AND usr=0 LIMIT 1");
$stmt->execute([':id' => $id]);
$zip_row = $stmt->fetch();
if (!$zip_row) {
	http_response_code(404);
	die('Файл книги отсутствует в локальном архиве');
}

$cache_dir = ROOT_PATH . 'cache/converted/';
$target = $cache_dir . "$id.$fmt";

if (!is_file($target)) {
	if (!is_dir($cache_dir)) {
		@mkdir($cache_dir, 0775, true);
	}
	// fb2c writes conversion.log into the current directory, so each run gets its own.
	$work = ROOT_PATH . 'cache/tmp/conv_' . bin2hex(random_bytes(6));
	mkdir($work, 0775, true);
	$src = ROOT_PATH . 'flibusta/' . $zip_row->filename . "/$id.fb2";
	$cmd = 'cd ' . escapeshellarg($work) . ' && timeout 180 ' . FB2C_PATH
		. ' convert --to ' . escapeshellarg($fmt) . ' --ow --nodirs '
		. escapeshellarg($src) . ' ' . escapeshellarg($work) . ' 2>&1';
	exec($cmd, $output);
	$result = glob("$work/*.$fmt");
	if ($result) {
		rename($result[0], $target);
	} else {
		error_log("fb2c failed for $id.$fmt: " . implode("\n", array_slice($output, -5)));
	}
	array_map('unlink', glob("$work/*"));
	rmdir($work);
	if (!is_file($target)) {
		http_response_code(500);
		die('Не удалось сконвертировать книгу');
	}
}

$filename = trim("$book->author_name - $book->title") . " $id.$fmt";
$filename = str_replace(['/', '\\', '"'], '_', $filename);
header('Content-Type: ' . CONVERT_FORMATS[$fmt]);
header("Content-Disposition: attachment; filename=\"$id.$fmt\"; filename*=UTF-8''" . rawurlencode($filename));
header('Content-Length: ' . filesize($target));
header('Cache-Control: private, max-age=86400');
readfile($target);
