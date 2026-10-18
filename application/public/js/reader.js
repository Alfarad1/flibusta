// Читалка (modules/read/index.php). Настройки — в localStorage (свои на каждом
// устройстве), позиция чтения — на сервере у пользователя.
// Позиция FB2 — «p:номер блока:доля внутри блока»: она не зависит от шрифта,
// ширины окна и подгрузки картинок, в отличие от процента прокрутки.
(function () {
	'use strict';
	var R = window.READER;
	var root = document.documentElement;
	var bar = document.getElementById('bar');
	var pct = document.getElementById('pct');
	var DEFAULTS = {theme: 'day', font: 'serif', size: 19, lh: 1.6, width: 'medium', align: 'justify'};
	var settings = Object.assign({}, DEFAULTS, load('reader-settings'));

	function load(key) {
		try {
			return JSON.parse(localStorage.getItem(key) || '{}');
		} catch (e) {
			return {};
		}
	}

	function barHeight() {
		return bar.classList.contains('hide') ? 0 : bar.offsetHeight;
	}

	/* ---------- настройки ---------- */

	var onSettingsChange = function () {};

	function applySettings() {
		root.dataset.theme = settings.theme;
		root.dataset.font = settings.font;
		root.dataset.width = settings.width;
		root.dataset.align = settings.align;
		root.style.setProperty('--r-size', settings.size + 'px');
		root.style.setProperty('--r-lh', settings.lh);
		document.querySelectorAll('[data-setting]').forEach(function (g) {
			g.querySelectorAll('[data-value]').forEach(function (b) {
				b.classList.toggle('active', b.dataset.value === String(settings[g.dataset.setting]));
			});
		});
		document.querySelectorAll('[data-show]').forEach(function (el) {
			var v = settings[el.dataset.show];
			el.textContent = el.dataset.show === 'size' ? v + ' px' : v.toFixed(1);
		});
		localStorage.setItem('reader-settings', JSON.stringify(settings));
	}

	function changeSetting(key, value) {
		var keep = getLoc();
		settings[key] = value;
		applySettings();
		onSettingsChange();
		if (keep) {
			goToLoc(keep);
		}
	}

	function step(key, delta) {
		var limits = {size: [12, 40], lh: [1.1, 2.4]}[key];
		var v = Math.round((settings[key] + delta) * 10) / 10;
		changeSetting(key, Math.min(limits[1], Math.max(limits[0], v)));
	}

	document.querySelectorAll('[data-setting] [data-value]').forEach(function (b) {
		b.addEventListener('click', function () {
			changeSetting(b.closest('[data-setting]').dataset.setting, b.dataset.value);
		});
	});
	document.querySelectorAll('[data-step] [data-delta]').forEach(function (b) {
		b.addEventListener('click', function () {
			step(b.closest('[data-step]').dataset.step, parseFloat(b.dataset.delta));
		});
	});
	document.getElementById('settings-reset').addEventListener('click', function () {
		var keep = getLoc();
		settings = Object.assign({}, DEFAULTS);
		applySettings();
		onSettingsChange();
		if (keep) {
			goToLoc(keep);
		}
	});
	applySettings();

	/* ---------- позиция чтения ---------- */

	var text = document.getElementById('text');
	var blocks = text ? Array.prototype.slice.call(text.querySelectorAll(
		'p, h1, h2, h3, h4, h5, h6, .image, .cover-page, tr, .empty-line')) : [];
	// getLoc/goToLoc/percent переопределяются для EPUB
	var getLoc = function () {
		if (!blocks.length) {
			return null;
		}
		var top = barHeight();
		// первый блок, нижний край которого ниже верха видимой области
		var lo = 0, hi = blocks.length - 1;
		while (lo < hi) {
			var mid = (lo + hi) >> 1;
			if (blocks[mid].getBoundingClientRect().bottom <= top) {
				lo = mid + 1;
			} else {
				hi = mid;
			}
		}
		var r = blocks[lo].getBoundingClientRect();
		var frac = r.height > 0 ? Math.min(1, Math.max(0, (top - r.top) / r.height)) : 0;
		return 'p:' + lo + ':' + frac.toFixed(3);
	};
	var goToLoc = function (loc) {
		var m = /^p:(\d+):([\d.]+)$/.exec(loc || '');
		if (!m || !blocks[+m[1]]) {
			return false;
		}
		var el = blocks[+m[1]];
		var y = el.getBoundingClientRect().top + window.scrollY + el.offsetHeight * parseFloat(m[2]);
		window.scrollTo(0, Math.max(0, y - barHeight()));
		return true;
	};
	var percent = function (loc) {
		var m = /^p:(\d+):([\d.]+)$/.exec(loc || '');
		if (!m || !blocks.length) {
			return 0;
		}
		if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 2) {
			return 100;
		}
		return Math.min(100, (+m[1] + parseFloat(m[2])) / blocks.length * 100);
	};

	var lastSaved = R.loc;
	function save(beacon) {
		var loc = getLoc();
		if (!loc) {
			return;
		}
		var p = percent(loc);
		pct.textContent = Math.floor(p) + '%';
		if (!R.loggedIn || loc === lastSaved) {
			return;
		}
		lastSaved = loc;
		var body = new URLSearchParams({bookid: R.bookid, pos: p.toFixed(2), loc: loc});
		if (beacon && navigator.sendBeacon) {
			navigator.sendBeacon(R.saveUrl, body);
		} else {
			fetch(R.saveUrl, {method: 'POST', body: body, credentials: 'same-origin', keepalive: true});
		}
	}

	var saveTimer;
	function scheduleSave() {
		clearTimeout(saveTimer);
		saveTimer = setTimeout(save, 1000);
	}
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'hidden') {
			save(true);
		}
	});
	window.addEventListener('pagehide', function () {
		save(true);
	});

	/* ---------- панель: прячется при прокрутке вниз ---------- */

	var lastY = window.scrollY;
	window.addEventListener('scroll', function () {
		var y = window.scrollY;
		if (y > lastY + 4 && y > 80) {
			bar.classList.add('hide');
		} else if (y < lastY - 4) {
			bar.classList.remove('hide');
		}
		lastY = y;
		scheduleSave();
		updateTocCurrent();
	}, {passive: true});

	// на сенсорных экранах касание текста показывает или прячет панель
	if (window.matchMedia('(pointer: coarse)').matches) {
		document.addEventListener('click', function (e) {
			if (!e.target.closest('a, button, .note-pop, .offcanvas, input')) {
				bar.classList.toggle('hide');
			}
		});
	}

	/* ---------- клавиши ---------- */

	function page(dir) {
		var line = settings.size * settings.lh;
		window.scrollBy({top: dir * (window.innerHeight - barHeight() - line * 2), behavior: 'instant'});
	}
	var pageFn = page;
	document.addEventListener('keydown', function (e) {
		// события из iframe EPUB пересылаются с target = document
		if (e.ctrlKey || e.metaKey || e.altKey || (e.target.closest && e.target.closest('input, textarea, select'))) {
			return;
		}
		var open = document.querySelector('.offcanvas.show');
		switch (e.key) {
			case ' ':
			case 'PageDown':
			case 'ArrowRight':
				if (open) return;
				e.preventDefault();
				pageFn(e.shiftKey && e.key === ' ' ? -1 : 1);
				break;
			case 'PageUp':
			case 'ArrowLeft':
				if (open) return;
				e.preventDefault();
				pageFn(-1);
				break;
			case '+':
			case '=':
				step('size', 1);
				break;
			case '-':
				step('size', -1);
				break;
			case 't':
			case 'T':
			case 'е':
			case 'Е':
				bootstrap.Offcanvas.getOrCreateInstance('#toc').toggle();
				break;
			case 's':
			case 'S':
			case 'ы':
			case 'Ы':
				bootstrap.Offcanvas.getOrCreateInstance('#settings').toggle();
				break;
			case 'Escape':
				hideNote();
				break;
		}
	});

	/* ---------- оглавление ---------- */

	var tocLinks = Array.prototype.slice.call(document.querySelectorAll('#toc-list a[href^="#"]'));
	var tocTargets = tocLinks.map(function (a) {
		return document.getElementById(a.getAttribute('href').slice(1));
	});
	var tocTimer;
	function updateTocCurrent() {
		if (!tocLinks.length) {
			return;
		}
		clearTimeout(tocTimer);
		tocTimer = setTimeout(function () {
			var cur = -1;
			for (var i = 0; i < tocTargets.length; i++) {
				if (tocTargets[i] && tocTargets[i].getBoundingClientRect().top <= barHeight() + 10) {
					cur = i;
				}
			}
			tocLinks.forEach(function (a, i) {
				a.classList.toggle('current', i === cur);
			});
		}, 100);
	}

	function scrollToElement(el) {
		bar.classList.remove('hide');
		window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - bar.offsetHeight - 8);
	}

	document.getElementById('toc').addEventListener('click', function (e) {
		var a = e.target.closest('a[href^="#"]');
		if (!a || !text) {
			return;
		}
		e.preventDefault();
		bootstrap.Offcanvas.getOrCreateInstance('#toc').hide();
		var el = document.getElementById(a.getAttribute('href').slice(1));
		if (el) {
			scrollToElement(el);
		}
	});
	document.getElementById('toc').addEventListener('shown.bs.offcanvas', function () {
		var cur = document.querySelector('#toc-list a.current');
		if (cur) {
			cur.scrollIntoView({block: 'center'});
		}
	});

	/* ---------- сноски и внутренние ссылки ---------- */

	var notesEl = document.getElementById('notes-data');
	var notes = notesEl ? JSON.parse(notesEl.textContent) : {};
	var pop = document.getElementById('note-pop');

	function hideNote() {
		pop.hidden = true;
	}
	pop.querySelector('.btn-close').addEventListener('click', hideNote);

	if (text) {
		text.addEventListener('click', function (e) {
			var a = e.target.closest('a[href^="#"]');
			if (!a) {
				if (!pop.hidden && !e.target.closest('.note-pop')) {
					hideNote();
				}
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			if (a.dataset.note && notes[a.dataset.note] !== undefined) {
				pop.querySelector('.note-body').innerHTML = notes[a.dataset.note];
				pop.hidden = false;
				pop.scrollTop = 0;
				return;
			}
			var el = document.getElementById(a.getAttribute('href').slice(1));
			if (el) {
				scrollToElement(el);
			}
		});
	}
	document.addEventListener('click', function (e) {
		if (!pop.hidden && !e.target.closest('.note-pop, .note-ref')) {
			hideNote();
		}
	});

	/* ---------- конец книги ---------- */

	var markRead = document.getElementById('mark-read');
	function showRead(done) {
		if (markRead && done) {
			markRead.disabled = true;
			markRead.innerHTML = '<i class="fas fa-check"></i> Прочитано';
		}
	}
	showRead(R.status === R.statusRead);
	if (markRead) {
		markRead.addEventListener('click', function () {
			markRead.disabled = true;
			fetch(R.markUrl, {method: 'POST', body: new URLSearchParams({bookid: R.bookid, status: R.statusRead}),
				credentials: 'same-origin'})
				.then(function (r) {
					if (!r.ok) {
						throw new Error(r.status);
					}
					showRead(true);
				})
				.catch(function () {
					markRead.disabled = false;
					alert('Не удалось сохранить, попробуйте ещё раз');
				});
		});
	}

	/* ---------- EPUB ---------- */

	if (R.format === 'epub') {
		var host = document.getElementById('epub');
		var book = ePub({replacements: 'blobUrl'});
		book.open(R.fileUrl, 'epub');
		var rendition = book.renderTo(host, {flow: 'scrolled-doc', width: '100%', allowScriptedContent: false});
		var THEMES = {
			day: {body: {background: '#fff', color: '#222'}},
			sepia: {body: {background: '#f4ecd8', color: '#3b2f1e'}},
			night: {body: {background: '#1d1f21', color: '#c8c8c8'}, a: {color: '#8ab4f8'}}
		};
		Object.keys(THEMES).forEach(function (k) {
			rendition.themes.register(k, THEMES[k]);
		});
		var FONTS = {
			serif: 'Georgia, "PT Serif", "Noto Serif", serif',
			sans: 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif'
		};
		onSettingsChange = function () {
			rendition.themes.select(settings.theme);
			rendition.themes.override('font-family', FONTS[settings.font]);
			rendition.themes.override('font-size', settings.size + 'px');
			rendition.themes.override('line-height', String(settings.lh));
			rendition.themes.override('text-align', settings.align === 'justify' ? 'justify' : 'left');
			rendition.themes.override('hyphens', settings.align === 'justify' ? 'auto' : 'manual');
		};
		onSettingsChange();

		var current = null;
		getLoc = function () {
			return current ? current.start.cfi : null;
		};
		goToLoc = function () {
			// у EPUB позиция привязана к тексту (CFI), после смены шрифта её не нужно восстанавливать
			return true;
		};
		percent = function () {
			if (!current) {
				return 0;
			}
			if (current.atEnd) {
				return 100;
			}
			return book.locations.length() ? current.start.percentage * 100 : 0;
		};
		pageFn = function (dir) {
			// в режиме scrolled-doc глава — отдельная страница: в её конце переходим к следующей
			var atEnd = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 2;
			if (dir > 0 && atEnd) {
				rendition.next().then(function () { window.scrollTo(0, 0); });
			} else if (dir < 0 && window.scrollY <= 0) {
				rendition.prev().then(function () { window.scrollTo(0, document.documentElement.scrollHeight); });
			} else {
				page(dir);
			}
		};
		rendition.on('relocated', function (loc) {
			current = loc;
			scheduleSave();
		});
		rendition.on('keydown', function (e) {
			document.dispatchEvent(new KeyboardEvent('keydown', {key: e.key, shiftKey: e.shiftKey}));
		});
		rendition.display(R.loc || undefined).then(function () {
			// проценты считаются по разметке книги на «страницы» — в фоне
			book.locations.generate(1600).then(function () {
				if (current) {
					current = rendition.currentLocation();
					pct.textContent = Math.floor(percent()) + '%';
				}
			});
		});
		book.loaded.navigation.then(function (nav) {
			var list = document.getElementById('toc-list');
			list.innerHTML = '';
			(function add(items, level) {
				items.forEach(function (it) {
					var a = document.createElement('a');
					a.href = '#';
					a.className = 'toc-l' + Math.min(level, 4);
					a.textContent = it.label.trim();
					a.addEventListener('click', function (e) {
						e.preventDefault();
						bootstrap.Offcanvas.getOrCreateInstance('#toc').hide();
						rendition.display(it.href).then(function () { window.scrollTo(0, 0); });
					});
					list.appendChild(a);
					if (it.subitems && it.subitems.length) {
						add(it.subitems, level + 1);
					}
				});
			})(nav.toc, 1);
			if (!nav.toc.length) {
				list.textContent = 'В книге нет оглавления.';
			}
		});
		// «Конец книги» — только после последней главы
		var end = document.getElementById('end');
		end.hidden = true;
		rendition.on('relocated', function (loc) {
			end.hidden = !loc.atEnd;
		});
		return;
	}

	/* ---------- восстановление позиции FB2 ---------- */

	function restore() {
		if (!goToLoc(R.loc) && R.pos > 0) {
			// старый формат — процент прокрутки
			window.scrollTo(0, document.documentElement.scrollHeight * R.pos / 100);
		}
		var loc = getLoc();
		pct.textContent = loc ? Math.floor(percent(loc)) + '%' : '';
		lastY = window.scrollY;
		updateTocCurrent();
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', restore);
	} else {
		restore();
	}
	// при повороте телефона и изменении окна место чтения сохраняется
	var resizeLoc = null, resizeTimer;
	window.addEventListener('resize', function () {
		if (resizeLoc === null) {
			resizeLoc = getLoc();
		}
		clearTimeout(resizeTimer);
		resizeTimer = setTimeout(function () {
			goToLoc(resizeLoc);
			resizeLoc = null;
		}, 150);
	});
})();
