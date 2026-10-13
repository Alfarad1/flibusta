<?php
header('Content-Type: application/atom+xml; charset=utf-8');

$q = trim($_GET['q'] ?? '');
$qu = urlencode($q);
$params = [];
$tsq = search_tsquery_sql($q, $params);

$books = [];
$authors_cnt = 0;
if ($tsq !== null) {
	// тот же поиск, что и в веб-интерфейсе: название, авторы, серии; только книги с файлами
	$order_params = [];
	$order = search_books_order_sql($q, $order_params);
	$stmt = $dbh->prepare("SELECT b.*
		FROM libbook b
		JOIN libbook_ts ts USING(bookid)
		CROSS JOIN (SELECT $tsq AS query) sq
		WHERE b.deleted='0' AND ts.vector @@ sq.query
			AND EXISTS (SELECT 1 FROM book_available ba WHERE ba.bookid=b.bookid)
		ORDER BY $order
		LIMIT " . (OPDS_FEED_COUNT + 1) . " OFFSET " . ($page * OPDS_FEED_COUNT));
	$stmt->execute($params + $order_params);
	$books = $stmt->fetchAll();

	if ($page == 0) {
		$stmt = $dbh->prepare("SELECT COUNT(*) cnt
			FROM libavtorname_ts ts
			CROSS JOIN (SELECT $tsq AS query) sq
			WHERE ts.vector @@ sq.query
				AND EXISTS (SELECT 1 FROM libavtor la WHERE la.avtorid=ts.avtorid)");
		$stmt->execute($params);
		$authors_cnt = $stmt->fetch()->cnt;
	}
}

echo '<?xml version="1.0" encoding="utf-8"?>';
echo <<< _XML
 <feed xmlns="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/terms/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:os="http://a9.com/-/spec/opensearch/1.1/" xmlns:opds="http://opds-spec.org/2010/catalog"> <id>tag:search:book:$qu</id>
 <title>Поиск по книгам</title>
 <updated>$cdt</updated>
 <icon>/favicon.ico</icon>
 <link href="$webroot/opds-opensearch.xml.php" rel="search" type="application/opensearchdescription+xml" />
 <link href="$webroot/opds/search?q={searchTerms}" rel="search" type="application/atom+xml" />
 <link href="$webroot/opds" rel="start" type="application/atom+xml;profile=opds-catalog" />
_XML;

if (count($books) > OPDS_FEED_COUNT) {
	array_pop($books);
	echo "\n <link href='$webroot/opds/search?q=$qu&amp;page=" . ($page + 1) . "' rel='next' type='application/atom+xml;profile=opds-catalog' />";
}

if ($authors_cnt > 0) {
	echo "\n<entry> <updated>$cdt</updated>";
	echo " <id>tag:search:author:$qu</id>";
	echo " <title>Авторы: " . htmlspecialchars($q) . "</title>";
	echo " <content type='text'>Найдено авторов: $authors_cnt</content>";
	echo " <link href='$webroot/opds/search?by=author&amp;q=$qu' type='application/atom+xml;profile=opds-catalog' />";
	echo "</entry>";
}

foreach ($books as $b) {
	opds_book($b, $webroot);
}
?>
</feed>
