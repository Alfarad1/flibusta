<?php
// Таблицы пользователей, полок и прогресса чтения. В отличие от таблиц Флибусты
// они не перезаливаются из дампов, поэтому создаются и обновляются здесь:
// при первом запросе после обновления приложения выполняются недостающие миграции.
// Номер выполненной миграции хранится в app_schema.

const SCHEMA_MIGRATIONS = [
	// 1. Пользователи: имя уникально без учёта регистра (раньше можно было
	//    создать две полки с одним именем — дубли переименовываются).
	1 => "
		CREATE TABLE IF NOT EXISTS fav_users (
			user_uuid uuid NOT NULL PRIMARY KEY,
			name varchar(64) NOT NULL
		);
		CREATE TABLE IF NOT EXISTS fav (
			id serial PRIMARY KEY,
			user_uuid uuid,
			bookid bigint,
			avtorid bigint,
			seqid bigint
		);
		CREATE TABLE IF NOT EXISTS progress (
			user_uuid uuid NOT NULL,
			bookid bigint NOT NULL,
			pos double precision,
			PRIMARY KEY (user_uuid, bookid)
		);
		ALTER TABLE fav_users ALTER COLUMN name TYPE varchar(64);
		ALTER TABLE fav_users ADD COLUMN IF NOT EXISTS created timestamptz NOT NULL DEFAULT now();
		UPDATE fav_users u SET name = left(trim(u.name), 56) || ' (' || d.rn || ')'
			FROM (SELECT user_uuid, row_number() OVER (PARTITION BY lower(trim(name)) ORDER BY user_uuid) rn
				FROM fav_users) d
			WHERE d.user_uuid = u.user_uuid AND d.rn > 1;
		UPDATE fav_users SET name = trim(name) WHERE name <> trim(name);
		CREATE UNIQUE INDEX IF NOT EXISTS i_fav_users_name ON fav_users (lower(name));
	",
];

function schema_ensure() {
	global $dbh;
	$last = max(array_keys(SCHEMA_MIGRATIONS));
	try {
		$version = (int)$dbh->query("SELECT version FROM app_schema")->fetchColumn();
	} catch (PDOException $e) {
		$version = 0;
	}
	if ($version >= $last) {
		return;
	}
	$dbh->beginTransaction();
	try {
		// одновременные первые запросы не должны выполнять миграции дважды
		$dbh->exec("SELECT pg_advisory_xact_lock(7140501)");
		$dbh->exec("CREATE TABLE IF NOT EXISTS app_schema (version integer NOT NULL)");
		$version = $dbh->query("SELECT version FROM app_schema")->fetchColumn();
		if ($version === false) {
			$dbh->exec("INSERT INTO app_schema VALUES (0)");
			$version = 0;
		}
		foreach (SCHEMA_MIGRATIONS as $n => $sql) {
			if ($n > $version) {
				$dbh->exec($sql);
				$dbh->exec("UPDATE app_schema SET version = $n");
			}
		}
		$dbh->commit();
	} catch (PDOException $e) {
		$dbh->rollBack();
		error_log('schema migration failed: ' . $e->getMessage());
		throw $e;
	}
}
