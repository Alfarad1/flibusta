<?php
// Читалка: отдельная страница без меню сайта, /read/<id>.
// FB2 показывается своей вёрсткой с настройками, оглавлением, сносками и
// позицией чтения (js/reader.js), EPUB — через epub.js с теми же настройками,
// остальные форматы — встроенными просмотрщиками.

$ext = $book ? strtolower(trim($book->filetype)) : '';
$back = $book ? "$webroot/book/view/$book->bookid" : "$webroot/";
$error = '';
$fb = null;

if (!$book) {
	http_response_code(404);
	$error = 'Книга не найдена.';
} elseif (!is_book_available($book->bookid)) {
	$error = 'Файла этой книги нет в локальном архиве.';
} elseif ($ext === 'fb2') {
	$fb = fb2html_book($book->bookid, $webroot);
	if ($fb === null) {
		$error = 'Файл книги не найден в архиве.';
	} elseif ($fb === false) {
		$error = 'Файл книги повреждён или не разбирается как FB2. Его можно скачать и открыть в другой программе.';
	}
}

$authors = [];
if ($book) {
	$stmt = $dbh->prepare("SELECT an.firstname, an.lastname, an.nickname FROM libavtor a
		JOIN libavtorname an USING(avtorid) WHERE a.bookid=:id");
	$stmt->execute([':id' => $book->bookid]);
	foreach ($stmt as $a) {
		$authors[] = trim("$a->firstname $a->lastname" . ($a->nickname ? " $a->nickname" : ''));
	}
}

$progress = null;
if ($book && $user_uuid !== '') {
	$stmt = $dbh->prepare("SELECT pos, loc FROM progress WHERE user_uuid=:u AND bookid=:b");
	$stmt->execute([':u' => $user_uuid, ':b' => $book->bookid]);
	$progress = $stmt->fetch() ?: null;
}
$styled = !$error && ($ext === 'fb2' || $ext === 'epub');

$config = [
	'bookid' => $book->bookid ?? 0,
	'format' => $ext,
	'loggedIn' => $user_uuid !== '',
	'saveUrl' => "$webroot/save_position.php",
	'markUrl' => "$webroot/mark.php",
	'fileUrl' => $book ? "$webroot/usr.php?id=$book->bookid" : '',
	'pos' => $progress ? (float)$progress->pos : 0,
	'loc' => $progress->loc ?? '',
	'status' => $book ? book_status_of($book->bookid)[0] : 0,
	'statusRead' => STATUS_READ,
];
$json = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!doctype html>
<html lang="<?php echo h($fb->lang ?? 'ru'); ?>" data-theme="day">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($book->title ?? 'Читалка'); ?></title>
<link rel="icon" href="<?php echo $webroot; ?>/favicon.svg" sizes="any" type="image/svg+xml">
<link href="<?php echo $webroot; ?>/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo asset('css/all.min.css'); ?>" rel="stylesheet">
<link href="<?php echo asset('css/reader.css'); ?>" rel="stylesheet">
<script>
// настройки применяются до отрисовки текста, чтобы страница не перестраивалась
(function () {
	try {
		var s = JSON.parse(localStorage.getItem('reader-settings') || '{}');
		var d = document.documentElement;
		if (s.theme) d.dataset.theme = s.theme;
		if (s.align) d.dataset.align = s.align;
		if (s.font) d.dataset.font = s.font;
		if (s.size) d.style.setProperty('--r-size', s.size + 'px');
		if (s.lh) d.style.setProperty('--r-lh', s.lh);
		if (s.width) d.dataset.width = s.width;
	} catch (e) {}
})();
</script>
</head>
<body class="reader-page<?php echo $styled ? ' styled' : ''; ?>">

<header id="bar" class="reader-bar">
	<a class="btn btn-link" href="<?php echo $back; ?>" title="К странице книги"><i class="fas fa-arrow-left"></i></a>
	<div class="reader-title">
		<div class="text-truncate"><?php echo h($book->title ?? ''); ?></div>
		<div class="text-truncate small opacity-75"><?php echo h(implode(', ', $authors)); ?></div>
	</div>
	<span id="pct" class="small opacity-75" title="Прочитано"></span>
<?php if ($styled) { ?>
	<button class="btn btn-link" type="button" data-bs-toggle="offcanvas" data-bs-target="#toc" title="Оглавление (T)"><i class="fas fa-list-ul"></i></button>
	<button class="btn btn-link" type="button" data-bs-toggle="offcanvas" data-bs-target="#settings" title="Настройки (S)"><i class="fas fa-font"></i></button>
<?php } ?>
</header>

<?php
if ($error) {
	echo "<main class='reader-text'><div class='alert alert-warning mt-4'>" . h($error) . "</div>";
	if ($book && is_book_available($book->bookid)) {
		echo "<a class='btn btn-outline-secondary' href='$webroot/" . ($ext === 'fb2' ? 'fb2' : 'usr') . ".php?id=$book->bookid'>Скачать файл</a> ";
	}
	echo "<a class='btn btn-outline-secondary' href='$back'>К странице книги</a></main>";
} elseif ($ext === 'fb2') {
	echo "<main id='text' class='reader-text'>" . str_replace('{WEBROOT}', $webroot, $fb->html) . "</main>";
	echo "<script type='application/json' id='notes-data'>" . str_replace('{WEBROOT}', $webroot, $json($fb->notes)) . "</script>";
} elseif ($ext === 'epub') {
	echo "<main id='epub' class='reader-epub'></main>";
} else {
	// остальные форматы — прежние просмотрщики, им нужны url и #reader
	$stmt = $dbh->prepare("SELECT filename FROM book_zip WHERE :id BETWEEN start_id AND end_id AND usr=1 LIMIT 1");
	$stmt->execute([':id' => $book->bookid]);
	$zip_name = $stmt->fetchColumn();
	$zip = new ZipArchive();
	echo "<main id='reader' class='reader reader-other'>";
	echo "<script>var url = " . $json($config['fileUrl']) . ";</script>";
	$viewers = ['txt' => 'txt', 'pdf' => 'pdf', 'mobi' => 'mobi', 'djvu' => 'djvu', 'djv' => 'djvu',
		'rtf' => 'rtf', 'docx' => 'docx', 'html' => 'html', 'htm' => 'html'];
	if (!isset($viewers[$ext])) {
		echo "<div class='alert alert-secondary mt-4'>Формат " . h($ext) . " в браузере не открывается. "
			. "<a href='$webroot/usr.php?id=$book->bookid'>Скачать файл</a></div>";
	} elseif ($zip_name && $zip->open(ROOT_PATH . "flibusta/" . $zip_name) === true) {
		$url->var1 = $book->bookid;
		include(__DIR__ . '/' . $viewers[$ext] . '.php');
		$zip->close();
	} else {
		echo "<div class='alert alert-warning mt-4'>Файл книги не найден в архиве.</div>";
	}
	echo "</main>";
}

if ($styled) {
?>
<footer id="end" class="reader-text reader-end">
	<div class="text-center my-5">
		<div class="mb-3 opacity-75">Конец книги</div>
<?php if ($user_uuid !== '') { ?>
		<button id="mark-read" class="btn btn-warning" type="button"><i class="fas fa-check"></i> Отметить «Прочитано»</button>
<?php } ?>
		<a class="btn btn-outline-secondary" href="<?php echo $back; ?>">К странице книги</a>
	</div>
</footer>

<div class="offcanvas offcanvas-start reader-panel" tabindex="-1" id="toc" aria-labelledby="toc-title">
	<div class="offcanvas-header">
		<h5 class="offcanvas-title" id="toc-title">Оглавление</h5>
		<button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Закрыть"></button>
	</div>
	<div class="offcanvas-body"><nav id="toc-list" class="toc">
<?php
	if ($fb) {
		if (!$fb->toc) {
			echo "<div class='opacity-75'>В книге нет оглавления.</div>";
		}
		foreach ($fb->toc as [$level, $id, $text]) {
			echo "<a class='toc-l" . min($level, 4) . "' href='#$id'>" . h($text) . "</a>";
		}
		if ($fb->notes) {
			echo "<a class='toc-l1' href='#notes'>Примечания</a>";
		}
	}
?>
	</nav></div>
</div>

<div class="offcanvas offcanvas-end reader-panel" tabindex="-1" id="settings" aria-labelledby="settings-title">
	<div class="offcanvas-header">
		<h5 class="offcanvas-title" id="settings-title">Настройки</h5>
		<button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Закрыть"></button>
	</div>
	<div class="offcanvas-body">
		<div class="mb-3">
			<div class="form-label">Тема</div>
			<div class="btn-group w-100" role="group" data-setting="theme">
				<button type="button" class="btn btn-outline-secondary" data-value="day">День</button>
				<button type="button" class="btn btn-outline-secondary" data-value="sepia">Сепия</button>
				<button type="button" class="btn btn-outline-secondary" data-value="night">Ночь</button>
			</div>
		</div>
		<div class="mb-3">
			<div class="form-label">Шрифт</div>
			<div class="btn-group w-100" role="group" data-setting="font">
				<button type="button" class="btn btn-outline-secondary" data-value="serif" style="font-family: Georgia, serif">С засечками</button>
				<button type="button" class="btn btn-outline-secondary" data-value="sans" style="font-family: system-ui, sans-serif">Без засечек</button>
			</div>
		</div>
		<div class="mb-3">
			<div class="form-label">Размер шрифта</div>
			<div class="input-group" data-step="size">
				<button type="button" class="btn btn-outline-secondary" data-delta="-1" title="Меньше (−)"><i class="fas fa-minus"></i></button>
				<span class="form-control text-center" data-show="size"></span>
				<button type="button" class="btn btn-outline-secondary" data-delta="1" title="Больше (+)"><i class="fas fa-plus"></i></button>
			</div>
		</div>
		<div class="mb-3">
			<div class="form-label">Межстрочный интервал</div>
			<div class="input-group" data-step="lh">
				<button type="button" class="btn btn-outline-secondary" data-delta="-0.1"><i class="fas fa-minus"></i></button>
				<span class="form-control text-center" data-show="lh"></span>
				<button type="button" class="btn btn-outline-secondary" data-delta="0.1"><i class="fas fa-plus"></i></button>
			</div>
		</div>
		<div class="mb-3">
			<div class="form-label">Ширина колонки</div>
			<div class="btn-group w-100" role="group" data-setting="width">
				<button type="button" class="btn btn-outline-secondary" data-value="narrow">Узкая</button>
				<button type="button" class="btn btn-outline-secondary" data-value="medium">Средняя</button>
				<button type="button" class="btn btn-outline-secondary" data-value="wide">Широкая</button>
				<button type="button" class="btn btn-outline-secondary" data-value="full">Во всю</button>
			</div>
		</div>
		<div class="mb-3">
			<div class="form-label">Выравнивание</div>
			<div class="btn-group w-100" role="group" data-setting="align">
				<button type="button" class="btn btn-outline-secondary" data-value="left"><i class="fas fa-align-left"></i> По левому краю</button>
				<button type="button" class="btn btn-outline-secondary" data-value="justify"><i class="fas fa-align-justify"></i> По ширине</button>
			</div>
		</div>
		<button type="button" class="btn btn-link px-0" id="settings-reset">Сбросить настройки</button>
		<hr>
		<div class="small opacity-75">
			Пробел, PageDown, → — страница вперёд; Shift+пробел, PageUp, ← — назад;
			+ и − — размер шрифта; T — оглавление; S — настройки.
			Настройки запоминаются на этом устройстве<?php echo $user_uuid !== '' ? ', позиция чтения — у вас на всех устройствах' : ''; ?>.
		</div>
	</div>
</div>

<div id="note-pop" class="note-pop" hidden>
	<button type="button" class="btn-close float-end" aria-label="Закрыть"></button>
	<div class="note-body"></div>
</div>
<?php
}
?>
<script src="<?php echo $webroot; ?>/bootstrap/js/bootstrap.bundle.min.js"></script>
<?php
if ($styled) {
	if ($ext === 'epub') {
		echo "<script src='$webroot/js/jszip.min.js'></script>\n<script src='$webroot/js/epub.min.js'></script>\n";
	}
	echo "<script>var READER = " . $json($config) . ";</script>\n";
	echo "<script src='" . asset('js/reader.js') . "'></script>\n";
}
?>
</body>
</html>
