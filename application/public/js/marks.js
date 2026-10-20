// Меню «статус и полки» на карточках книг (marks_menu в shelves.php).
// Ответ сервера содержит готовый вид кнопки и значка статуса.
document.addEventListener('click', function (e) {
	var item = e.target.closest('[data-marks] [data-status], [data-marks] [data-shelf]');
	if (!item) {
		return;
	}
	e.preventDefault();
	var box = item.closest('[data-marks]');
	var bookid = box.dataset.marks;
	var body = new URLSearchParams({bookid: bookid});
	if (item.dataset.status !== undefined) {
		body.set('status', item.dataset.status);
	} else {
		body.set('shelf', item.dataset.shelf);
		body.set('on', item.classList.contains('mark-on') ? '0' : '1');
	}
	item.classList.add('disabled');
	// статус у книги один — после выбора меню закрываем; полок можно отметить несколько
	if (item.dataset.status !== undefined) {
		bootstrap.Dropdown.getOrCreateInstance(box.querySelector('.mark-button')).hide();
	}
	fetch(MARK_URL, {method: 'POST', body: body, credentials: 'same-origin'})
		.then(function (r) {
			if (!r.ok) {
				// текст ошибки от сервера (например, WebDAV для полки «На Kindle»)
				return r.json().catch(function () {
					return {};
				}).then(function (res) {
					throw new Error(res.error || '');
				});
			}
			return r.json();
		})
		.then(function (res) {
			// одна книга может быть на странице несколько раз
			document.querySelectorAll('[data-marks="' + bookid + '"]').forEach(function (b) {
				b.querySelectorAll('[data-status]').forEach(function (a) {
					a.classList.toggle('mark-on', +a.dataset.status === res.status);
				});
				b.querySelectorAll('[data-shelf]').forEach(function (a) {
					a.classList.toggle('mark-on', res.shelves.indexOf(+a.dataset.shelf) >= 0);
				});
				var btn = b.querySelector('.mark-button');
				// класс show нужно сохранить: по нему Bootstrap находит открытое меню,
				// чтобы закрыть его кликом снаружи
				var shown = btn.classList.contains('show');
				btn.className = 'btn btn-sm mark-button ' + res.button.class + (shown ? ' show' : '');
				btn.innerHTML = res.button.html;
				btn.title = res.button.title;
			});
			document.querySelectorAll('[data-status-badge="' + bookid + '"]').forEach(function (b) {
				b.innerHTML = res.badge;
			});
		})
		.catch(function (err) {
			alert(err.message || 'Не удалось сохранить, попробуйте ещё раз');
		})
		.finally(function () {
			item.classList.remove('disabled');
		});
});

// Открытое меню закрывается, когда мышь уходит с кнопки и меню
// (с небольшой задержкой, чтобы не закрыть его при случайном движении)
document.addEventListener('mouseout', function (e) {
	var box = e.target.closest('[data-marks]');
	if (!box || box.contains(e.relatedTarget)) {
		return;
	}
	var btn = box.querySelector('.mark-button.show');
	if (!btn) {
		return;
	}
	clearTimeout(box.closeTimer);
	box.closeTimer = setTimeout(function () {
		if (!box.matches(':hover')) {
			bootstrap.Dropdown.getOrCreateInstance(btn).hide();
		}
	}, 400);
});
