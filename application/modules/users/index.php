<?php
// Выбор пользователя («Кто читает?»), создание, переименование и удаление.
// Паролей нет: сайт для своих, вход — просто выбор имени.

// куда вернуться после входа: локальный путь, с которого пришли
$return = $_POST['return'] ?? $_GET['return'] ?? '';
if (!preg_match('#^/(?![/\\\\])#', $return) || str_starts_with($return, "$webroot/users")) {
	$return = '';
}
$error = '';
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? '') : '';

if ($action === 'login') {
	if (user_login($_POST['uuid'] ?? '')) {
		header('Location: ' . ($return ?: "$webroot/fav/"));
		exit;
	}
	$error = 'Такого пользователя нет';
} elseif ($action === 'create') {
	[$uuid, $error] = user_create($_POST['name'] ?? '');
	if ($uuid) {
		user_login($uuid);
		header('Location: ' . ($return ?: "$webroot/fav/"));
		exit;
	}
} elseif ($action === 'logout') {
	user_logout();
	header("Location: $webroot/users/");
	exit;
} elseif ($action === 'rename' && $user_uuid !== '') {
	$error = user_rename($user_uuid, $_POST['name'] ?? '');
	if (!$error) {
		header("Location: $webroot/users/");
		exit;
	}
} elseif ($action === 'delete' && $user_uuid !== '') {
	// удалить можно только себя, имя нужно ввести для подтверждения
	if (mb_strtolower(trim($_POST['confirm'] ?? '')) === mb_strtolower($user_name)) {
		user_delete($user_uuid);
		user_logout();
		header("Location: $webroot/users/");
		exit;
	}
	$error = 'Для удаления введите своё имя точно так же, как оно написано';
}

$users = user_list();
$counts = [];
foreach ($dbh->query("SELECT u.user_uuid,
		(SELECT COUNT(*) FROM shelf s WHERE s.user_uuid=u.user_uuid) shelves,
		(SELECT COUNT(DISTINCT sb.bookid) FROM shelf s JOIN shelf_book sb ON sb.shelf_id=s.id WHERE s.user_uuid=u.user_uuid) books,
		(SELECT COUNT(*) FROM book_status st WHERE st.user_uuid=u.user_uuid AND st.status=" . STATUS_READ . ") done
	FROM fav_users u") as $r) {
	$counts[$r->user_uuid] = $r;
}
$ret = $return !== '' ? "<input type='hidden' name='return' value='" . h($return) . "'>" : '';

echo "<h3 class='mb-3'>Кто читает?</h3>";
if ($error) {
	echo "<div class='alert alert-warning'>" . h($error) . "</div>";
}

echo "<div class='row'>";
foreach ($users as $u) {
	$c = $counts[$u->user_uuid] ?? null;
	$me = $u->user_uuid === $user_uuid;
	echo "<div class='col-sm-6 col-lg-4 mb-3'>";
	echo "<div class='card h-100" . ($me ? ' border-success' : '') . "'>";
	echo "<div class='card-body d-flex align-items-center'>";
	echo "<div class='me-auto'><h5 class='card-title mb-1'><i class='fas fa-user'></i> " . h($u->name) . "</h5>";
	echo "<div class='small text-muted'>";
	echo "<a href='$webroot/fav/" . ($me ? '' : "?user=$u->user_uuid") . "'>Полок: " . ($c->shelves ?? 0) . '</a>, книг на полках: ' . ($c->books ?? 0) . ', прочитано: ' . ($c->done ?? 0);
	echo "</div></div>";
	if ($me) {
		echo "<span class='badge bg-success'>это вы</span>";
	} else {
		echo "<form method='post' class='ms-2'>$ret<input type='hidden' name='action' value='login'>";
		echo "<input type='hidden' name='uuid' value='" . h($u->user_uuid) . "'>";
		echo "<button class='btn btn-primary'>Войти</button></form>";
	}
	echo "</div></div></div>";
}
echo "</div>";

echo "<div class='card mb-3'><div class='card-body'>";
echo "<form method='post'>$ret<input type='hidden' name='action' value='create'>";
echo "<label class='form-label' for='new-user'>Новый пользователь</label>";
echo "<div class='input-group'>";
echo "<input id='new-user' name='name' class='form-control' maxlength='" . USER_NAME_MAX . "' placeholder='Имя' required>";
echo "<button class='btn btn-success'>Создать и войти</button>";
echo "</div></form>";
echo "</div></div>";

if ($user_uuid !== '') {
	echo "<div class='card mb-3'><h5 class='card-header'>" . h($user_name) . "</h5><div class='card-body'>";

	echo "<form method='post' class='mb-3'><input type='hidden' name='action' value='logout'>";
	echo "<button class='btn btn-outline-secondary'><i class='fas fa-sign-out-alt'></i> Выйти</button></form>";

	echo "<form method='post' class='mb-3'><input type='hidden' name='action' value='rename'>";
	echo "<label class='form-label' for='rename-user'>Изменить имя</label>";
	echo "<div class='input-group'>";
	echo "<input id='rename-user' name='name' class='form-control' maxlength='" . USER_NAME_MAX . "' value='" . h($user_name) . "' required>";
	echo "<button class='btn btn-outline-primary'>Сохранить</button>";
	echo "</div></form>";

	echo "<details><summary class='text-danger'>Удалить пользователя</summary>";
	echo "<form method='post' class='mt-2'><input type='hidden' name='action' value='delete'>";
	echo "<p class='small mb-2'>Удалятся полки, статусы книг, избранное и прогресс чтения. Для подтверждения введите имя «" . h($user_name) . "».</p>";
	echo "<div class='input-group'>";
	echo "<input name='confirm' class='form-control' autocomplete='off' required>";
	echo "<button class='btn btn-danger'>Удалить</button>";
	echo "</div></form></details>";

	echo "</div></div>";
}
