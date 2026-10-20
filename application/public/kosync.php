<?php
// Синхронизация прогресса KOReader (kosync), совместимо с koreader-sync-server.
// Снаружи через nginx-external доступен только этот скрипт: KOReader на Kindle работает без VPN.
// Пользователь один: логин и пароль из FLIBUSTA_KOSYNC_USER / FLIBUSTA_KOSYNC_PASSWORD,
// прогресс записывается пользователю сайта FLIBUSTA_KINDLE_USER. Книга находится по хэшу
// файла, выложенного с полки «На Kindle»: на сайте обновляются процент, место и статус.
// Позицию из веб-читалки KOReader получает отсюда же (kosync_from_web, application/kosync.php).
include('../init.php');

function kosync_reply($code, $data) {
	http_response_code($code);
	header('Content-Type: application/json');
	echo json_encode($data ?: new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!preg_match('#/(healthcheck|users/create|users/auth|syncs/progress)(?:/([^/]*))?/?$#', $path, $m)) {
	kosync_reply(404, ['message' => 'Not found']);
}
$route = $m[1];
$arg = $m[2] ?? '';

$cfg = kosync_config();
if (!$cfg) {
	kosync_reply(404, ['message' => 'Not found']);
}
[$login, $password, $uuid] = $cfg;
schema_ensure();

if ($route === 'healthcheck') {
	kosync_reply(200, ['state' => 'OK']);
}
if ($route === 'users/create') {
	// KOReader ждёт 201 или 402 и показывает message
	kosync_reply(402, ['message' => 'Регистрация отключена, войдите с логином из FLIBUSTA_KOSYNC_USER']);
}
$key = strtolower((string)($_SERVER['HTTP_X_AUTH_KEY'] ?? ''));
$user = (string)($_SERVER['HTTP_X_AUTH_USER'] ?? '');
if (!hash_equals(md5($password), $key) || !hash_equals(mb_strtolower($login), mb_strtolower($user))) {
	kosync_reply(401, ['code' => 2001, 'message' => 'Unauthorized']);
}
if ($route === 'users/auth') {
	kosync_reply(200, ['authorized' => 'OK']);
}

$invalid = ['code' => 2003, 'message' => 'Invalid request'];
if ($method === 'GET' && $arg !== '') {
	if (!preg_match('/^[0-9a-f]{32}$/i', $arg)) {
		kosync_reply(400, $invalid);
	}
	$stmt = $dbh->prepare("SELECT document, progress, percentage, device, device_id,
		extract(epoch FROM updated)::bigint AS timestamp FROM kosync_progress WHERE user_uuid=:u AND document=:d");
	$stmt->execute([':u' => $uuid, ':d' => strtolower($arg)]);
	$p = $stmt->fetch(PDO::FETCH_ASSOC);
	if ($p) {
		$p['percentage'] = (float)$p['percentage'];
		$p['timestamp'] = (int)$p['timestamp'];
	}
	kosync_reply(200, $p ?: []);
}
if ($method !== 'PUT' || $arg !== '') {
	kosync_reply(405, $invalid);
}

$body = json_decode(file_get_contents('php://input', false, null, 0, 65536), true);
$document = strtolower((string)($body['document'] ?? ''));
$progress = $body['progress'] ?? null;
$percentage = $body['percentage'] ?? null;
if (!preg_match('/^[0-9a-f]{32}$/', $document) || !is_scalar($progress) || (string)$progress === ''
	|| !is_numeric($percentage) || $percentage < 0 || $percentage > 1) {
	kosync_reply(400, $invalid);
}
$percentage = (float)$percentage;
$bookid = kosync_bookid($uuid, $document);

$stmt = $dbh->prepare("INSERT INTO kosync_progress (user_uuid, document, bookid, progress, percentage, device, device_id)
	VALUES (:u, :d, :b, :p, :pct, :dev, :devid)
	ON CONFLICT (user_uuid, document) DO UPDATE SET bookid=EXCLUDED.bookid, progress=EXCLUDED.progress,
		percentage=EXCLUDED.percentage, device=EXCLUDED.device, device_id=EXCLUDED.device_id, updated=now()
	RETURNING extract(epoch FROM updated)::bigint");
$stmt->execute([
	':u' => $uuid, ':d' => $document, ':b' => $bookid,
	':p' => mb_substr((string)$progress, 0, 1000), ':pct' => $percentage,
	':dev' => mb_substr((string)($body['device'] ?? ''), 0, 100), ':devid' => mb_substr((string)($body['device_id'] ?? ''), 0, 100),
]);
$timestamp = (int)$stmt->fetchColumn();
if ($bookid) {
	kosync_site_progress($uuid, $bookid, $percentage, (string)$progress);
}
kosync_reply(200, ['document' => $document, 'timestamp' => $timestamp]);
