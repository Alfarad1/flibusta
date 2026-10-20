<?php
// Синхронизация прогресса с KOReader (kosync, API — public/kosync.php).
// Позиция переводится в обе стороны: для FB2 — номер блока веб-читалки («p:номер:доля»)
// ↔ XPointer crengine (/FictionBook/body[1]/section[2]/p[5]), для EPUB — CFI epub.js
// ↔ XPointer (/body/DocFragment[3]/body/div/p[7]). Точность — до абзаца.

// так позиция с сайта подписана в KOReader («Перейти к 75% с устройства …?»)
const KOSYNC_WEB_DEVICE = 'Сайт';
const KOSYNC_WEB_DEVICE_ID = 'flibusta-web';

// [логин, пароль, uuid пользователя сайта] или null, если не настроено
function kosync_config() {
	global $dbh;
	static $cfg = false;
	if ($cfg === false) {
		$env = fn($k) => trim((string)getenv($k));
		$password = $env('FLIBUSTA_KOSYNC_PASSWORD');
		if ($password === '' && ($f = $env('FLIBUSTA_KOSYNC_PASSWORD_FILE')) !== '' && is_readable($f)) {
			$password = trim(file_get_contents($f));
		}
		$login = $env('FLIBUSTA_KOSYNC_USER');
		$name = $env('FLIBUSTA_KINDLE_USER');
		$cfg = null;
		if ($login !== '' && $password !== '' && $name !== '') {
			$stmt = $dbh->prepare("SELECT user_uuid FROM fav_users WHERE lower(name)=lower(:n)");
			$stmt->execute([':n' => $name]);
			$uuid = $stmt->fetchColumn();
			$cfg = $uuid === false ? null : [$login, $password, $uuid];
		}
	}
	return $cfg;
}

// Досчитывает хэши файлов, выложенных до появления kosync ($bookid — только этой книги)
function kosync_fill_hashes($uuid, $bookid = 0) {
	global $dbh;
	$stmt = $dbh->prepare("SELECT bookid, path FROM kindle_file WHERE user_uuid=:u AND md5_file IS NULL"
		. ($bookid ? " AND bookid=" . intval($bookid) : ''));
	$stmt->execute([':u' => $uuid]);
	$set = $dbh->prepare("UPDATE kindle_file SET md5_file=:mf, md5_name=:mn WHERE user_uuid=:u AND bookid=:b");
	foreach ($stmt->fetchAll() as $f) {
		[$data] = kindle_book_file($f->bookid);
		if ($data !== null) {
			$set->execute([':mf' => koreader_partial_md5($data), ':mn' => md5($f->path), ':u' => $uuid, ':b' => $f->bookid]);
		}
	}
}

// Книга по хэшу KOReader
function kosync_bookid($uuid, $document) {
	global $dbh;
	$find = $dbh->prepare("SELECT bookid FROM kindle_file WHERE user_uuid=:u AND (md5_file=:d OR md5_name=:d) LIMIT 1");
	$find->execute([':u' => $uuid, ':d' => $document]);
	$bookid = $find->fetchColumn();
	if ($bookid === false) {
		kosync_fill_hashes($uuid);
		$find->execute([':u' => $uuid, ':d' => $document]);
		$bookid = $find->fetchColumn();
	}
	return $bookid === false ? null : (int)$bookid;
}

function kosync_book_ext($bookid) {
	global $dbh;
	$stmt = $dbh->prepare("SELECT filetype FROM libbook WHERE bookid=:id");
	$stmt->execute([':id' => $bookid]);
	return strtolower(trim((string)$stmt->fetchColumn()));
}

// Позиция из KOReader → место для веб-читалки или null
function kosync_xp_to_loc($bookid, $xp) {
	$ext = kosync_book_ext($bookid);
	if ($ext === 'fb2') {
		return kosync_fb2_loc($bookid, $xp);
	}
	return $ext === 'epub' ? kosync_epub_cfi($bookid, $xp) : null;
}

// Место веб-читалки → позиция для KOReader или null
function kosync_loc_to_xp($bookid, $loc) {
	$ext = kosync_book_ext($bookid);
	if ($ext === 'fb2') {
		return kosync_fb2_xp($bookid, $loc);
	}
	return $ext === 'epub' ? kosync_epub_xp($bookid, $loc) : null;
}

// /a/b[1]/c[2] и /a/b/c[2] — один и тот же адрес: [1] у единственного элемента не пишется
function kosync_xp_key($xp) {
	return str_replace('[1]', '', $xp);
}

/* ---------- FB2 ---------- */

function kosync_fb2_loc($bookid, $xp) {
	$xps = fb2html_xpointers($bookid);
	if (!$xps || !str_starts_with($xp, '/FictionBook/')) {
		return null;
	}
	$index = [];
	foreach ($xps as $i => $x) {
		if ($x !== '' && !isset($index[$k = kosync_xp_key($x)])) {
			$index[$k] = $i;
		}
	}
	// ближайший блок, внутри которого позиция (text().N и вложенные элементы отбрасываются),
	// а если сам элемент не блок (заголовок сноски, раздел) — первый блок внутри него
	$path = kosync_xp_key(preg_replace('#(/text\(\)(\[\d+\])?)?(\.\d+)?$#', '', $xp));
	while ($path !== '' && $path !== '/FictionBook') {
		if (isset($index[$path])) {
			return "p:{$index[$path]}:0";
		}
		foreach ($index as $k => $i) {
			if (str_starts_with($k, "$path/")) {
				return "p:$i:0";
			}
		}
		$path = preg_replace('#/[^/]*$#', '', $path);
	}
	return null;
}

function kosync_fb2_xp($bookid, $loc) {
	if (!preg_match('/^p:(\d+):[\d.]+$/', (string)$loc, $m)) {
		return null;
	}
	$xps = fb2html_xpointers($bookid);
	// обложка и заголовок «Примечания» — не из FB2: берётся следующий блок
	for ($i = (int)$m[1]; $xps && $i < count($xps); $i++) {
		if ($xps[$i] !== '') {
			return $xps[$i];
		}
	}
	return null;
}

/* ---------- EPUB ---------- */

// Главы EPUB в порядке чтения: [[путь в архиве, idref], …] и номер <spine> среди детей <package>
function kosync_epub_spine($zip) {
	$container = new DOMDocument();
	if (!@$container->loadXML((string)$zip->getFromName('META-INF/container.xml'))) {
		return null;
	}
	$rootfile = $container->getElementsByTagName('rootfile')->item(0);
	$opf_path = $rootfile ? $rootfile->getAttribute('full-path') : '';
	$opf = new DOMDocument();
	if ($opf_path === '' || !@$opf->loadXML((string)$zip->getFromName($opf_path))) {
		return null;
	}
	$base = dirname($opf_path) === '.' ? '' : dirname($opf_path) . '/';
	$hrefs = [];
	foreach ($opf->getElementsByTagName('item') as $item) {
		$hrefs[$item->getAttribute('id')] = $base . rawurldecode($item->getAttribute('href'));
	}
	$spine = $opf->getElementsByTagName('spine')->item(0);
	if (!$spine) {
		return null;
	}
	$items = [];
	foreach ($spine->getElementsByTagName('itemref') as $ref) {
		$id = $ref->getAttribute('idref');
		$items[] = [kosync_path_normalize($hrefs[$id] ?? ''), $id];
	}
	return [$items, kosync_element_pos($spine)];
}

function kosync_path_normalize($path) {
	$out = [];
	foreach (explode('/', $path) as $part) {
		if ($part === '..') {
			array_pop($out);
		} elseif ($part !== '.' && $part !== '') {
			$out[] = $part;
		}
	}
	return implode('/', $out);
}

// номер элемента среди элементов-соседей, с 1
function kosync_element_pos($el) {
	$n = 0;
	foreach ($el->parentNode->childNodes as $c) {
		if ($c->nodeType === XML_ELEMENT_NODE) {
			$n++;
			if ($c === $el) {
				return $n;
			}
		}
	}
	return 0;
}

// [DOMDocument главы, idref, номер <spine>] или null
function kosync_epub_chapter($bookid, $n) {
	[$data, $ext] = kindle_book_file($bookid);
	if ($data === null || $ext !== 'epub') {
		return null;
	}
	$tmp = tempnam(sys_get_temp_dir(), 'kosync');
	file_put_contents($tmp, $data);
	$zip = new ZipArchive();
	$r = null;
	if ($zip->open($tmp) === true) {
		$spine = kosync_epub_spine($zip);
		if ($spine && isset($spine[0][$n - 1])) {
			[$path, $idref] = $spine[0][$n - 1];
			$doc = new DOMDocument();
			if (@$doc->loadXML((string)$zip->getFromName($path), LIBXML_NONET | LIBXML_PARSEHUGE)) {
				$r = [$doc, $idref, $spine[1]];
			}
		}
		$zip->close();
	}
	unlink($tmp);
	return $r;
}

// CFI epub.js → XPointer: /body/DocFragment[глава]/body/… — номер у элемента, только
// если у родителя несколько одноимённых (как в crengine)
function kosync_epub_xp($bookid, $loc) {
	if (!preg_match('#^epubcfi\(/\d+/(\d+)(?:\[[^\]]*\])?!((?:/\d+(?:\[[^\]]*\])?)*)#', (string)$loc, $m)) {
		return null;
	}
	$chapter = intdiv((int)$m[1], 2);
	$r = kosync_epub_chapter($bookid, $chapter);
	if (!$r) {
		return null;
	}
	$el = $r[0]->documentElement;
	$path = null;
	preg_match_all('#/(\d+)#', $m[2], $steps);
	foreach ($steps[1] as $step) {
		$step = (int)$step;
		if ($step % 2) {
			break; // текстовый узел — место внутри элемента
		}
		$child = null;
		$n = 0;
		foreach ($el->childNodes as $c) {
			if ($c->nodeType === XML_ELEMENT_NODE && ++$n === $step / 2) {
				$child = $c;
				break;
			}
		}
		if (!$child) {
			break;
		}
		$el = $child;
		if ($path === null) {
			if ($el->localName === 'body') {
				$path = '';
			}
			continue;
		}
		$same = 0;
		$i = 0;
		foreach ($el->parentNode->childNodes as $c) {
			if ($c->nodeType === XML_ELEMENT_NODE && $c->localName === $el->localName) {
				$same++;
				if ($c === $el) {
					$i = $same;
				}
			}
		}
		$path .= '/' . strtolower($el->localName) . ($same > 1 ? "[$i]" : '');
	}
	return "/body/DocFragment[$chapter]/body" . $path;
}

// XPointer → CFI для epub.js (без смещения в тексте — начало абзаца)
function kosync_epub_cfi($bookid, $xp) {
	if (!preg_match('#^/body/DocFragment\[(\d+)\]/body((?:/[a-zA-Z][\w-]*(?:\[\d+\])?)*)#', $xp, $m)) {
		return null;
	}
	$chapter = (int)$m[1];
	$r = kosync_epub_chapter($bookid, $chapter);
	if (!$r) {
		return null;
	}
	[$doc, $idref, $spine_pos] = $r;
	$body = null;
	foreach ($doc->documentElement->childNodes as $c) {
		if ($c->nodeType === XML_ELEMENT_NODE && $c->localName === 'body') {
			$body = $c;
		}
	}
	if (!$body) {
		return null;
	}
	$cfi = '/' . 2 * kosync_element_pos($body);
	$el = $body;
	preg_match_all('#/([a-zA-Z][\w-]*)(?:\[(\d+)\])?#', $m[2], $segs, PREG_SET_ORDER);
	foreach ($segs as $seg) {
		$want = (int)($seg[2] ?? 0) ?: 1;
		$found = null;
		$same = 0;
		foreach ($el->childNodes as $c) {
			if ($c->nodeType === XML_ELEMENT_NODE && strtolower($c->localName) === strtolower($seg[1]) && ++$same === $want) {
				$found = $c;
				break;
			}
		}
		if (!$found) {
			break;
		}
		$el = $found;
		$cfi .= '/' . 2 * kosync_element_pos($el);
	}
	return 'epubcfi(/' . 2 * $spine_pos . '/' . 2 * $chapter . '[' . $idref . ']!' . $cfi . ')';
}

/* ---------- сайт → KOReader ---------- */

// Веб-читалка сохранила позицию: если книга выложена на Kindle, KOReader увидит её
// как прогресс с устройства «Сайт» и предложит перейти туда
function kosync_from_web($uuid, $bookid, $pos, $loc) {
	global $dbh;
	$cfg = kosync_config();
	if (!$cfg || $cfg[2] !== $uuid || $loc === '') {
		return;
	}
	kosync_fill_hashes($uuid, $bookid);
	$stmt = $dbh->prepare("SELECT md5_file, md5_name FROM kindle_file WHERE user_uuid=:u AND bookid=:b AND md5_file IS NOT NULL");
	$stmt->execute([':u' => $uuid, ':b' => $bookid]);
	$f = $stmt->fetch();
	if (!$f) {
		return;
	}
	$xp = kosync_loc_to_xp($bookid, $loc);
	if ($xp === null) {
		return;
	}
	$stmt = $dbh->prepare("INSERT INTO kosync_progress (user_uuid, document, bookid, progress, percentage, device, device_id)
		VALUES (:u, :d, :b, :p, :pct, :dev, :devid)
		ON CONFLICT (user_uuid, document) DO UPDATE SET bookid=EXCLUDED.bookid, progress=EXCLUDED.progress,
			percentage=EXCLUDED.percentage, device=EXCLUDED.device, device_id=EXCLUDED.device_id, updated=now()");
	// KOReader ищет книгу по хэшу содержимого или имени файла — в зависимости от настройки
	foreach (array_unique([$f->md5_file, $f->md5_name]) as $doc) {
		$stmt->execute([':u' => $uuid, ':d' => $doc, ':b' => $bookid, ':p' => $xp,
			':pct' => max(0, min(1, $pos / 100)), ':dev' => KOSYNC_WEB_DEVICE, ':devid' => KOSYNC_WEB_DEVICE_ID]);
	}
}

/* ---------- KOReader → сайт ---------- */

// Процент, место и статус на сайте: «Читаю», как в веб-читалке; в конце книги — «Прочитано»,
// но «Бросил» не трогается
function kosync_site_progress($uuid, $bookid, $percentage, $xp) {
	global $dbh;
	$loc = kosync_xp_to_loc($bookid, $xp);
	$stmt = $dbh->prepare("INSERT INTO progress (user_uuid, bookid, pos, loc) VALUES (:u, :b, :pos, :loc)
		ON CONFLICT (user_uuid, bookid) DO UPDATE SET pos=EXCLUDED.pos, loc=EXCLUDED.loc, updated=now()");
	$stmt->execute([':u' => $uuid, ':b' => $bookid, ':pos' => round($percentage * 100, 2), ':loc' => $loc]);
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
