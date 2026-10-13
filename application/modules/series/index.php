<style>
.c {
	background: #eee;
	border-radius: 50%;
	border-color: #eee;
}
</style>

<?php

$filter2 = "";
$letter = 'А%';
$get = '';
$search = trim($_GET['q'] ?? '');

if (isset($_SESSION['series_letter'])) {
	$get = $_SESSION['series_letter'];
}
if (isset($_GET['letter'])) {
	$get = mb_strtolower($_GET['letter']);
}
if ($get != '') {
	$_SESSION['series_letter'] = $get;
	$letter = $get . "%";
} else {
	unset($_SESSION['series_letter']);
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
		echo "<li class='page-item $cc'><a class='page-link' href='$webroot/series/?letter=" . urlencode($l) . "'>$l</a></li>";
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
		echo "<li class='page-item $cc'><a class='page-link' href='$webroot/series/?letter=" . urlencode($l) . "'>$l</a></li>";
	}

echo "</ul>";


echo "<form action='$webroot/series/'>\n";
?>
<div class="input-group mb-3">
  <input name="q" type="search" class="form-control" placeholder="Поиск серии" aria-label="Поиск серии" aria-describedby="basic-addon2" value="<?php echo htmlspecialchars($search); ?>">
  <div class="input-group-append">

    <input type='submit' class="btn btn-outline-secondary" value='Поиск' type="button">
  </div>
</div>
</form>

<?php
$start = SERIES_PAGE * $page;

$params = [];
$tsq = search_tsquery_sql($search, $params);
if ($tsq !== null) {
	// поиск по словам названия в любом порядке; только серии с книгами
	$join = "JOIN libseqname_ts ts ON ts.seqid=sn.seqid CROSS JOIN (SELECT $tsq AS query) sq";
	$where = "ts.vector @@ sq.query AND EXISTS (SELECT 1 FROM libseq ls WHERE ls.seqid=sn.seqid)";
	$order = "ts_rank(ts.vector, sq.query) DESC, cnt DESC, sn.seqname";
} else {
	$join = '';
	$where = "LOWER(sn.SeqName) LIKE :letter";
	$order = "sn.seqname";
	$params[':letter'] = $letter;
}

$stmt = $dbh->prepare("SELECT COUNT(*) cnt FROM libseqname sn $join WHERE $where");
$stmt->execute($params);
$cnt = $stmt->fetch()->cnt;

$stmt = $dbh->prepare("SELECT sn.SeqName, sn.SeqId,
		(SELECT COUNT(*) FROM libseq WHERE libseq.SeqId=sn.SeqId) cnt
		FROM libseqname sn
		$join
		WHERE $where
		ORDER BY $order LIMIT " . SERIES_PAGE . " OFFSET $start");
$stmt->execute($params);

if ($tsq !== null && $cnt == 0) {
	echo "<div class='alert alert-secondary'>По запросу «" . htmlspecialchars($search) . "» серий не найдено.</div>";
}

echo '<div class="row">';
show_gpager(ceil($cnt / SERIES_PAGE), 5);
while ($bs = $stmt->fetch()) {
	if ($bs->cnt > 0) {
		echo "<div class='col col-sm-6 mb-3 d-flex justify-content-between'><a class='mw-100 text-dark' href='$webroot/?sid=$bs->seqid'>$bs->seqname</a><span class='badge bg-secondary'>$bs->cnt</span></div>";
	}
}
echo "</div>";

show_gpager(ceil($cnt / SERIES_PAGE), 5);

