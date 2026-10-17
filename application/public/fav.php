<?php
// Добавление в избранное и удаление из него без перезагрузки страницы (js/fav.js)
include('../init.php');
session_start();
header('Content-Type: application/json; charset=utf-8');

$type = $_POST['type'] ?? '';
$id = intval($_POST['id'] ?? 0);
$on = ($_POST['on'] ?? '') === '1';
$user_uuid = $_SESSION['user_uuid'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset(FAV_COLUMNS[$type]) || $id <= 0) {
	http_response_code(400);
	die(json_encode(['error' => 'bad request']));
}

$stmt = $dbh->prepare("SELECT 1 FROM fav_users WHERE user_uuid::text=:uuid");
$stmt->execute([':uuid' => $user_uuid]);
if ($user_uuid === '' || $stmt->fetchColumn() === false) {
	http_response_code(403);
	die(json_encode(['error' => 'no shelf']));
}

fav_set($user_uuid, $type, $id, $on);
echo json_encode(['fav' => $on]);
