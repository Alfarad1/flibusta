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
	fetch(MARK_URL, {method: 'POST', body: body, credentials: 'same-origin'})
		.then(function (r) {
			if (!r.ok) {
				throw new Error(r.status);
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
				btn.className = 'btn btn-sm mark-button ' + res.button.class;
				btn.innerHTML = res.button.html;
				btn.title = res.button.title;
			});
			document.querySelectorAll('[data-status-badge="' + bookid + '"]').forEach(function (b) {
				b.innerHTML = res.badge;
			});
		})
		.catch(function () {
			alert('Не удалось сохранить, попробуйте ещё раз');
		})
		.finally(function () {
			item.classList.remove('disabled');
		});
});
