<?php
// searchTerm — имя параметра из старого описания OpenSearch, его могли запомнить читалки
if (!isset($_GET['q']) && isset($_GET['searchTerm'])) {
	$_GET['q'] = $_GET['searchTerm'];
}
$search = $_GET['by'] ?? '';
switch ($search) {
	case 'author':
		include('search_author.php');
		break;

	default:
		include('search_book.php');
}
?>