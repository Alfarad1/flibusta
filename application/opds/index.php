<?php
switch ($url->action) {
	case 'list':
		include(__DIR__ . '/list.php');
		break;
	case 'authorsindex':
		include(__DIR__ . '/authorsindex.php');
		break;
	case 'author':
		include(__DIR__ . '/author.php');
		break;
	case 'sequencesindex':
		include(__DIR__ . '/sequencesindex.php');
		break;
	case 'genres':
		include(__DIR__ . '/genres.php');
		break;
	case 'listgenres':
		include(__DIR__ . '/listgenres.php');
		break;
	case 'fav':
		include(__DIR__ . '/fav.php');
		break;
	case 'favs':
		include(__DIR__ . '/favs.php');
		break;
	case 'search':
		include(__DIR__ . '/search.php');
		break;

	default:
		include(__DIR__ . '/main.php');
}
