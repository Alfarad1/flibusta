-- Производные таблицы: полнотекстовые индексы для поиска (ниже),
-- рейтинги и рекомендации читателей, издания одной книги (в конце файла).
--
-- Полнотекстовые индексы для поиска.
-- Каждый текст индексируется дважды: конфигурацией russian (словоформы:
-- «маргариты» найдёт «Маргарита») и simple (слова как есть: стоп-слова вроде
-- «Мы» не теряются, работает поиск по началу слова). Ё заменяется на е.
-- Скрипт можно запускать повторно: изменённые записи обновляются,
-- записи удалённых книг, авторов и серий вычищаются.

CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- Книги: название (вес A), авторы (B), серии (C)
WITH a AS (
	SELECT la.bookid,
		string_agg(concat_ws(' ', an.firstname, an.middlename, an.lastname, an.nickname), ' ') names
	FROM libavtor la
	JOIN libavtorname an USING(avtorid)
	GROUP BY la.bookid
), s AS (
	SELECT ls.bookid, string_agg(sn.seqname, ' ') names
	FROM libseq ls
	JOIN libseqname sn USING(seqid)
	GROUP BY ls.bookid
), t AS (
	SELECT b.bookid,
		translate(b.title, 'Ёё', 'Ее') title,
		translate(coalesce(a.names, ''), 'Ёё', 'Ее') authors,
		translate(coalesce(s.names, ''), 'Ёё', 'Ее') series
	FROM libbook b
	LEFT JOIN a USING(bookid)
	LEFT JOIN s USING(bookid)
	WHERE b.deleted='0'
)
INSERT INTO libbook_ts (bookid, vector)
SELECT bookid,
	setweight(to_tsvector('russian', title) || to_tsvector('simple', title), 'A') ||
	setweight(to_tsvector('russian', authors) || to_tsvector('simple', authors), 'B') ||
	setweight(to_tsvector('russian', series) || to_tsvector('simple', series), 'C')
FROM t
ON CONFLICT (bookid) DO UPDATE SET vector = EXCLUDED.vector
	WHERE libbook_ts.vector IS DISTINCT FROM EXCLUDED.vector;

DELETE FROM libbook_ts ts
WHERE NOT EXISTS (SELECT 1 FROM libbook b WHERE b.bookid=ts.bookid AND b.deleted='0');

-- Авторы: фамилия, имя, отчество, псевдоним
INSERT INTO libavtorname_ts (avtorid, vector)
SELECT avtorid, to_tsvector('russian', n) || to_tsvector('simple', n)
FROM (
	SELECT avtorid, translate(concat_ws(' ', lastname, firstname, middlename, nickname), 'Ёё', 'Ее') n
	FROM libavtorname
) t
ON CONFLICT (avtorid) DO UPDATE SET vector = EXCLUDED.vector
	WHERE libavtorname_ts.vector IS DISTINCT FROM EXCLUDED.vector;

DELETE FROM libavtorname_ts ts
WHERE NOT EXISTS (SELECT 1 FROM libavtorname a WHERE a.avtorid=ts.avtorid);

-- Серии
INSERT INTO libseqname_ts (seqid, vector)
SELECT seqid, to_tsvector('russian', n) || to_tsvector('simple', n)
FROM (
	SELECT seqid, translate(seqname, 'Ёё', 'Ее') n
	FROM libseqname
) t
ON CONFLICT (seqid) DO UPDATE SET vector = EXCLUDED.vector
	WHERE libseqname_ts.vector IS DISTINCT FROM EXCLUDED.vector;

DELETE FROM libseqname_ts ts
WHERE NOT EXISTS (SELECT 1 FROM libseqname s WHERE s.seqid=ts.seqid);

-- Триграммные индексы для подсказок при опечатках
CREATE INDEX IF NOT EXISTS i_book_title_trgm ON libbook USING gin (lower(title) gin_trgm_ops);
CREATE INDEX IF NOT EXISTS i_avtorname_trgm ON libavtorname
	USING gin (lower(firstname || ' ' || lastname) gin_trgm_ops);

ANALYZE libbook_ts;
ANALYZE libavtorname_ts;
ANALYZE libseqname_ts;

-- Рейтинги и рекомендации читателей Флибусты (librate, librecs).
-- Оценки и рекомендации старых редакций переносятся на книгу, которой их
-- заменили (libjoinedbooks.realid — конец цепочки замен). Если читатель
-- оценил несколько редакций одной книги, учитывается последняя оценка.
-- В book_rating есть строка для каждой книги (у неоценённых votes=0 и
-- score NULL), чтобы сортировка по рейтингу шла по индексу, а не сортировала
-- весь каталог.
CREATE TABLE IF NOT EXISTS book_rating (
	bookid bigint PRIMARY KEY,
	votes integer NOT NULL,
	rating real,
	-- байесовская оценка для сортировки: книга с одной пятёркой
	-- не обгоняет книгу с сотней оценок в среднем 4,8
	score real
);
CREATE INDEX IF NOT EXISTS i_book_rating_score ON book_rating (score DESC NULLS LAST, bookid DESC);

CREATE TABLE IF NOT EXISTS book_recs (
	bookid bigint NOT NULL,
	uid bigint NOT NULL,
	PRIMARY KEY (bookid, uid)
);
CREATE INDEX IF NOT EXISTS i_book_recs_uid ON book_recs (uid);

BEGIN;
DELETE FROM book_rating;
WITH v AS (
	SELECT DISTINCT ON (m.bookid, r.userid) m.bookid, r.rate::text::int rate
	FROM librate r
	LEFT JOIN libjoinedbooks j ON j.badid=r.bookid
	CROSS JOIN LATERAL (SELECT COALESCE(j.realid, r.bookid) bookid) m
	WHERE r.rate BETWEEN '1' AND '5'
	ORDER BY m.bookid, r.userid, r.id DESC
), b AS (
	SELECT bookid, COUNT(*) votes, AVG(rate) rating FROM v GROUP BY bookid
), c AS (
	-- среднее по всей библиотеке и «вес» априорной оценки в голосах
	SELECT AVG(rate) mean, 10 m FROM v
)
INSERT INTO book_rating (bookid, votes, rating, score)
SELECT bk.bookid, COALESCE(b.votes, 0), b.rating, (b.votes * b.rating + c.m * c.mean) / (b.votes + c.m)
FROM libbook bk
LEFT JOIN b USING(bookid)
CROSS JOIN c
WHERE bk.deleted='0';

DELETE FROM book_recs;
INSERT INTO book_recs (bookid, uid)
SELECT DISTINCT COALESCE(j.realid, r.bid), r.uid
FROM librecs r
LEFT JOIN libjoinedbooks j ON j.badid=r.bid
WHERE r.bid IS NOT NULL AND r.uid IS NOT NULL;
COMMIT;

-- Издания одной книги: разные файлы, переиздания и переводы с одинаковым
-- названием и тем же набором авторов на том же языке. В поиске показывается
-- одно издание группы, остальные — списком в карточке.
-- Название сравнивается без регистра, ё, пунктуации и пометок вроде «(сборник)»,
-- номера томов римскими цифрами приводятся к арабским.
-- Чтобы не склеить разные книги:
-- - у названий-заглушек («Том 1», «Стихотворения», «Избранное») должны
--   совпадать ещё и серии (собрания сочинений разные — и тома разные);
-- - книги одной серии с разными номерами — разные тома, а не издания.
-- В таблице только книги, у которых есть другие издания;
-- workid — наименьший bookid группы.
CREATE TABLE IF NOT EXISTS book_editions (
	bookid bigint PRIMARY KEY,
	workid bigint NOT NULL,
	editions integer NOT NULL
);
CREATE INDEX IF NOT EXISTS i_book_editions_workid ON book_editions (workid);

BEGIN;
DELETE FROM book_editions;
WITH a AS (
	SELECT bookid, string_agg(DISTINCT avtorid::text, ',' ORDER BY avtorid::text) au
	FROM libavtor GROUP BY bookid
), s AS (
	SELECT bookid, string_agg(seqid::text, ',' ORDER BY seqid) seqs
	FROM libseq GROUP BY bookid
), n0 AS (
	SELECT b.bookid, lower(trim(b.lang)) lang, a.au, s.seqs,
		trim(regexp_replace(
			regexp_replace(lower(translate(b.title, 'Ёё', 'Ее')),
				'[\(\[]\s*(сборник|авторский сборник|с иллюстрациями|иллюстрированное издание|litres)\s*[\)\]]\s*$', ''),
			'[^[:alnum:]]+', ' ', 'g')) t
	FROM libbook b
	JOIN a USING(bookid)
	LEFT JOIN s USING(bookid)
	WHERE b.deleted='0'
), n AS (
	-- «Том II» = «Том 2»
	SELECT n0.bookid, n0.lang, n0.au, n0.seqs,
		CASE WHEN r.num IS NULL THEN n0.t ELSE regexp_replace(n0.t, '[ivx]+$', r.num::text) END t
	FROM n0
	CROSS JOIN LATERAL (SELECT array_position(ARRAY['i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x'],
		substring(n0.t FROM '(?:^| )(?:том|книга|часть) ([ivx]+)$')) num) r
), k AS (
	SELECT bookid, md5(concat_ws('|', t, au, lang,
		CASE WHEN t ~ '^(том|книга|часть|выпуск|т) ?[0-9ivxlc]+$'
			OR t IN ('избранное', 'избранные произведения', 'избранные сочинения', 'сочинения',
				'собрание сочинений', 'полное собрание сочинений', 'произведения', 'стихотворения',
				'стихи', 'стихотворения и поэмы', 'поэмы', 'сказки', 'рассказы', 'повести',
				'повести и рассказы', 'рассказы и повести', 'басни', 'пьесы', 'статьи', 'письма',
				'дневники', 'мемуары', 'воспоминания')
		THEN coalesce(seqs, '') END)) k
	FROM n
), multi AS (
	SELECT k FROM k GROUP BY k HAVING COUNT(*) > 1
), conflict AS (
	-- серии, в которых книги группы стоят под разными номерами
	SELECT k.k, ls.seqid
	FROM k JOIN multi USING(k) JOIN libseq ls USING(bookid)
	GROUP BY k.k, ls.seqid
	HAVING COUNT(DISTINCT ls.seqnumb) > 1
), vol AS (
	SELECT k.bookid, string_agg(ls.seqid || ':' || ls.seqnumb, ',' ORDER BY ls.seqid) v
	FROM k JOIN conflict c USING(k) JOIN libseq ls ON ls.bookid=k.bookid AND ls.seqid=c.seqid
	GROUP BY k.bookid
), g AS (
	SELECT k.bookid, min(k.bookid) OVER w workid, COUNT(*) OVER w editions
	FROM k JOIN multi USING(k) LEFT JOIN vol USING(bookid)
	WINDOW w AS (PARTITION BY k.k || coalesce(vol.v, ''))
)
INSERT INTO book_editions (bookid, workid, editions)
SELECT bookid, workid, editions FROM g WHERE editions > 1;
COMMIT;

-- сортировка по году издания (без года — в конце)
CREATE INDEX IF NOT EXISTS i_book_year_time ON libbook ((NULLIF(year, 0)) DESC NULLS LAST, time DESC);

ANALYZE book_rating;
ANALYZE book_recs;
ANALYZE book_editions;
