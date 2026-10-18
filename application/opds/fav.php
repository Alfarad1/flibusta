<?php
// Полки пользователя в OPDS: ?uuid= — список полок и статусов,
// ?uuid=&shelf=ID или ?uuid=&status=N — книги
header('Content-Type: application/atom+xml; charset=utf-8');
echo '<?xml version="1.0" encoding="utf-8"?>';
$owner = user_get($_GET['uuid'] ?? '');
$shelf = isset($_GET['shelf']) ? shelf_get(intval($_GET['shelf'])) : null;
$status = intval($_GET['status'] ?? 0);
if ($owner && $shelf && $shelf->user_uuid !== $owner->user_uuid) {
	$shelf = null;
}
$self = '/opds/fav/?' . h(http_build_query(array_intersect_key($_GET, ['uuid' => 1, 'shelf' => 1, 'status' => 1])));
$title = !$owner ? 'Полка не найдена' : ($shelf ? $shelf->name : (isset(BOOK_STATUSES[$status]) ? BOOK_STATUSES[$status][0] : $owner->name));
$kind = ($shelf || isset(BOOK_STATUSES[$status])) ? 'acquisition' : 'navigation';
echo <<< _XML
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/terms/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:os="http://a9.com/-/spec/opensearch/1.1/" xmlns:opds="http://opds-spec.org/2010/catalog">
<id>tag:root:home</id>
_XML;
echo "<title>" . h($title) . "</title>";
echo "<updated>$cdt</updated>";
echo <<< _XML
<icon>/favicon.ico</icon>
<link href="$webroot/opds-opensearch.xml.php" rel="search" type="application/opensearchdescription+xml" />
<link href="$webroot/opds/search?q={searchTerms}" rel="search" type="application/atom+xml" />
<link href="$webroot/opds/" rel="start" type="application/atom+xml;profile=opds-catalog" />
_XML;
echo "<link href='$webroot$self' rel='self' type='application/atom+xml;profile=opds-catalog;kind=$kind' />";

if (!$owner) {
	// ничего
} elseif ($kind === 'navigation') {
	$base = "$webroot/opds/fav/?uuid=$owner->user_uuid";
	foreach (user_shelves($owner->user_uuid) as $sh) {
		echo "<entry><updated>$cdt</updated><id>tag:shelf:$sh->id</id><title>" . h($sh->name) . "</title>";
		echo "<content type='text'>$sh->cnt " . plural_ru($sh->cnt, 'книга', 'книги', 'книг') . "</content>";
		echo "<link href='$base&amp;shelf=$sh->id' type='application/atom+xml;profile=opds-catalog;kind=acquisition' /></entry>";
	}
	foreach (BOOK_STATUSES as $code => [$label]) {
		echo "<entry><updated>$cdt</updated><id>tag:status:$owner->user_uuid:$code</id><title>$label</title>";
		echo "<content type='text'></content>";
		echo "<link href='$base&amp;status=$code' type='application/atom+xml;profile=opds-catalog;kind=acquisition' /></entry>";
	}
} else {
	if ($shelf) {
		$books = $dbh->prepare("SELECT b.* FROM shelf_book x JOIN libbook b USING(bookid)
			WHERE x.shelf_id=:id ORDER BY x.added DESC");
		$books->execute([':id' => $shelf->id]);
	} else {
		$books = $dbh->prepare("SELECT b.* FROM book_status x JOIN libbook b USING(bookid)
			WHERE x.user_uuid=:u AND x.status=:s ORDER BY x.updated DESC");
		$books->execute([':u' => $owner->user_uuid, ':s' => $status]);
	}
	while ($b = $books->fetch()) {
		opds_book($b, $webroot);
	}
}

echo "</feed>";
