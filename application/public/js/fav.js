// Кнопки «в избранное» (fav_link в functions.php): переключаются запросом
// к fav.php без перезагрузки. Если запрос не удался — переход по обычной ссылке.
document.addEventListener('click', function (e) {
	var a = e.target.closest('a[data-fav-type]');
	if (!a || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) {
		return;
	}
	e.preventDefault();
	if (a.classList.contains('disabled')) {
		return;
	}
	var on = a.dataset.fav !== '1';
	var body = new URLSearchParams({type: a.dataset.favType, id: a.dataset.favId, on: on ? '1' : '0'});
	a.classList.add('disabled');
	fetch(FAV_URL, {method: 'POST', body: body, credentials: 'same-origin'})
		.then(function (r) {
			if (!r.ok) {
				throw new Error(r.status);
			}
			return r.json();
		})
		.then(function (res) {
			a.classList.remove('disabled');
			var state = res.fav ? 'on' : 'off';
			a.classList.remove(a.dataset[res.fav ? 'offClass' : 'onClass']);
			a.classList.add(a.dataset[state + 'Class']);
			a.innerHTML = a.dataset[state + 'Html'];
			a.dataset.fav = res.fav ? '1' : '0';
			// ссылка без JS должна вести к следующему переключению
			a.href = a.href.replace(/([?&])(un)?fav_/, res.fav ? '$1unfav_' : '$1fav_');
		})
		.catch(function () {
			window.location = a.href;
		});
});
