<style>
.c {
	background: #eee;
	border-radius: 50%;
	border-color: #eee;
}
</style>

<?php

include_once(ROOT_PATH . "webroot.php");
$filter2 = "";
$letter = 'А%';
$get = '';
$search = trim($_GET['q'] ?? '');

if (isset($_SESSION['authors_letter'])) {
	$get = $_SESSION['authors_letter'];
}
if (isset($_GET['letter'])) {
	$get = mb_strtolower($_GET['letter']);
}
if ($get != '') {
	$_SESSION['authors_letter'] = $get;
	$letter = $get . "%";
} else {
	unset($_SESSION['authors_letter']);
}
// при поиске буква алфавита не подсвечивается
$active = $search === '' ? mb_strtoupper($get) : '';

echo "<ul class='pagination'>";
	foreach (range(chr(0xC0), chr(0xDF)) as $b) {
		$l = iconv('CP1251', 'UTF-8', $b);
		if ($l == $active) {
			$cc = 'active';
		} else {
			$cc = '';
		}
		echo "<li class='page-item $cc'><a class='page-link' href='$webroot/authors/?letter=" . urlencode($l) . "'>$l</a></li>";
	}
echo "</ul>";
echo "<ul class='pagination'>";
	foreach (range('A', 'Z') as $b) {
		$l = iconv('CP1251', 'UTF-8', $b);
		if ($l == $active) {
			$cc = 'active';
		} else {
			$cc = '';
		}
		echo "<li class='page-item $cc'><a class='page-link' href='$webroot/authors/?letter=" . urlencode($l) . "'>$l</a></li>";
	}

echo "</ul>";


echo "<form action='$webroot/authors/'>\n";
?>
<div class="input-group mb-3">
  <input name="q" type="search" class="form-control" placeholder="Фамилия, имя или псевдоним" aria-label="Поиск автора" aria-describedby="basic-addon2" value="<?php echo htmlspecialchars($search); ?>">
  <div class="input-group-append">

    <input type='submit' class="btn btn-outline-secondary" value='Поиск' type="button">
  </div>
</div>
</form>

<?php
$start = AUTHORS_PAGE * $page;

$params = [];
$tsq = search_tsquery_sql($search, $params);
if ($tsq !== null) {
	// поиск по любой части имени, в любом порядке; только авторы с книгами
	$join = "JOIN libavtorname_ts ts ON ts.avtorid=an.avtorid CROSS JOIN (SELECT $tsq AS query) sq";
	$where = "ts.vector @@ sq.query AND EXISTS (SELECT 1 FROM libavtor la WHERE la.avtorid=an.avtorid)";
	$order = "ts_rank(ts.vector, sq.query) DESC, cnt DESC, an.lastname, an.firstname";
} else {
	$join = '';
	$where = "LOWER(an.lastname) LIKE :letter";
	$order = "an.firstname";
	$params[':letter'] = $letter;
}

$stmt = $dbh->prepare("SELECT COUNT(*) cnt FROM libavtorname an $join WHERE $where");
$stmt->execute($params);
$cnt = $stmt->fetch()->cnt;

$stmt = $dbh->prepare("SELECT an.*, ap.file,
		(SELECT COUNT(*) FROM libavtor WHERE libavtor.avtorid=an.avtorid) cnt
		FROM libavtorname an
		LEFT JOIN libapics ap ON ap.avtorid=an.avtorid
		$join
		WHERE $where
		ORDER BY $order LIMIT " . AUTHORS_PAGE . " OFFSET $start");
$stmt->execute($params);

if ($tsq !== null && $cnt == 0) {
	echo "<div class='alert alert-secondary'>По запросу «" . htmlspecialchars($search) . "» авторов не найдено.</div>";
	echo search_suggestions_html($dbh, $search, $webroot, false);
}

echo '<div class="row">';
show_gpager(ceil($cnt / AUTHORS_PAGE), 5);
while ($a = $stmt->fetch()) {
	if ($a->cnt > 0) {
		echo "<div class='col col-sm-6 mb-3 d-flex justify-content-between'>";
		echo "<a class='mw-100 rounded-pill author' href='$webroot/author/view/$a->avtorid'>";
		if ($a->file != '') {
			echo "<img class='rounded-circle contact' src='$webroot/extract_author.php?id=$a->avtorid' />";	
		}
		echo "&nbsp;$a->lastname $a->firstname $a->middlename $a->nickname&nbsp;</a>";
		echo "<div class='badge bg-secondary'>$a->cnt</div>";
		echo "</div>";

	}
}
echo "</div>";

show_gpager(ceil($cnt / AUTHORS_PAGE), 5);
