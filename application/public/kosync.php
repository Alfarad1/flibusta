<?php
// Синхронизация прогресса KOReader (kosync), совместимо с koreader-sync-server.
// Снаружи через nginx-external доступен только этот скрипт: KOReader на Kindle работает без VPN.
// Пользователь один: логин и пароль из FLIBUSTA_KOSYNC_USER / FLIBUSTA_KOSYNC_PASSWORD,
// прогресс записывается пользователю сайта FLIBUSTA_KINDLE_USER. Книга находится по хэшу
// файла, выложенного с полки «На Kindle»: на сайте обновляются процент и статус.
include('../init.php');

function kosync_reply($code, $data) {
	http_response_code($code);
	header('Content-Type: application/json');
	echo json_encode($data ?: new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

// [логин, пароль, uuid пользователя сайта] или null, если не настроено
function kosync_config() {
	global $dbh;
	$env = fn($k) => trim((string)getenv($k));
	$password = $env('FLIBUSTA_KOSYNC_PASSWORD');
	if ($password === '' && ($f = $env('FLIBUSTA_KOSYNC_PASSWORD_FILE')) !== '' && is_readable($f)) {
		$password = trim(file_get_contents($f));
	}
	$login = $env('FLIBUSTA_KOSYNC_USER');
	$name = $env('FLIBUSTA_KINDLE_USER');
	if ($login === '' || $password === '' || $name === '') {
		return null;
	}
	$stmt = $dbh->prepare("SELECT user_uuid FROM fav_users WHERE lower(name)=lower(:n)");
	$stmt->execute([':n' => $name]);
	$uuid = $stmt->fetchColumn();
	return $uuid === false ? null : [$login, $password, $uuid];
}

// Книга по хэшу KOReader; хэши файлов, выложенных до появления kosync, досчитываются здесь
function kosync_bookid($uuid, $document) {
	global $dbh;
	$find = $dbh->prepare("SELECT bookid FROM kindle_file WHERE user_uuid=:u AND (md5_file=:d OR md5_name=:d) LIMIT 1");
	$find->execute([':u' => $uuid, ':d' => $document]);
	$bookid = $find->fetchColumn();
	if ($bookid !== false) {
		return (int)$bookid;
	}
	$stmt = $dbh->prepare("SELECT bookid, path FROM kindle_file WHERE user_uuid=:u AND md5_file IS NULL");
	$stmt->execute([':u' => $uuid]);
	$set = $dbh->prepare("UPDATE kindle_file SET md5_file=:mf, md5_name=:mn WHERE user_uuid=:u AND bookid=:b");
	$found = 0;
	foreach ($stmt->fetchAll() as $f) {
		[$data] = kindle_book_file($f->bookid);
		if ($data === null) {
			continue;
		}
		$mf = koreader_partial_md5($data);
		$mn = md5($f->path);
		$set->execute([':mf' => $mf, ':mn' => $mn, ':u' => $uuid, ':b' => $f->bookid]);
		if ($mf === $document || $mn === $document) {
			$found = (int)$f->bookid;
		}
	}
	return $found ?: null;
}

// Процент и статус на сайте: «Читаю», как в веб-читалке; в конце книги — «Прочитано»,
// но «Бросил» не трогается
function kosync_site_progress($uuid, $bookid, $percentage) {
	global $dbh;
	$stmt = $dbh->prepare("INSERT INTO progress (user_uuid, bookid, pos, loc) VALUES (:u, :b, :pos, NULL)
		ON CONFLICT (user_uuid, bookid) DO UPDATE SET pos=EXCLUDED.pos, loc=NULL, updated=now()");
	$stmt->execute([':u' => $uuid, ':b' => $bookid, ':pos' => round($percentage * 100, 2)]);
	if ($percentage >= 0.99) {
		$stmt = $dbh->prepare("INSERT INTO book_status (user_uuid, bookid, status) VALUES (:u, :b, " . STATUS_READ . ")
			ON CONFLICT (user_uuid, bookid) DO UPDATE SET status=EXCLUDED.status, updated=now()
			WHERE book_status.status IN (1, " . STATUS_READING . ")");
	} elseif ($percentage > 0) {
		$stmt = $dbh->prepare("INSERT INTO book_status (user_uuid, bookid, status) VALUES (:u, :b, " . STATUS_READING . ")
			ON CONFLICT (user_uuid, bookid) DO UPDATE SET status=EXCLUDED.status, updated=now()
			WHERE book_status.status=1");
	} else {
		return;
	}
	$stmt->execute([':u' => $uuid, ':b' => $bookid]);
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
	kosync_site_progress($uuid, $bookid, $percentage);
}
kosync_reply(200, ['document' => $document, 'timestamp' => $timestamp]);
