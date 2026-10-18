<?php
include_once(ROOT_PATH . "webroot.php");
$stmt = $dbh->prepare("SELECT * FROM libavtorname LEFT JOIN libapics USING(AvtorId) WHERE avtorid=:id");
$stmt->bindParam(":id", $url->var1);
$stmt->execute();
$a = $stmt->fetch();

echo "<div class='card mb-3'>";
echo "<div class='card-header'><h3>$a->lastname $a->firstname $a->middlename $a->nickname</h3></div>";
echo "<div class='card-body'><div class='row'>";

echo "<div class='col-sm-2 mb-3'>";
if (isset($a->file)) {
	echo "<img src='$webroot/extract_author.php?id=$a->avtorid' style='width: 100%;' class='card-image' /><br />";	
}
$stmt = $dbh->prepare("SELECT
	(SELECT COUNT(*) FROM libavtor a JOIN libbook b USING(bookid) WHERE a.avtorid=:id AND b.deleted='0') books,
	(SELECT COUNT(*) FROM libtranslator t JOIN libbook b USING(bookid) WHERE t.translatorid=:id AND b.deleted='0') translations");
$stmt->execute([':id' => $a->avtorid]);
$cnt = $stmt->fetch();
if ($cnt->books > 0 || $cnt->translations == 0) {
	echo "<a class='btn btn-primary mt-2 w-100' href='$webroot/?aid=$a->avtorid&amp;tid'>Книги автора <span class='badge bg-light text-dark'>$cnt->books</span></a>";
}
if ($cnt->translations > 0) {
	echo "<a class='btn btn-outline-primary mt-2 w-100' href='$webroot/?tid=$a->avtorid&amp;aid'>Переводы <span class='badge bg-primary'>$cnt->translations</span></a>";
}

if ($user_uuid != '') {
	echo fav_link('author', $a->avtorid, fav_is($user_uuid, 'author', $a->avtorid), 'btn mt-2 w-100',
		['btn-warning', 'Из избранного'], ['btn-secondary', 'В избранное'], 'Автор в избранное');
}

echo "</div>";
echo "<div class='col-sm-10 mb-3'>";

$stmt = $dbh->prepare("SELECT * FROM libaannotations WHERE AvtorId=:id");
$stmt->bindParam(":id", $url->var1);
$stmt->execute();
while ($an = $stmt->fetch()) {
	echo "$an->title<br />";
	echo "<p>", bbc2html(nl2br($an->body)), "</p>";
}
echo '</div>';



echo "</div></div></div>";

