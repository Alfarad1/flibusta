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
