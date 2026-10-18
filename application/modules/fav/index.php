<?php
// Полки пользователя: /fav/ — обзор, /fav/shelf/ID — полка, /fav/status/N — книги
// со статусом. Чужие полки (?user=uuid) можно смотреть, но не менять.

// чья полка: у страницы полки — её владелец, иначе ?user= или текущий пользователь
$owner = null;
$shelf = $url->action === 'shelf' ? shelf_get($url->var1) : null;
if ($shelf) {
	$owner = user_get($shelf->user_uuid);
} elseif (isset($_GET['user'])) {
	$owner = user_get($_GET['user']);
} elseif ($user_uuid !== '') {
	$owner = user_get($user_uuid);
}
if ($url->action === 'shelf' && !$shelf) {
	echo "<div class='alert alert-warning'>Такой полки нет.</div>";
	return;
}
if (!$owner) {
	echo "<div class='alert alert-info'>Полки у каждого свои. <a href='$webroot/users/?return=" . urlencode("$webroot/fav/") . "'>Войдите</a>, чтобы увидеть свои, или посмотрите чужие:</div>";
	foreach (user_list() as $u) {
		echo "<a class='btn btn-outline-secondary me-2 mb-2' href='$webroot/fav/?user=$u->user_uuid'><i class='fas fa-user'></i> " . h($u->name) . "</a>";
	}
	return;
}
$mine = $owner->user_uuid === $user_uuid;
$uq = $mine ? '' : "?user=$owner->user_uuid";
$page = max(0, intval($_GET['page'] ?? 0));
$sort = $_GET['sort'] ?? 'added';
$error = '';

// заголовок и переключатель между членами семьи
function fav_header($owner, $mine, $title = '') {
	global $webroot, $user_uuid;
	echo "<div class='d-flex flex-wrap align-items-center mb-3'>";
	echo "<h3 class='me-auto mb-0'>";
	if ($title !== '') {
		$uq = $mine ? '' : "?user=$owner->user_uuid";
		echo "<a href='$webroot/fav/$uq'>" . ($mine ? 'Мои полки' : 'Полки: ' . h($owner->name)) . "</a> / $title";
	} else {
		echo $mine ? 'Мои полки' : 'Полки: ' . h($owner->name);
	}
	echo "</h3>";
	echo "<div class='btn-group btn-group-sm flex-wrap'>";
	foreach (user_list() as $u) {
		$active = $u->user_uuid === $owner->user_uuid ? ' active' : '';
		$q = $u->user_uuid === $user_uuid ? '' : "?user=$u->user_uuid";
		echo "<a class='btn btn-outline-secondary$active' href='$webroot/fav/$q'>" . h($u->name) . "</a>";
	}
	echo "</div></div>";
}

if ($shelf) {
	if ($mine && $_SERVER['REQUEST_METHOD'] === 'POST') {
		$action = $_POST['action'] ?? '';
		if ($action === 'rename' && !$shelf->fav) {
			$error = shelf_rename($shelf, $_POST['name'] ?? '');
			if (!$error) {
				header("Location: $webroot/fav/shelf/$shelf->id");
				exit;
			}
		} elseif ($action === 'delete' && !$shelf->fav) {
			shelf_delete($shelf);
			header("Location: $webroot/fav/");
			exit;
		}
	}
	fav_header($owner, $mine, ($shelf->fav ? "<i class='fas fa-heart'></i> " : '') . h($shelf->name));
	if ($error) {
		echo "<div class='alert alert-warning'>" . h($error) . "</div>";
	}
	shelf_books_html("(SELECT bookid, added FROM shelf_book WHERE shelf_id=:id) x JOIN libbook b USING(bookid)",
		[':id' => $shelf->id], $sort, $page);
	if ($mine && !$shelf->fav) {
		echo "<details class='mt-3'><summary>Переименовать или удалить полку</summary><div class='card card-body mt-2'>";
		echo "<form method='post' class='mb-3'><input type='hidden' name='action' value='rename'>";
		echo "<div class='input-group'><input name='name' class='form-control' maxlength='" . SHELF_NAME_MAX . "' value='" . h($shelf->name) . "' required>";
		echo "<button class='btn btn-outline-primary'>Переименовать</button></div></form>";
		echo "<form method='post' onsubmit=\"return confirm('Удалить полку «" . h(addslashes($shelf->name)) . "»? Книги останутся в библиотеке.')\">";
		echo "<input type='hidden' name='action' value='delete'><button class='btn btn-danger'>Удалить полку</button></form>";
		echo "</div></details>";
	}
	return;
}

if ($url->action === 'status') {
	$st = $url->var1;
	if (!isset(BOOK_STATUSES[$st])) {
		echo "<div class='alert alert-warning'>Такого статуса нет.</div>";
		return;
	}
	[$label, $icon] = BOOK_STATUSES[$st];
	fav_header($owner, $mine, "<i class='$icon'></i> $label");
	shelf_books_html("(SELECT bookid, updated added FROM book_status WHERE user_uuid=:u AND status=:s) x JOIN libbook b USING(bookid)",
		[':u' => $owner->user_uuid, ':s' => $st], $sort, $page);
	return;
}

// обзор
if ($mine && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
	[$id, $error] = shelf_create($user_uuid, $_POST['name'] ?? '');
	if ($id) {
		header("Location: $webroot/fav/shelf/$id");
		exit;
	}
}

fav_header($owner, $mine);
if ($error) {
	echo "<div class='alert alert-warning'>" . h($error) . "</div>";
}

if ($mine) {
	continue_reading_html($owner->user_uuid, 'Продолжить чтение', "$webroot/fav/status/" . STATUS_READING);
}

$stmt = $dbh->prepare("SELECT status, COUNT(*) cnt FROM book_status WHERE user_uuid=:u GROUP BY status");
$stmt->execute([':u' => $owner->user_uuid]);
$counts = [];
foreach ($stmt as $r) {
	$counts[$r->status] = $r->cnt;
}
echo "<div class='row'>";
foreach (BOOK_STATUSES as $code => [$label, $icon, $cls]) {
	$c = $counts[$code] ?? 0;
	echo "<div class='col-6 col-md-3 mb-3'><a class='btn $cls w-100 py-3" . ($c ? '' : ' opacity-50') . "' href='$webroot/fav/status/$code$uq'>";
	echo "<i class='$icon'></i> $label <span class='badge bg-light text-dark'>$c</span></a></div>";
}
echo "</div>";

echo "<div class='row'>";
foreach (user_shelves($owner->user_uuid) as $sh) {
	echo "<div class='col-6 col-md-4 col-lg-3 mb-3'><a class='card h-100 text-decoration-none' href='$webroot/fav/shelf/$sh->id'>";
	echo "<div class='card-body'><h5 class='card-title mb-1'>" . ($sh->fav ? "<i class='fas fa-heart text-primary'></i> " : "<i class='fas fa-layer-group text-secondary'></i> ") . h($sh->name) . "</h5>";
	echo "<div class='small text-muted'>$sh->cnt " . plural_ru($sh->cnt, 'книга', 'книги', 'книг') . "</div></div></a></div>";
}
echo "</div>";

if ($mine) {
	echo "<form method='post' class='mb-4' id='new-shelf'><input type='hidden' name='action' value='create'>";
	echo "<div class='input-group'><input name='name' class='form-control' maxlength='" . SHELF_NAME_MAX . "' placeholder='Новая полка, например «Детям» или «В отпуск»' required>";
	echo "<button class='btn btn-success'><i class='fas fa-plus'></i> Создать</button></div></form>";
}

$stmt = $dbh->prepare("SELECT *
		FROM fav
		LEFT JOIN libavtorname USING(AvtorId)
		LEFT JOIN libapics USING(AvtorId)
		WHERE user_uuid=:uuid AND avtorid IS NOT NULL
		ORDER BY lastname, firstname");
$stmt->execute([':uuid' => $owner->user_uuid]);
$authors = $stmt->fetchAll();
if ($authors) {
	echo "<h5>Избранные авторы</h5>";
	echo '<div class="row mb-2">';
	foreach ($authors as $a) {
		echo "<div class='col col-sm-333 mb-3 d-flex justify-content-between'>";
		echo "<a class='mw-100 rounded-pill author' href='$webroot/author/view/$a->avtorid'>";
		if ($a->file != '') {
			echo "<img class='rounded-circle contact' src='$webroot/extract_author.php?id=$a->avtorid' />";
		}
		echo "&nbsp;$a->lastname $a->firstname $a->middlename $a->nickname&nbsp;</a>";
		echo "</div>";
	}
	echo "</div>";
}

$stmt = $dbh->prepare("SELECT *
		FROM fav
		LEFT JOIN libseqname USING(seqid)
		WHERE user_uuid=:uuid AND seqid IS NOT NULL
		ORDER BY seqname");
$stmt->execute([':uuid' => $owner->user_uuid]);
$series = $stmt->fetchAll();
if ($series) {
	echo "<h5>Избранные серии</h5>";
	echo "<div class='block mb-3'>";
	foreach ($series as $s) {
		echo "<a class='btn btn-sm btn-dark m-1' href='$webroot/?sid=$s->seqid'>&nbsp;$s->seqname&nbsp;</a> ";
	}
	echo "</div>";
}
