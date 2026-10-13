<?php
header('Content-Type: application/atom+xml; charset=utf-8');
echo '<?xml version="1.0" encoding="utf-8"?>';
echo <<< _XML
 <feed xmlns="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/terms/" xmlns:os="http://a9.com/-/spec/opensearch/1.1/" xmlns:opds="http://opds-spec.org/2010/catalog"> <id>tag:root:authors</id>
 <title>Поиск по авторам</title>
 <updated>$cdt</updated>
 <icon>/favicon.ico</icon>
 <link href="$webroot/opds-opensearch.xml.php" rel="search" type="application/opensearchdescription+xml" />
 <link href="$webroot/opds/search?by=author&amp;q={searchTerms}" rel="search" type="application/atom+xml" />
 <link href="$webroot/opds" rel="start" type="application/atom+xml;profile=opds-catalog" />
_XML;

$q = trim($_GET['q'] ?? '');
$prefix = trim($_GET['prefix'] ?? '');

$params = [];
$tsq = search_tsquery_sql($q, $params);
if ($prefix !== '') {
	// переход из алфавитного указателя authorsindex: фамилия начинается с букв
	$params = [':prefix' => $prefix . '%'];
	$join = '';
	$where = 'an.lastname ILIKE :prefix';
	$order = 'an.lastname, an.firstname';
} elseif ($tsq !== null) {
	// поиск по любой части имени, в любом порядке
	$join = "JOIN libavtorname_ts ts ON ts.avtorid=an.avtorid CROSS JOIN (SELECT $tsq AS query) sq";
	$where = 'ts.vector @@ sq.query';
	$order = 'ts_rank(ts.vector, sq.query) DESC, cnt DESC, an.lastname, an.firstname';
} else {
	echo '</feed>';
	return;
}

$authors = $dbh->prepare("SELECT an.avtorid, an.lastname, an.firstname, an.middlename, an.nickname, c.cnt
	FROM libavtorname an
	$join
	CROSS JOIN LATERAL (SELECT COUNT(*) cnt
		FROM libavtor la JOIN libbook b USING(bookid)
		WHERE la.avtorid=an.avtorid AND b.deleted='0') c
	WHERE $where AND c.cnt > 0
	ORDER BY $order
	LIMIT " . OPDS_FEED_COUNT);
$authors->execute($params);
while ($a = $authors->fetch()) {
	echo "\n<entry> <updated>$cdt</updated>";
	echo " <id>tag:author:$a->avtorid</id>";
	echo " <title>" . htmlspecialchars("$a->lastname $a->firstname $a->middlename $a->nickname") . "</title>";
	echo " <content type='text'>$a->cnt книг</content>";
	echo " <link href='$webroot/opds/author?author_id=$a->avtorid' type='application/atom+xml;profile=opds-catalog' />";
	echo '</entry>';
}
?>
</feed>
