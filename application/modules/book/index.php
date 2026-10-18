<?php
book_info_pg($book, $webroot, true);
echo read_button($book);

// «Также рекомендуют»: книги, которые рекомендовали те же читатели Флибусты.
// Вклад читателя тем меньше, чем больше у него рекомендаций.
if (db_has_table('book_recs')) {
	$stmt = $dbh->prepare("WITH u AS (
			SELECT r.uid, 1 / ln(2 + COUNT(*)) w
			FROM book_recs me JOIN book_recs r USING(uid)
			WHERE me.bookid=:id GROUP BY r.uid
		), c AS (
			SELECT r.bookid, SUM(u.w) score, COUNT(*) cnt
			FROM u JOIN book_recs r USING(uid)
			WHERE r.bookid<>:id GROUP BY r.bookid
		)
		SELECT b.* FROM c JOIN libbook b USING(bookid)
		WHERE b.deleted='0' AND c.cnt>=2
			AND EXISTS (SELECT 1 FROM book_available ba WHERE ba.bookid=b.bookid)
		ORDER BY c.score DESC, b.bookid LIMIT 6");
	$stmt->execute([':id' => $url->var1]);
	$recs = $stmt->fetchAll();
	if ($recs) {
		echo "<div class='card mb-3'><h5 class='card-header'>Читатели этой книги также рекомендуют</h5>";
		echo "<div class='card-body pb-0'><div class='row equal'>";
		foreach ($recs as $r) {
			book_small_pg($r, $webroot);
		}
		echo "</div></div></div>";
	}
}

echo "<div class='card card-body p-3'><ul>";

$stmt = $dbh->prepare("SELECT name, text FROM libreviews WHERE bookid=:id ORDER BY time");
$stmt->bindParam(":id", $url->var1);
$stmt->execute();

while ($r = $stmt->fetch()) {
	echo "<li><span class='badge bg-secondary'>$r->name</span> " . stripslashes($r->text) . "</li>";
}

echo "</ul></div>";
