<?php
if (isset($_GET['fb2'])) {
	if ($_GET['fb2'] == '') {
		unset($_SESSION['fb2']);
	} else {
		$_SESSION['fb2'] = true;
	}
}
if (isset($_GET['ru'])) {
	if ($_GET['ru'] == '') {
		unset($_SESSION['ru']);
	} else {
		$_SESSION['ru'] = true;
	}
}
if (isset($_GET['missing'])) {
	if ($_GET['missing'] == '') {
		unset($_SESSION['show_missing']);
	} else {
		$_SESSION['show_missing'] = true;
	}
}
// Поисковый запрос берётся только из URL: результаты можно открыть по ссылке,
// а «Назад» в браузере работает как ожидается
unset($_SESSION['search']);
$search = trim($_GET['q'] ?? '');
$params = [];
$order_params = [];
$search_tsq = search_tsquery_sql($search, $params);

// Сортировка. Без параметра sort: при поиске — по релевантности, иначе — новые поступления
$sort_modes = [
	'date' => ['Новые поступления', 'b.Time DESC'],
	'abc' => ['По названию', 'b.Title, b.BookId'],
	'year' => ['По году издания', 'NULLIF(b.Year, 0) DESC NULLS LAST, b.Time DESC'],
];
if (db_has_table('book_rating')) {
	$sort_modes['rating'] = ['По рейтингу', 'r.score DESC NULLS LAST, r.bookid DESC'];
}
$sort_mode = $_GET['sort'] ?? '';
if (!isset($sort_modes[$sort_mode])) {
	$sort_mode = '';
}
$order_join = '';
if ($sort_mode === '' && $search_tsq !== null) {
	$order = search_books_order_sql($search, $order_params);
} else {
	$order = $sort_modes[$sort_mode ?: 'date'][1];
	if ($sort_mode === 'rating') {
		// в book_rating есть строки для всех книг, обычный JOIN позволяет идти по индексу
		$order_join = 'JOIN book_rating r ON r.bookid=b.BookId ';
	}
}
// дописывается к ссылкам фильтров, чтобы переключение фильтра не сбрасывало поиск и сортировку
$qamp = keep_params_amp();

if (isset($_GET['aid'])) {
	if ($_GET['aid'] == '') {
		unset($_SESSION['filter_author']);
	} else {
		$_SESSION['filter_author'] = intval($_GET['aid']);
	}
}


if (isset($_GET['tid'])) {
	if ($_GET['tid'] == '') {
		unset($_SESSION['filter_translator']);
	} else {
		$_SESSION['filter_translator'] = intval($_GET['tid']);
	}
}

if (isset($_GET['gid'])) {
	if ($_GET['gid'] == '') {
		unset($_SESSION['filter_genre']);
	} else {
		$_SESSION['filter_genre'] = intval($_GET['gid']);
	}
}

if (isset($_GET['sid'])) {
	if ($_GET['sid'] == '') {
		unset($_SESSION['filter_series']);
	} else {
		$_SESSION['filter_series'] = intval($_GET['sid']);
	}
}



if (isset($_GET['xgid'])) {
	if ($_GET['xgid'] == '') {
		unset($_SESSION['filter_xgenre']);
	} else {
		if ($_SESSION['filter_genre'] == intval($_GET['xgid'])) {
			unset($_SESSION['filter_genre']);
		}
		$_SESSION['filter_xgenre'] = intval($_GET['xgid']);
	}
}


$filter = '';
$fcontent = '';
$join = '';
$cols = '';


$fcontent .= '<div class="btn-group mt-1 me-1" role="group">';
if (isset($_SESSION['fb2'])) {
	$filter .= "AND filetype='fb2' ";
	$fcontent .= "<a class='btn bg-dark text-white bg-opacity-90 text-white' href='$webroot/?fb2$qamp'>Только FB2</a> ";
} else {
	$fcontent .= "<a class='btn bg-dark text-white bg-opacity-50 text-white' href='$webroot/?fb2=1$qamp'>Все форматы</a> ";
}

if (isset($_SESSION['ru'])) {
	$filter .= "AND lang='ru' ";
	$fcontent .= "<a class='btn bg-success text-white bg-opacity-90 text-white' href='$webroot/?ru$qamp'>На русском</a> ";
} else {
	$fcontent .= "<a class='btn bg-success text-white bg-opacity-50 text-white' href='$webroot/?ru=1$qamp'>Все языки</a> ";
}

if (isset($_SESSION['show_missing'])) {
	$fcontent .= "<a class='btn bg-secondary text-white bg-opacity-50' href='$webroot/?missing$qamp' title='Показаны и книги, файлов которых нет в локальном архиве'>Включая отсутствующие</a> ";
} else {
	$filter .= "AND EXISTS (SELECT 1 FROM book_available ba WHERE ba.bookid=b.BookId) ";
	$fcontent .= "<a class='btn bg-secondary text-white bg-opacity-90' href='$webroot/?missing=1$qamp' title='Скрыты книги, файлов которых нет в локальном архиве'>Только доступные</a> ";
}
$fcontent .= '</div>';

if (isset($_SESSION['filter_author'])) {
	$do_cnt = true;
	$filter .= 'AND avtorid=:aid ';
	$params[':aid'] = $_SESSION['filter_author'];
	$join .= 'LEFT JOIN libavtor a USING(BookId) ';
	$stmt = $dbh->prepare("SELECT * FROM libavtorname
		LEFT JOIN libapics USING(AvtorId)
		WHERE AvtorId=:id");
	$stmt->bindParam(":id", $_SESSION['filter_author']);
	$stmt->execute();
	$a = $stmt->fetch();

	$fcontent .= "<div class='badge rounded-pill author'>";
	if ($a->file != '') {
		$fcontent .= "<img class='rounded-circle contact' src='$webroot/extract_author.php?id=$a->avtorid' />";
	}
	$fcontent .= "<a href='$webroot/?aid$qamp'>$a->lastname $a->firstname $a->middlename $a->nickname</a> <i class='fas fa-times-circle'></i></div> ";
}

if (isset($_SESSION['filter_translator'])) {
	$filter .= 'AND EXISTS (SELECT 1 FROM libtranslator t WHERE t.bookid=b.BookId AND t.translatorid=:tid) ';
	$params[':tid'] = $_SESSION['filter_translator'];
	$stmt = $dbh->prepare("SELECT * FROM libavtorname WHERE AvtorId=:id");
	$stmt->execute([':id' => $_SESSION['filter_translator']]);
	$t = $stmt->fetch();

	$fcontent .= "<div class='badge rounded-pill author'>";
	$fcontent .= "<a href='$webroot/?tid$qamp'>Перевод: " . ($t ? "$t->firstname $t->middlename $t->lastname $t->nickname" : $_SESSION['filter_translator']) . "</a> <i class='fas fa-times-circle'></i></div> ";
}

if (isset($_SESSION['filter_genre'])) {
	$filter .= 'AND g.genreid=:gid ';
	$params[':gid'] = $_SESSION['filter_genre'];
	$join .= 'LEFT JOIN libgenre g USING(BookId) ';
	$stmt = $dbh->prepare("SELECT * FROM libgenrelist
		WHERE genreid=:id");
	$stmt->bindParam(":id", $_SESSION['filter_genre']);
	$stmt->execute();
	$g = $stmt->fetch();

	$fcontent .= "<div class='badge bg-success p-1 text-white'>";
	$fcontent .= "<a class='text-white' href='$webroot/?xgid=$g->genreid$qamp'>$g->genremeta: $g->genredesc <i class='fas fa-times-circle'></i></a></div> ";
}

if (isset($_SESSION['filter_xgenre'])) {
	$filter .= 'AND NOT EXISTS (SELECT 1 FROM libgenre xg WHERE xg.BookId=b.BookId AND xg.genreid=:xgid) ';
	$params[':xgid'] = $_SESSION['filter_xgenre'];
	$stmt = $dbh->prepare("SELECT * FROM libgenrelist
		WHERE genreid=:id");
	$stmt->bindParam(":id", $_SESSION['filter_xgenre']);
	$stmt->execute();
	$xg = $stmt->fetch();

	$fcontent .= "<div class='badge bg-secondary p-1 text-white'>";
	$fcontent .= "<a style='text-decoration: line-through;' class='text-white' href='$webroot/?xgid$qamp'>$xg->genremeta: $xg->genredesc <i class='fas fa-times-circle'></i></a></div> ";
}


if (isset($_SESSION['filter_series'])) {
	$do_cnt = true;
	$cols = 's.seqnumb,';
	$filter .= 'AND seqid=:sid ';
	$params[':sid'] = $_SESSION['filter_series'];
	$join .= 'LEFT JOIN libseq s USING(BookId) ';
	$stmt = $dbh->prepare("SELECT * FROM libseqname
		WHERE seqid=:id");
	$stmt->bindParam(":id", $_SESSION['filter_series']);
	$stmt->execute();
	$s = $stmt->fetch();

	$fcontent .= "<div class='badge bg-danger p-1 text-white'>";
	$fcontent .= "<a class='text-white' href='$webroot/?sid$qamp'>$s->seqname <i class='fas fa-times-circle'></i></a></div> ";
	// внутри серии по умолчанию — по номеру книги в серии
	if ($sort_mode === '') {
		$order = "s.seqnumb, $order";
	}
	$seqname = $s->seqname;
	$seqid = $_SESSION['filter_series'];
}

if ($search_tsq !== null) {
	$filter .= "AND ts.vector @@ sq.query ";
	$join .= "JOIN libbook_ts ts USING(bookid) CROSS JOIN (SELECT $search_tsq AS query) sq ";

	$fcontent .= "<div class='badge bg-success p-1 text-white'>";
	$fcontent .= "<a class='text-white' href='$webroot/" . ($sort_mode !== '' ? "?sort=$sort_mode" : '') . "'>" . htmlspecialchars($search) . " <i class='fas fa-times-circle'></i></a></div> ";
}

if (isset($_SESSION['filter_series']) && $user_uuid != '') {
	$heart = "<i class='fas fa-heart'></i>";
	$fcontent .= fav_link('seq', $seqid, fav_is($user_uuid, 'seq', $seqid), 'btn btn-sm float-end',
		['btn-primary', "$heart Серия в избранном"], ['btn-info', "$heart Серию в избранное"], 'Серия в избранное');
}

// выбор сортировки
$sort_items = $search_tsq !== null ? ['' => 'По релевантности'] : [];
foreach ($sort_modes as $k => $m) {
	$sort_items[$k] = $m[0];
}
$sort_current = ($sort_mode === '' && $search_tsq === null) ? 'date' : $sort_mode;
$sort_q = $search !== '' ? '&amp;q=' . urlencode($search) : '';
$fcontent .= "<div class='btn-group mt-1 me-1'>";
$fcontent .= "<button type='button' class='btn btn-outline-secondary dropdown-toggle' data-bs-toggle='dropdown' aria-expanded='false' title='Сортировка'>";
$fcontent .= "<i class='fas fa-sort-amount-down'></i> {$sort_items[$sort_current]}</button>";
$fcontent .= "<ul class='dropdown-menu'>";
foreach ($sort_items as $k => $label) {
	$active = $k === $sort_current ? ' active' : '';
	$href = $k === '' ? "$webroot/?q=" . urlencode($search) : "$webroot/?sort=$k$sort_q";
	$fcontent .= "<li><a class='dropdown-item$active' href='$href'>$label</a></li>";
}
$fcontent .= "</ul></div>";

echo "<div class='block rounded' style='margin-bottom:8px;'>";
echo "<form action='$webroot/'>";
?>

<div class="input-group mb-3">
   <input name="q" type="search" class="form-control" placeholder="Название, автор или серия" aria-label="Поиск книг" aria-describedby="basic-addon2" value="<?php echo htmlspecialchars($search); ?>">
   <div class="input-group-append">
   <input type='submit' class="btn btn-outline-secondary" value='Поиск' type="button">

 </div>
</div>
</form>
<?php
echo $fcontent;

echo "</div>";


$sql = "SELECT b.*, $cols
        (SELECT Body FROM libbannotations WHERE BookId=b.BookId LIMIT 1) Body
		FROM libbook b
		$join
		$order_join
		WHERE deleted='0'
		$filter
		ORDER BY $order LIMIT " . RECORDS_PAGE . " OFFSET $start";

//echo "$sql";

$stmt = $dbh->prepare($sql);

try {
	$stmt->execute($params + $order_params);
} catch (Exception $e) {
	$protocol = (isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0');
        header($protocol . ' 504 Gateway Time-out');

	echo "<div class='card m-3 border-danger'><div class='card-header bg-danger'>База данных</div><div class='card-body'>";
	echo "<h3>" . $e->getMessage() . "</h3>";
	echo "<p>Попробуйте упростить параметры поиска, убрать часть тэгов, направленность. Сервер маленький ^^.</p>";
	echo "</div></div>";
}

if (COUNT_BOOKS) {
	$sql = "SELECT COUNT(*) cnt
		FROM libbook b
		$join
		WHERE deleted='0'
		$filter";
	$stt = $dbh->prepare($sql);
	$stt->execute($params);
	$cnt = $stt->fetch()->cnt;
	echo "<span class='badge bg-primary mb-1'>Найдено: $cnt</span> ";
} else {
	$cnt = 2000;
}

$rcnt = $stmt->rowCount();
if ($rcnt < RECORDS_PAGE) {
	$cnt = $page * RECORDS_PAGE + $rcnt;
}

if ($search_tsq !== null && $rcnt == 0 && $page == 0) {
	// Ничего не нашлось: проверяем, не скрыли ли книги фильтры, и подсказываем похожее
	$filtered = isset($_SESSION['fb2']) || isset($_SESSION['ru']) || !isset($_SESSION['show_missing'])
		|| isset($_SESSION['filter_author']) || isset($_SESSION['filter_genre'])
		|| isset($_SESSION['filter_xgenre']) || isset($_SESSION['filter_series'])
		|| isset($_SESSION['filter_translator']);
	$hidden = 0;
	if ($filtered) {
		$search_params = [];
		$tsq = search_tsquery_sql($search, $search_params);
		$stt = $dbh->prepare("SELECT COUNT(*) cnt FROM libbook b
			JOIN libbook_ts ts USING(bookid) CROSS JOIN (SELECT $tsq AS query) sq
			WHERE deleted='0' AND ts.vector @@ sq.query");
		$stt->execute($search_params);
		$hidden = $stt->fetch()->cnt;
	}
	if ($hidden > 0) {
		echo "<div class='alert alert-warning mt-2'>По запросу «" . htmlspecialchars($search) . "» есть книги ($hidden), но их скрывают фильтры. ";
		echo "<a href='$webroot/?fb2&amp;ru&amp;missing=1&amp;aid&amp;tid&amp;gid&amp;xgid&amp;sid$qamp'>Сбросить фильтры</a></div>";
	} else {
		echo "<div class='alert alert-secondary mt-2'>По запросу «" . htmlspecialchars($search) . "» ничего не найдено.</div>";
		echo search_suggestions_html($dbh, $search, $webroot);
	}
}

show_gpager(ceil($cnt / RECORDS_PAGE), 5);

$c = 0;
while ($book = $stmt->fetch()) {
	$c++;
	if ($c > 10) {
		break;
	}
	book_info_pg($book, $webroot);
}

show_gpager(ceil($cnt / RECORDS_PAGE), 5);

