<?php
// Статус книги и полки без перезагрузки страницы (js/marks.js)
include('../init.php');
session_start();
user_init();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(405);
	die(json_encode(['error' => 'POST only']));
}
if ($user_uuid === '') {
	http_response_code(403);
	die(json_encode(['error' => 'not logged in']));
}
$bookid = intval($_POST['bookid'] ?? 0);
if ($bookid <= 0) {
	http_response_code(400);
	die(json_encode(['error' => 'bad request']));
}

if (isset($_POST['status'])) {
	status_set($user_uuid, $bookid, intval($_POST['status']));
} elseif (isset($_POST['shelf'])) {
	$shelf = shelf_get(intval($_POST['shelf']));
	if (!$shelf || $shelf->user_uuid !== $user_uuid) {
		http_response_code(403);
		die(json_encode(['error' => 'not your shelf']));
	}
	shelf_set($shelf->id, $bookid, ($_POST['on'] ?? '') === '1');
} else {
	http_response_code(400);
	die(json_encode(['error' => 'bad request']));
}

user_marks($user_uuid, true);
[$st, $inherited] = book_status_of($bookid);
[$cls, $html, $title] = status_button_view($bookid);
echo json_encode([
	'status' => $inherited ? 0 : $st,
	'shelves' => user_marks($user_uuid)['shelves'][$bookid] ?? [],
	'button' => ['class' => $cls, 'html' => $html, 'title' => $title],
	'badge' => status_badge_inner($bookid),
]);
