<?php
// Позиция чтения FB2 (в процентах прокрутки) для текущего пользователя
include('../init.php');
session_start();
user_init();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $user_uuid === '') {
	http_response_code($user_uuid === '' ? 403 : 405);
	die();
}
$bookid = intval($_POST['bookid'] ?? 0);
$pos = floatval($_POST['pos'] ?? 0);
if ($bookid <= 0) {
	http_response_code(400);
	die();
}

if ($pos <= 0) {
	$stmt = $dbh->prepare("DELETE FROM progress WHERE user_uuid=:uuid AND bookid=:id");
	$stmt->execute([':uuid' => $user_uuid, ':id' => $bookid]);
	die();
}

$stmt = $dbh->prepare("INSERT INTO progress (user_uuid, bookid, pos) VALUES (:uuid, :id, :pos)
	ON CONFLICT (user_uuid, bookid) DO UPDATE SET pos=EXCLUDED.pos");
$stmt->execute([':uuid' => $user_uuid, ':id' => $bookid, ':pos' => min($pos, 100)]);
