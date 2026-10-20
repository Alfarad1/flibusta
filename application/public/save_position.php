<?php
// Позиция чтения текущего пользователя: pos — процент прочитанного,
// loc — точное место (для FB2 «p:абзац:доля», для EPUB — CFI).
// Если у книги ещё нет статуса или он «Хочу прочитать», ставится «Читаю».
// Если книга выложена на Kindle, позицию получит и KOReader (kosync).
include('../init.php');
session_start();
user_init();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $user_uuid === '') {
	http_response_code($user_uuid === '' ? 403 : 405);
	die();
}
$bookid = intval($_POST['bookid'] ?? 0);
$pos = floatval($_POST['pos'] ?? 0);
$loc = substr(trim($_POST['loc'] ?? ''), 0, 1000);
if ($bookid <= 0) {
	http_response_code(400);
	die();
}

if ($pos <= 0 && $loc === '') {
	$stmt = $dbh->prepare("DELETE FROM progress WHERE user_uuid=:uuid AND bookid=:id");
	$stmt->execute([':uuid' => $user_uuid, ':id' => $bookid]);
	die();
}

$stmt = $dbh->prepare("INSERT INTO progress (user_uuid, bookid, pos, loc) VALUES (:uuid, :id, :pos, :loc)
	ON CONFLICT (user_uuid, bookid) DO UPDATE SET pos=EXCLUDED.pos, loc=EXCLUDED.loc, updated=now()");
$stmt->execute([':uuid' => $user_uuid, ':id' => $bookid, ':pos' => max(0, min($pos, 100)), ':loc' => $loc === '' ? null : $loc]);

$stmt = $dbh->prepare("INSERT INTO book_status (user_uuid, bookid, status) VALUES (:u, :b, " . STATUS_READING . ")
	ON CONFLICT (user_uuid, bookid) DO UPDATE SET status=EXCLUDED.status, updated=now()
	WHERE book_status.status=1");
$stmt->execute([':u' => $user_uuid, ':b' => $bookid]);

kosync_from_web($user_uuid, $bookid, $pos, $loc);
