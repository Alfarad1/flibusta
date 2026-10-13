<?php
// Полнотекстовый поиск по libbook_ts, libavtorname_ts, libseqname_ts
// (векторы строит tools/update_vectors.sql) и подсказки при опечатках через pg_trgm.

define('SEARCH_MAX_WORDS', 8);
define('SEARCH_PREFIX_MIN', 3);
// порог похожести для подсказок; стандартные 0.6 не ловят даже перестановку двух букв
define('SEARCH_SUGGEST_THRESHOLD', 0.4);

function search_normalize($q) {
	$q = mb_strtolower(trim((string)$q));
	$q = str_replace('ё', 'е', $q);
	return preg_replace('/\s+/u', ' ', $q);
}

function search_words($q) {
	preg_match_all('/[\p{L}\p{N}]+/u', search_normalize($q), $m);
	return array_slice($m[0], 0, SEARCH_MAX_WORDS);
}

/*
 SQL-выражение tsquery для запроса, параметры дописываются в $params.
 Должны найтись все слова. Слово совпадает по словоформе (russian) или как есть
 (simple, так находятся стоп-слова вроде «Мы»), слова от SEARCH_PREFIX_MIN букв —
 ещё и по началу («потт» найдёт «Поттер»). Пустое tsquery от стоп-слова
 в || поглощается вторым операндом.
 Возвращает null, если в запросе нет ни одного слова.
*/
function search_tsquery_sql($q, &$params, $name = 'q', $prefix = true) {
	$parts = [];
	foreach (search_words($q) as $i => $w) {
		$params[":{$name}r$i"] = $w;
		$params[":{$name}s$i"] = $prefix && mb_strlen($w) >= SEARCH_PREFIX_MIN ? "$w:*" : $w;
		$parts[] = "(plainto_tsquery('russian', :{$name}r$i) || to_tsquery('simple', :{$name}s$i))";
	}
	if (!$parts) {
		return null;
	}
	return '(' . implode(' && ', $parts) . ')';
}

/*
 Порядок книг по релевантности: сначала точное совпадение названия, потом
 совпадение целых слов (иначе «Мастерская» обгоняет «Мастера и Маргариту»),
 потом с учётом начала слов; название весит больше авторов и серий.
 Ожидает алиасы b (libbook), ts (libbook_ts), sq (подзапрос с query).
*/
function search_books_order_sql($q, &$params) {
	$params[':qexact'] = search_normalize($q);
	$words = search_tsquery_sql($q, $params, 'qw', false);
	return "(translate(lower(b.title), 'ё', 'е') = :qexact) DESC, ts_rank(ts.vector, $words) DESC, "
		. "ts_rank(ts.vector, sq.query) DESC, b.title";
}

// Похожие названия книг (опечатки). Без pg_trgm просто ничего не подсказываем.
function search_suggest_titles($dbh, $q, $limit = 5) {
	$q = search_normalize($q);
	if (mb_strlen($q) < SEARCH_PREFIX_MIN) {
		return [];
	}
	try {
		$dbh->exec("SET pg_trgm.word_similarity_threshold = " . SEARCH_SUGGEST_THRESHOLD);
		$stmt = $dbh->prepare("SELECT title FROM (
				SELECT DISTINCT ON (lower(title)) title, word_similarity(:q1, lower(title)) sim
				FROM libbook
				WHERE deleted='0' AND lower(title) %> :q2
				ORDER BY lower(title), sim DESC
			) t
			ORDER BY sim DESC, title
			LIMIT " . intval($limit));
		$stmt->execute([':q1' => $q, ':q2' => $q]);
		return $stmt->fetchAll(PDO::FETCH_COLUMN);
	} catch (PDOException $e) {
		error_log('search_suggest_titles: ' . $e->getMessage());
		return [];
	}
}

// Похожие имена авторов (опечатки), только авторы, у которых есть книги.
function search_suggest_authors($dbh, $q, $limit = 5) {
	$q = search_normalize($q);
	if (mb_strlen($q) < SEARCH_PREFIX_MIN) {
		return [];
	}
	try {
		$dbh->exec("SET pg_trgm.word_similarity_threshold = " . SEARCH_SUGGEST_THRESHOLD);
		$stmt = $dbh->prepare("SELECT avtorid, lastname, firstname, middlename, nickname
			FROM libavtorname an
			WHERE lower(firstname || ' ' || lastname) %> :q1
				AND EXISTS (SELECT 1 FROM libavtor la JOIN libbook b USING(bookid)
					WHERE la.avtorid=an.avtorid AND b.deleted='0')
			ORDER BY word_similarity(:q2, lower(firstname || ' ' || lastname)) DESC, lastname
			LIMIT " . intval($limit));
		$stmt->execute([':q1' => $q, ':q2' => $q]);
		return $stmt->fetchAll();
	} catch (PDOException $e) {
		error_log('search_suggest_authors: ' . $e->getMessage());
		return [];
	}
}

// Блок «Возможно, вы искали» для веб-интерфейса; пустая строка, если подсказать нечего.
function search_suggestions_html($dbh, $q, $webroot, $titles = true) {
	$items = [];
	if ($titles) {
		foreach (search_suggest_titles($dbh, $q) as $t) {
			$items[] = "<a class='badge bg-light text-dark border p-2' href='$webroot/?q=" . urlencode($t) . "'>"
				. htmlspecialchars($t) . "</a>";
		}
	}
	foreach (search_suggest_authors($dbh, $q) as $a) {
		$name = trim("$a->lastname $a->firstname $a->middlename $a->nickname");
		$items[] = "<a class='badge rounded-pill author p-2' href='$webroot/author/view/$a->avtorid'>"
			. htmlspecialchars($name) . "</a>";
	}
	if (!$items) {
		return '';
	}
	return "<div class='mb-3'>Возможно, вы искали: " . implode(' ', $items) . "</div>";
}
