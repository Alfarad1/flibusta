<?php
// Добавление в избранное и удаление из него без перезагрузки страницы (js/fav.js)
include('../init.php');
session_start();
header('Content-Type: application/json; charset=utf-8');

$type = $_POST['type'] ?? '';
$id = intval($_POST['id'] ?? 0);
$on = ($_POST['on'] ?? '') === '1';
user_init();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset(FAV_COLUMNS[$type]) || $id <= 0) {
	http_response_code(400);
	die(json_encode(['error' => 'bad request']));
}

if ($user_uuid === '') {
	http_response_code(403);
	die(json_encode(['error' => 'not logged in']));
}

fav_set($user_uuid, $type, $id, $on);
echo json_encode(['fav' => $on]);
