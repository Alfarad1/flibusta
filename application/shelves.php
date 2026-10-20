<?php
// Полки и статусы книг. Полок у пользователя сколько угодно, книга может
// лежать на нескольких; «Избранное» — полка с fav=true, она есть у каждого
// и её нельзя удалить (кнопка с сердцем). Статус у книги один на пользователя.

const BOOK_STATUSES = [
	// код => [название, иконка, класс кнопки]
	1 => ['Хочу прочитать', 'fas fa-bookmark', 'btn-info'],
	2 => ['Читаю', 'fas fa-book-open', 'btn-success'],
	3 => ['Прочитано', 'fas fa-check', 'btn-warning'],
	4 => ['Бросил', 'fas fa-times', 'btn-secondary'],
];
const STATUS_READING = 2;
const STATUS_READ = 3;
const SHELF_NAME_MAX = 64;
const SHELF_PAGE = 24;

function shelf_get($id) {
	global $dbh;
	$stmt = $dbh->prepare("SELECT s.*, u.name user_name FROM shelf s JOIN fav_users u USING(user_uuid) WHERE s.id=:id");
	$stmt->execute([':id' => $id]);
	return $stmt->fetch() ?: null;
}

// Полки пользователя: сначала «Избранное» и «На Kindle», потом по имени; cnt — число книг
function user_shelves($uuid) {
	global $dbh;
	static $cache = [];
	if (!isset($cache[$uuid])) {
		shelf_fav_id($uuid);
		kindle_shelf_id($uuid);
		$stmt = $dbh->prepare("SELECT s.*, (SELECT COUNT(*) FROM shelf_book sb WHERE sb.shelf_id=s.id) cnt
			FROM shelf s WHERE user_uuid=:uuid ORDER BY fav DESC, kindle DESC, lower(name)");
		$stmt->execute([':uuid' => $uuid]);
		$cache[$uuid] = $stmt->fetchAll();
	}
	return $cache[$uuid];
}

// id полки «Избранное»; у новых пользователей она создаётся при первом обращении
function shelf_fav_id($uuid) {
	global $dbh;
	static $cache = [];
	if (!isset($cache[$uuid])) {
		$stmt = $dbh->prepare("SELECT id FROM shelf WHERE user_uuid=:uuid AND fav");
		$stmt->execute([':uuid' => $uuid]);
		$id = $stmt->fetchColumn();
		if ($id === false) {
			$stmt = $dbh->prepare("INSERT INTO shelf (user_uuid, name, fav) VALUES (:uuid, 'Избранное', true)
				ON CONFLICT DO NOTHING RETURNING id");
			$stmt->execute([':uuid' => $uuid]);
			$id = $stmt->fetchColumn();
		}
		$cache[$uuid] = (int)$id;
	}
	return $cache[$uuid];
}

function shelf_name_error($uuid, $name, $except_id = 0) {
	global $dbh;
	if ($name === '') {
		return 'Введите название полки';
	}
	if (mb_strlen($name) > SHELF_NAME_MAX) {
		return 'Слишком длинное название (не больше ' . SHELF_NAME_MAX . ' символов)';
	}
	$stmt = $dbh->prepare("SELECT 1 FROM shelf WHERE user_uuid=:uuid AND lower(name)=lower(:name) AND id<>:id");
	$stmt->execute([':uuid' => $uuid, ':name' => $name, ':id' => $except_id]);
	if ($stmt->fetchColumn() !== false) {
		return "Полка «{$name}» уже есть";
	}
	return null;
}

// Создаёт полку; возвращает [id, null] или [null, ошибка]
function shelf_create($uuid, $name) {
	global $dbh;
	$name = trim($name);
	if ($err = shelf_name_error($uuid, $name)) {
		return [null, $err];
	}
	$stmt = $dbh->prepare("INSERT INTO shelf (user_uuid, name) VALUES (:uuid, :name) RETURNING id");
	$stmt->execute([':uuid' => $uuid, ':name' => $name]);
	return [(int)$stmt->fetchColumn(), null];
}

function shelf_rename($shelf, $name) {
	global $dbh;
	$name = trim($name);
	if ($err = shelf_name_error($shelf->user_uuid, $name, $shelf->id)) {
		return $err;
	}
	$dbh->prepare("UPDATE shelf SET name=:name WHERE id=:id")->execute([':name' => $name, ':id' => $shelf->id]);
	return null;
}

// Иконка полки: у «Избранного» и «На Kindle» свои
function shelf_icon($shelf) {
	return $shelf->fav ? 'fas fa-heart' : ($shelf->kindle ? 'fas fa-tablet-alt' : 'fas fa-layer-group');
}

// «Избранное» и «На Kindle» нельзя переименовать и удалить
// (если выгрузку на Kindle отключили, её полка становится обычной)
function shelf_fixed($shelf) {
	return $shelf->fav || ($shelf->kindle && kindle_config());
}

function shelf_delete($shelf) {
	global $dbh;
	if (!shelf_fixed($shelf)) {
		$dbh->prepare("DELETE FROM shelf WHERE id=:id")->execute([':id' => $shelf->id]);
	}
}

function shelf_set($shelf_id, $bookid, $on) {
	global $dbh;
	if ($on) {
		$stmt = $dbh->prepare("INSERT INTO shelf_book (shelf_id, bookid) VALUES (:s, :b) ON CONFLICT DO NOTHING");
	} else {
		$stmt = $dbh->prepare("DELETE FROM shelf_book WHERE shelf_id=:s AND bookid=:b");
	}
	$stmt->execute([':s' => $shelf_id, ':b' => $bookid]);
}

// Статус 0 — снять статус
function status_set($uuid, $bookid, $status) {
	global $dbh;
	if (!isset(BOOK_STATUSES[$status])) {
		$stmt = $dbh->prepare("DELETE FROM book_status WHERE user_uuid=:u AND bookid=:b");
		$stmt->execute([':u' => $uuid, ':b' => $bookid]);
		return;
	}
	$stmt = $dbh->prepare("INSERT INTO book_status (user_uuid, bookid, status) VALUES (:u, :b, :s)
		ON CONFLICT (user_uuid, bookid) DO UPDATE SET status=EXCLUDED.status, updated=now()");
	$stmt->execute([':u' => $uuid, ':b' => $bookid, ':s' => $status]);
}

// Отметки пользователя для карточек: статусы и полки всех его книг загружаются
// одним запросом на страницу, а не отдельно для каждой карточки.
// status[bookid] = [статус, из другого издания?], shelves[bookid] = [id полок].
function user_marks($uuid, $reset = false) {
	global $dbh;
	static $cache = [];
	if ($uuid === '') {
		return ['status' => [], 'shelves' => []];
	}
	if ($reset || !isset($cache[$uuid])) {
		$m = ['status' => [], 'shelves' => []];
		if (db_has_table('book_editions')) {
			// статус, поставленный одному изданию, виден и на остальных изданиях книги
			$stmt = $dbh->prepare("SELECT e2.bookid, s.status FROM book_status s
				JOIN book_editions e1 ON e1.bookid=s.bookid
				JOIN book_editions e2 ON e2.workid=e1.workid AND e2.bookid<>s.bookid
				WHERE s.user_uuid=:u ORDER BY s.updated");
			$stmt->execute([':u' => $uuid]);
			foreach ($stmt as $r) {
				$m['status'][$r->bookid] = [(int)$r->status, true];
			}
		}
		$stmt = $dbh->prepare("SELECT bookid, status FROM book_status WHERE user_uuid=:u");
		$stmt->execute([':u' => $uuid]);
		foreach ($stmt as $r) {
			$m['status'][$r->bookid] = [(int)$r->status, false];
		}
		$stmt = $dbh->prepare("SELECT sb.bookid, sb.shelf_id FROM shelf_book sb JOIN shelf s ON s.id=sb.shelf_id WHERE s.user_uuid=:u");
		$stmt->execute([':u' => $uuid]);
		foreach ($stmt as $r) {
			$m['shelves'][$r->bookid][] = (int)$r->shelf_id;
		}
		$cache[$uuid] = $m;
	}
	return $cache[$uuid];
}

function book_status_of($bookid) {
	global $user_uuid;
	return user_marks($user_uuid)['status'][$bookid] ?? [0, false];
}

function book_on_shelf($bookid, $shelf_id) {
	global $user_uuid;
	return in_array($shelf_id, user_marks($user_uuid)['shelves'][$bookid] ?? []);
}

// Кнопка статуса на карточке: иконка и цвет — по статусу
function status_button_view($bookid) {
	[$st, $inherited] = book_status_of($bookid);
	if (!$st) {
		return ['btn-outline-secondary', "<i class='far fa-bookmark'></i>", 'Статус и полки'];
	}
	[$label, $icon, $cls] = BOOK_STATUSES[$st];
	return [$inherited ? str_replace('btn-', 'btn-outline-', $cls) : $cls, "<i class='$icon'></i>",
		$label . ($inherited ? ' (отмечено в другом издании)' : '')];
}

// Статус книги текстом (на странице книги и в результатах поиска).
// Обёртка выводится всегда, чтобы js/marks.js мог обновить её содержимое.
function status_badge($bookid) {
	return "<div class='status-badge' data-status-badge='$bookid'>" . status_badge_inner($bookid) . "</div>";
}

function status_badge_inner($bookid) {
	[$st, $inherited] = book_status_of($bookid);
	if (!$st) {
		return '';
	}
	[$label, $icon, $cls] = BOOK_STATUSES[$st];
	$bg = str_replace('btn-', 'bg-', $cls) . ($cls === 'btn-warning' ? ' text-dark' : '');
	return "<span class='badge $bg mb-1'><i class='$icon'></i> $label" . ($inherited ? ' — в другом издании' : '') . "</span>";
}

// Меню «статус и полки» на карточке книги (переключается в js/marks.js)
function marks_menu($bookid) {
	global $user_uuid, $webroot;
	if ($user_uuid === '') {
		return '';
	}
	[$st, $inherited] = book_status_of($bookid);
	[$cls, $html, $title] = status_button_view($bookid);
	$check = "<i class='fas fa-check mark-check'></i>";
	$s = "<div class='btn-group' role='group' data-marks='$bookid'>";
	$s .= "<button type='button' class='btn btn-sm $cls mark-button' data-bs-toggle='dropdown' data-bs-auto-close='outside'"
		. " aria-expanded='false' title='" . h($title) . "'>$html</button>";
	$s .= "<ul class='dropdown-menu dropdown-menu-end marks-menu'>";
	$s .= "<li><h6 class='dropdown-header'>Статус</h6></li>";
	foreach (BOOK_STATUSES + [0 => ['Без статуса', '', '']] as $code => [$label]) {
		$on = $code === ($inherited ? 0 : $st) ? ' mark-on' : '';
		$s .= "<li><a class='dropdown-item$on' href='#' data-status='$code'>$check $label</a></li>";
	}
	$s .= "<li><hr class='dropdown-divider'></li><li><h6 class='dropdown-header'>Полки</h6></li>";
	foreach (user_shelves($user_uuid) as $sh) {
		if ($sh->fav) {
			continue;
		}
		$on = book_on_shelf($bookid, $sh->id) ? ' mark-on' : '';
		$icon = $sh->kindle ? " <i class='" . shelf_icon($sh) . " text-muted'></i>" : '';
		$s .= "<li><a class='dropdown-item$on' href='#' data-shelf='$sh->id'>$check " . h($sh->name) . "$icon</a></li>";
	}
	$s .= "<li><a class='dropdown-item text-muted' href='$webroot/fav/#new-shelf'><i class='fas fa-plus'></i> Новая полка…</a></li>";
	$s .= "</ul></div>";
	return $s;
}

// Книги для страницы полки или списка статуса
const SHELF_SORTS = [
	'added' => ['Добавлены недавно', 'x.added DESC, b.bookid DESC'],
	'abc' => ['По названию', 'b.title, b.bookid'],
	'year' => ['По году издания', 'NULLIF(b.year, 0) DESC NULLS LAST, b.bookid DESC'],
];

// Заголовок с выбором сортировки и листалкой для страниц полок
function shelf_books_html($from_sql, $params, $sort, $page) {
	global $dbh, $webroot;
	$order = SHELF_SORTS[$sort][1] ?? SHELF_SORTS['added'][1];
	$stmt = $dbh->prepare("SELECT COUNT(*) FROM $from_sql");
	$stmt->execute($params);
	$cnt = (int)$stmt->fetchColumn();
	$stmt = $dbh->prepare("SELECT b.* FROM $from_sql ORDER BY $order LIMIT " . SHELF_PAGE . " OFFSET " . ($page * SHELF_PAGE));
	$stmt->execute($params);
	$books = $stmt->fetchAll();

	$cur = isset(SHELF_SORTS[$sort]) ? $sort : 'added';
	echo "<div class='d-flex align-items-center mb-2'>";
	echo "<span class='badge bg-primary me-2'>Книг: $cnt</span>";
	echo "<div class='btn-group'><button type='button' class='btn btn-sm btn-outline-secondary dropdown-toggle' data-bs-toggle='dropdown' aria-expanded='false' title='Сортировка'>";
	echo "<i class='fas fa-sort-amount-down'></i> " . SHELF_SORTS[$cur][0] . "</button><ul class='dropdown-menu'>";
	foreach (SHELF_SORTS as $k => [$label]) {
		$active = $k === $cur ? ' active' : '';
		echo "<li><a class='dropdown-item$active' href='?" . h(http_build_query(['sort' => $k] + array_intersect_key($_GET, ['user' => 1]))) . "'>$label</a></li>";
	}
	echo "</ul></div></div>";

	if (!$books) {
		echo "<div class='alert alert-secondary'>Здесь пока пусто.</div>";
		return;
	}
	show_gpager(ceil($cnt / SHELF_PAGE), 5);
	echo "<div class='row equal'>";
	foreach ($books as $b) {
		book_small_pg($b, $webroot);
	}
	echo "</div>";
	show_gpager(ceil($cnt / SHELF_PAGE), 5);
}

// Форматы, которые открываются в читалке (modules/read)
const READABLE_FORMATS = ['fb2', 'epub', 'txt', 'pdf', 'mobi', 'djvu', 'djv', 'rtf', 'docx', 'html', 'htm'];

// Кнопка «Читать» / «Продолжить» на странице книги
function read_button($book) {
	global $dbh, $user_uuid, $webroot;
	if (!$book || !in_array(strtolower(trim($book->filetype)), READABLE_FORMATS) || !is_book_available($book->bookid)) {
		return '';
	}
	$pos = 0;
	if ($user_uuid !== '') {
		$stmt = $dbh->prepare("SELECT pos FROM progress WHERE user_uuid=:u AND bookid=:b");
		$stmt->execute([':u' => $user_uuid, ':b' => $book->bookid]);
		$pos = (float)$stmt->fetchColumn();
	}
	$label = $pos > 0 ? 'Продолжить чтение · ' . floor($pos) . '%' : 'Читать';
	return "<div class='mb-3'><a class='btn btn-success btn-lg' href='$webroot/read/$book->bookid'><i class='fas fa-book-reader'></i> $label</a></div>";
}

// «Продолжить чтение»: книги со статусом «Читаю», последние прочитанные первыми
function continue_reading($uuid, $limit = 6) {
	global $dbh;
	$stmt = $dbh->prepare("SELECT b.*, p.pos FROM book_status s
		JOIN libbook b USING(bookid)
		LEFT JOIN progress p ON p.user_uuid=s.user_uuid AND p.bookid=s.bookid
		WHERE s.user_uuid=:u AND s.status=" . STATUS_READING . "
		ORDER BY GREATEST(s.updated, p.updated) DESC, b.bookid DESC LIMIT " . intval($limit));
	$stmt->execute([':u' => $uuid]);
	return $stmt->fetchAll();
}

function continue_reading_html($uuid, $title = 'Продолжить чтение', $more = '') {
	global $webroot;
	$books = continue_reading($uuid);
	if (!$books) {
		return;
	}
	echo "<div class='card mb-3'><h5 class='card-header d-flex'>$title";
	if ($more !== '') {
		echo "<a class='ms-auto small' href='$more'>все</a>";
	}
	echo "</h5><div class='card-body pb-0'><div class='row equal'>";
	foreach ($books as $b) {
		$pct = (int)floor($b->pos ?? 0);
		$note = "<div class='w-100 px-1'><div class='progress my-1' style='height: 4px' title='Прочитано $pct%'>"
			. "<div class='progress-bar bg-success' style='width: $pct%'></div></div>";
		if (in_array(strtolower(trim($b->filetype)), READABLE_FORMATS)) {
			$note .= "<a class='btn btn-sm btn-success w-100 mb-1' href='$webroot/read/$b->bookid'><i class='fas fa-book-reader'></i> "
				. ($pct > 0 ? "Продолжить · $pct%" : 'Читать') . "</a>";
		}
		$note .= "</div>";
		book_small_pg($b, $webroot, false, $note);
	}
	echo "</div></div></div>";
}
