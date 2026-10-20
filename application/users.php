<?php
// Пользователи. Сайт для своих, поэтому без паролей: вход — выбор имени,
// пользователь запоминается в cookie на год. Таблица fav_users осталась
// от «книжных полок», user_uuid — её ключ, на него же ссылаются fav и progress.

const USER_COOKIE = 'flibusta_user';
const USER_NAME_MAX = 64;

$user_uuid = '';
$user_name = '';

function is_uuid($s) {
	return is_string($s) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s);
}

function user_get($uuid) {
	global $dbh;
	if (!is_uuid($uuid)) {
		return null;
	}
	$stmt = $dbh->prepare("SELECT * FROM fav_users WHERE user_uuid=:uuid");
	$stmt->execute([':uuid' => $uuid]);
	return $stmt->fetch() ?: null;
}

function user_list() {
	global $dbh;
	return $dbh->query("SELECT * FROM fav_users ORDER BY lower(name)")->fetchAll();
}

// Определяет текущего пользователя по cookie и заполняет $user_uuid, $user_name.
// Раньше пользователь («полка») хранился в сессии — такой вход переносится в cookie.
function user_init() {
	global $user_uuid, $user_name;
	schema_ensure();
	$uuid = $_COOKIE[USER_COOKIE] ?? '';
	if ($uuid === '' && session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_uuid'])) {
		$uuid = $_SESSION['user_uuid'];
		unset($_SESSION['user_uuid']);
		if ($u = user_get($uuid)) {
			user_login($u->user_uuid);
		}
	}
	$u = user_get($uuid);
	if ($u) {
		$user_uuid = $u->user_uuid;
		$user_name = $u->name;
	} elseif ($uuid !== '') {
		user_logout();
	}
}

function user_cookie($value, $expires) {
	setcookie(USER_COOKIE, $value, [
		'expires' => $expires,
		'path' => '/',
		'httponly' => true,
		'samesite' => 'Lax',
	]);
}

function user_login($uuid) {
	global $user_uuid, $user_name;
	$u = user_get($uuid);
	if (!$u) {
		return false;
	}
	user_cookie($u->user_uuid, time() + 3600 * 24 * 365);
	$_COOKIE[USER_COOKIE] = $u->user_uuid;
	$user_uuid = $u->user_uuid;
	$user_name = $u->name;
	return true;
}

function user_logout() {
	global $user_uuid, $user_name;
	user_cookie('', time() - 3600);
	unset($_COOKIE[USER_COOKIE]);
	$user_uuid = '';
	$user_name = '';
}

// Проверка имени: возвращает текст ошибки или null
function user_name_error($name, $except_uuid = '') {
	global $dbh;
	if ($name === '') {
		return 'Введите имя';
	}
	if (mb_strlen($name) > USER_NAME_MAX) {
		return 'Слишком длинное имя (не больше ' . USER_NAME_MAX . ' символов)';
	}
	$stmt = $dbh->prepare("SELECT 1 FROM fav_users WHERE lower(name)=lower(:name) AND user_uuid::text<>:uuid");
	$stmt->execute([':name' => $name, ':uuid' => $except_uuid]);
	if ($stmt->fetchColumn() !== false) {
		return "Имя «{$name}» уже занято";
	}
	return null;
}

// Создаёт пользователя; возвращает [uuid, null] или [null, ошибка]
function user_create($name) {
	global $dbh;
	$name = trim($name);
	if ($err = user_name_error($name)) {
		return [null, $err];
	}
	$stmt = $dbh->prepare("INSERT INTO fav_users (user_uuid, name) VALUES (gen_random_uuid(), :name) RETURNING user_uuid");
	$stmt->execute([':name' => $name]);
	return [$stmt->fetchColumn(), null];
}

function user_rename($uuid, $name) {
	global $dbh;
	$name = trim($name);
	if ($err = user_name_error($name, $uuid)) {
		return $err;
	}
	$stmt = $dbh->prepare("UPDATE fav_users SET name=:name WHERE user_uuid=:uuid");
	$stmt->execute([':name' => $name, ':uuid' => $uuid]);
	return null;
}

function user_delete($uuid) {
	global $dbh;
	$dbh->beginTransaction();
	foreach (['fav', 'progress', 'book_status', 'kindle_file', 'shelf', 'fav_users'] as $t) {
		$stmt = $dbh->prepare("DELETE FROM $t WHERE user_uuid=:uuid");
		$stmt->execute([':uuid' => $uuid]);
	}
	$dbh->commit();
}
