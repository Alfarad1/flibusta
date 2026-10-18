<!doctype html>
<html lang="ru">
<head>

<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<meta name='wmail-verification' content='7404cc552213a233445be2fe20acca8c' />

<?php
	if ($url->description != '') {
		echo "<meta name='description' content='$url->description' />";
	}

	if ($url->title != '') {
		$title = $url->title;
	} else {
		$title = 'Библиотека';
	}
	echo "<title>$title</title>";
	include_once(ROOT_PATH . 'webroot.php');
echo <<< __HTML

<link href="$webroot/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<script src="$webroot/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>var FAV_URL = '$webroot/fav.php';</script>
<script src="$webroot/js/fav.js" defer></script>

<link rel="icon" href="$webroot/favicon.svg" sizes="any" type="image/svg+xml">

<link href="$webroot/css/all.min.css" rel="stylesheet">
<link href="$webroot/css/style.css" rel="stylesheet">
__HTML
?>
<style>
.pagination>li.active>a {
  background-color: #777 !important;
  border-color: #6d6d6d !important;
}

.badge {
    white-space: break-spaces;
}

.author {
        background: #dddddd;
}

.author a {
	color: #333;
	line-height: 24px;
	padding-left: 3px;
}

.contact {
         width: 24px;
         height: 24px;
}

</style>

</head>
<?php
// активный пункт меню; страницы книги и автора относятся к разделам «Книги» и «Авторы»
$menu_mod = [
	'genres' => 'genres', 'authors' => 'authors', 'author' => 'authors',
	'series' => 'series', 'fav' => 'fav', 'users' => 'users', 'service' => 'service',
][$url->mod] ?? 'primary';
$menu = [];
foreach (['primary', 'genres', 'authors', 'series', 'fav', 'service'] as $m) {
	$menu[$m] = $m === $menu_mod ? 'active' : '';
}
// справа — текущий пользователь или вход с возвратом на эту страницу
if ($user_uuid !== '') {
	$user_button = "<a href='$webroot/users/' class='btn btn-outline-success' title='Сменить пользователя'><i class='fas fa-user'></i> " . h($user_name) . "</a>";
} else {
	$back = $url->mod === 'users' ? '' : '?return=' . urlencode($_SERVER['REQUEST_URI'] ?? '');
	$user_button = "<a href='$webroot/users/$back' class='btn btn-outline-light'><i class='fas fa-sign-in-alt'></i> Войти</a>";
}



echo <<< __HTML

<body style='background-color: #343a40;'>

<div class="container whb">
<nav class="navbar navbar-expand-lg navbar-dark rounded-bottom shadow" style="background-color: #3e3b6c;">
<div class="container-fluid">
  <a class="navbar-brand" href="$webroot/" title="Библиотека">
   &nbsp;Библиотека
  </a>
  <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainMenu" aria-controls="mainMenu" aria-expanded="false" aria-label="Меню">
    <span class="navbar-toggler-icon"></span>
  </button>
  <div class="collapse navbar-collapse" id="mainMenu">
		<ul class="navbar-nav mx-auto mb-2 mb-lg-0">
			<li class="nav-item"><a title="" class='nav-link {$menu['primary']}' href="$webroot/">Книги</a></li>
			<li class="nav-item"><a title="" class='nav-link {$menu['genres']}' href="$webroot/genres/">Жанры</a></li>
			<li class="nav-item"><a title="" class='nav-link {$menu['authors']}' href="$webroot/authors/">Авторы</a></li>
			<li class="nav-item"><a title="" class='nav-link {$menu['series']}' href="$webroot/series/">Серии</a></li>
			<li class="nav-item"><a title="" class='nav-link {$menu['fav']}' href="$webroot/fav/">Полка</a></li>
			<li class="nav-item"><a title="" class='nav-link {$menu['service']}' href="$webroot/service/">Сервис</a></li>
		</ul>

<div class="d-flex">

$user_button
__HTML
?>
</div>
  </div>

</div>
</nav>
</div>
<div class="container whb">
<br />
<?php
if (file_exists($url->module)) {
//	echo "<h1>$url->title</h1>";
	include($url->module);
} else {
	echo 'Раздел не найден', 'Вы ввели неверный адрес, либо раздел находится в разработке.';
	header("HTTP/1.0 404 Not Found");
}
?>
<div>&nbsp;</div>
</div>



<div class="container whb rounded-bottom mb-3">
	<nav class="navbar navbar-expand-lg rounded-top shadow navbar-dark bg-dark" style="background-color: #768fa8;">
		<ul class="navbar-nav mr-auto">
		</ul>
	</nav>
</div>




</body>
</html>

