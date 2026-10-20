<?php
// Полка «На Kindle»: книги с неё выкладываются в папку WebDAV (например, Seafile),
// откуда их забирает KOReader. Читалка одна, поэтому всё задаётся переменными
// окружения; пока они не заданы, полки нет. Полка появляется только у пользователя
// FLIBUSTA_KINDLE_USER. Файлы кладутся как есть: KOReader сам читает FB2, EPUB, PDF и т. д.

const KINDLE_SHELF_NAME = 'На Kindle';
// форматы, которые открывает KOReader
const KINDLE_FORMATS = ['fb2', 'epub', 'pdf', 'djvu', 'djv', 'mobi', 'txt', 'rtf', 'doc', 'docx', 'html', 'htm', 'chm'];

// Настройки или null, если выгрузка не настроена
function kindle_config() {
	static $cfg = false;
	if ($cfg === false) {
		$env = fn($k) => trim((string)getenv($k));
		$password = $env('FLIBUSTA_WEBDAV_PASSWORD');
		if ($password === '' && ($f = $env('FLIBUSTA_WEBDAV_PASSWORD_FILE')) !== '' && is_readable($f)) {
			$password = trim(file_get_contents($f));
		}
		$cfg = [
			'user' => $env('FLIBUSTA_KINDLE_USER'),
			'url' => rtrim($env('FLIBUSTA_WEBDAV_URL'), '/'),
			'login' => $env('FLIBUSTA_WEBDAV_USER'),
			'password' => $password,
			'dir' => trim($env('FLIBUSTA_WEBDAV_DIR') ?: 'Kindle', '/'),
		];
		if ($cfg['user'] === '' || $cfg['url'] === '' || $cfg['login'] === '' || $cfg['password'] === '') {
			$cfg = null;
		}
	}
	return $cfg;
}

// id полки «На Kindle» пользователя или 0, если у него её нет.
// Создаётся при первом обращении; полка с таким именем, если была, становится ею.
function kindle_shelf_id($uuid) {
	global $dbh;
	static $cache = [];
	$cfg = kindle_config();
	if (!$cfg || $uuid === '') {
		return 0;
	}
	if (!isset($cache[$uuid])) {
		$u = user_get($uuid);
		$id = 0;
		if ($u && mb_strtolower($u->name) === mb_strtolower($cfg['user'])) {
			$stmt = $dbh->prepare("SELECT id FROM shelf WHERE user_uuid=:uuid AND kindle");
			$stmt->execute([':uuid' => $uuid]);
			$id = $stmt->fetchColumn();
			if ($id === false) {
				$stmt = $dbh->prepare("UPDATE shelf SET kindle=true WHERE user_uuid=:uuid AND lower(name)=lower(:name) AND NOT fav RETURNING id");
				$stmt->execute([':uuid' => $uuid, ':name' => KINDLE_SHELF_NAME]);
				$id = $stmt->fetchColumn();
			}
			if ($id === false) {
				$stmt = $dbh->prepare("INSERT INTO shelf (user_uuid, name, kindle) VALUES (:uuid, :name, true)
					ON CONFLICT DO NOTHING RETURNING id");
				$stmt->execute([':uuid' => $uuid, ':name' => KINDLE_SHELF_NAME]);
				$id = $stmt->fetchColumn();
			}
		}
		$cache[$uuid] = (int)$id;
	}
	return $cache[$uuid];
}

// Файл книги из архива: [содержимое, расширение] или [null, текст ошибки]
function kindle_book_file($bookid) {
	global $dbh;
	$stmt = $dbh->prepare("SELECT b.filetype, f.filename FROM libbook b LEFT JOIN libfilename f USING(bookid) WHERE b.bookid=:id");
	$stmt->execute([':id' => $bookid]);
	$book = $stmt->fetch();
	if (!$book) {
		return [null, 'Книга не найдена'];
	}
	$ext = strtolower(trim($book->filetype));
	if (!in_array($ext, KINDLE_FORMATS)) {
		return [null, "Формат $ext KOReader не открывает"];
	}
	// FB2 лежат в архивах usr=0 под именем id.fb2, остальные — в usr=1 (как в fb2.php и usr.php)
	$usr = $ext === 'fb2' ? 0 : 1;
	$stmt = $dbh->prepare("SELECT filename FROM book_zip WHERE :id BETWEEN start_id AND end_id AND usr=:usr LIMIT 1");
	$stmt->execute([':id' => $bookid, ':usr' => $usr]);
	$zip_name = $stmt->fetchColumn();
	$zip = new ZipArchive();
	if (!$zip_name || $zip->open(ROOT_PATH . "flibusta/" . $zip_name) !== true) {
		return [null, 'Файла книги нет в локальном архиве'];
	}
	$name = $usr ? ($book->filename ?: "$bookid.$ext") : "$bookid.fb2";
	$data = $zip->getFromName($name);
	$zip->close();
	if ($data === false) {
		return [null, 'Файла книги нет в локальном архиве'];
	}
	return [$data, $ext];
}

// Имя файла «Автор - Название.ext» без символов, запрещённых в FAT32 (файловая система Kindle).
// Если имя занято другой книгой, добавляется её номер.
function kindle_file_name($bookid, $ext) {
	global $dbh;
	$stmt = $dbh->prepare("SELECT b.title,
		(SELECT CONCAT(an.lastname, ' ', an.firstname) FROM libavtor a
			JOIN libavtorname an USING(avtorid) WHERE a.bookid=b.bookid LIMIT 1) author
		FROM libbook b WHERE b.bookid=:id");
	$stmt->execute([':id' => $bookid]);
	$b = $stmt->fetch();
	$clean = fn($s) => trim(preg_replace('/\s+/u', ' ', preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]/u', ' ', (string)$s)), ' .');
	$author = $clean($b->author ?? '');
	$title = mb_substr($clean($b->title ?? ''), 0, 100) ?: (string)$bookid;
	$base = $author !== '' ? mb_substr($author, 0, 60) . " - $title" : $title;
	$name = "$base.$ext";
	$stmt = $dbh->prepare("SELECT 1 FROM kindle_file WHERE path=:p AND bookid<>:id");
	$stmt->execute([':p' => $name, ':id' => $bookid]);
	if ($stmt->fetchColumn() !== false) {
		$name = "$base ($bookid).$ext";
	}
	return $name;
}

// Запрос к WebDAV; путь — относительно FLIBUSTA_WEBDAV_URL, без кодирования.
// Возвращает HTTP-код или 0, если сервер недоступен.
function kindle_dav($method, $path, $body = null) {
	$cfg = kindle_config();
	$url = $cfg['url'] . '/' . implode('/', array_map('rawurlencode', explode('/', $path)));
	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_CUSTOMREQUEST => $method,
		CURLOPT_USERPWD => $cfg['login'] . ':' . $cfg['password'],
		CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_TIMEOUT => 120,
	]);
	if ($body !== null) {
		curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/octet-stream']);
	}
	curl_exec($ch);
	$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	if ($code === 0) {
		error_log("kindle: $method $url: " . curl_error($ch));
	}
	curl_close($ch);
	return $code;
}

function kindle_dav_error($code) {
	return match (true) {
		$code === 0 => 'WebDAV-сервер недоступен',
		$code === 401, $code === 403 => 'WebDAV не принял логин или пароль',
		$code === 507 => 'На WebDAV-сервере кончилось место',
		default => "WebDAV-сервер ответил ошибкой $code",
	};
}

// Создаёт папку для книг вместе с родительскими (405 — папка уже есть)
function kindle_dav_mkdir() {
	$path = '';
	foreach (explode('/', kindle_config()['dir']) as $part) {
		$path .= ($path === '' ? '' : '/') . $part;
		$code = kindle_dav('MKCOL', $path);
		if ($code >= 300 && $code !== 405) {
			return $code;
		}
	}
	return 201;
}

// Хэш, по которому KOReader узнаёт файл («Binary» в синхронизации прогресса, util.partialMD5):
// MD5 кусков по 1024 байта со смещений 0, 1024, 4096, … 1024·4^10, пока смещение внутри файла
function koreader_partial_md5($data) {
	$ctx = hash_init('md5');
	for ($i = -1; $i <= 10; $i++) {
		$off = $i < 0 ? 0 : 1024 << (2 * $i);
		if ($off >= strlen($data)) {
			break;
		}
		hash_update($ctx, substr($data, $off, 1024));
	}
	return hash_final($ctx);
}

// Выкладывает книгу; возвращает null или текст ошибки
function kindle_upload($uuid, $bookid) {
	global $dbh;
	[$data, $ext] = kindle_book_file($bookid);
	if ($data === null) {
		return $ext;
	}
	$stmt = $dbh->prepare("SELECT path FROM kindle_file WHERE user_uuid=:u AND bookid=:b");
	$stmt->execute([':u' => $uuid, ':b' => $bookid]);
	$name = $stmt->fetchColumn() ?: kindle_file_name($bookid, $ext);
	$path = kindle_config()['dir'] . "/$name";
	$code = kindle_dav('PUT', $path, $data);
	if ($code === 409 || $code === 404) {
		// нет папки
		$mk = kindle_dav_mkdir();
		$code = $mk < 300 ? kindle_dav('PUT', $path, $data) : $mk;
	}
	if ($code < 200 || $code >= 300) {
		return 'Не удалось выложить книгу: ' . kindle_dav_error($code);
	}
	$stmt = $dbh->prepare("INSERT INTO kindle_file (user_uuid, bookid, path, md5_file, md5_name) VALUES (:u, :b, :p, :mf, :mn)
		ON CONFLICT (user_uuid, bookid) DO UPDATE SET path=EXCLUDED.path, md5_file=EXCLUDED.md5_file,
			md5_name=EXCLUDED.md5_name, uploaded=now()");
	$stmt->execute([':u' => $uuid, ':b' => $bookid, ':p' => $name, ':mf' => koreader_partial_md5($data), ':mn' => md5($name)]);
	return null;
}

// Убирает книгу из папки; возвращает null или текст ошибки
function kindle_remove($uuid, $bookid) {
	global $dbh;
	$stmt = $dbh->prepare("SELECT path FROM kindle_file WHERE user_uuid=:u AND bookid=:b");
	$stmt->execute([':u' => $uuid, ':b' => $bookid]);
	$name = $stmt->fetchColumn();
	if ($name !== false) {
		$code = kindle_dav('DELETE', kindle_config()['dir'] . "/$name");
		if (($code < 200 || $code >= 300) && $code !== 404) {
			return 'Не удалось убрать книгу: ' . kindle_dav_error($code);
		}
		$stmt = $dbh->prepare("DELETE FROM kindle_file WHERE user_uuid=:u AND bookid=:b");
		$stmt->execute([':u' => $uuid, ':b' => $bookid]);
	}
	return null;
}
