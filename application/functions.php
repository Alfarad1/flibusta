<?php

function bbc2html($content) {
  $search = array (
    '/(\[b\])(.*?)(\[\/b\])/',
    '/(\[i\])(.*?)(\[\/i\])/',
    '/(\[u\])(.*?)(\[\/u\])/',
    '/(\[ul\])(.*?)(\[\/ul\])/',
    '/(\[li\])(.*?)(\[\/li\])/',
    '/(\[url=)(.*?)(\])(.*?)(\[\/url\])/',
    '/(\[url\])(.*?)(\[\/url\])/'
  );

  $replace = array (
    '<strong>$2</strong>',
    '<em>$2</em>',
    '<u>$2</u>',
    '<ul>$2</ul>',
    '<li>$2</li>',
    '<a href="$2" target="_blank">$4</a>',
    '<a href="$2" target="_blank">$2</a>'
  );

  return preg_replace($search, $replace, $content);
}


function show_gpager($page_count, $block_size = 100) {
	global $url;
	if (isset($_GET['page'])) {
		$page = intval($_GET['page']);
	} else {
		$page = 0;
	}
	// поисковый запрос и сортировка живут в URL, их нужно сохранять при листании
	$extra = keep_params_amp();
	if ($page_count > 1) {
		echo "<nav><ul class='pagination pagination-sm'>";

		$b1 = $page - $block_size;
		$b2 = $block_size + $page;


		if ($b1 < 1) {
			$b1 = 1;
		}
		if ($b2 > $page_count) {
			$b2 = $page_count;
		}

    	if ($b1 > 1) {
 			echo "<li class='page-item'><a class='page-link' href='?page=", $b1 - 2, "$extra' aria-label='Previous'><span aria-hidden='true'><i class='fas fa-angle-left'></i></span></a></li>";
	    	
    	}

		for ($p = $b1; $p <= $b2; $p++) {
			if ($p == $page + 1) {
				$pv = 'active';
			} else {
				$pv = '';
			}
			echo "<li class='page-item $pv'><a class='page-link' href='?page=", $p - 1, "$extra'>$p</a></li>";
		}
		$pv = '';		
    	
    	if ($b2 < $page_count) {
    		echo "<li class='page-item'><a class='page-link' href='?page=", $b2, "$extra' aria-label='Next'><span aria-hidden='true'><i class='fas fa-angle-right'></i></span></a></li>";
    	}

		echo '</ul></nav>';
	}
}



function pg_array_parse($literal){
    if ($literal == '') return;
    preg_match_all('/(?<=^\{|,)(([^,"{]*)|\s*"((?:[^"\\\\]|\\\\(?:.|[0-9]+|x[0-9a-f]+))*)"\s*)(,|(?<!^\{)(?=\}$))/i', $literal, $matches, PREG_SET_ORDER);
    $values = [];
    foreach ($matches as $match) {
        $values[] = $match[3] != '' ? stripcslashes($match[3]) : (strtolower($match[2]) == 'null' ? null : $match[2]);
    }
    return $values;
}

function to_pg_array($set) {
    settype($set, 'array'); // can be called with a scalar or array
    $result = array();
    foreach ($set as $t) {
        if (is_array($t)) {
            $result[] = to_pg_array($t);
        } else {
            $t = str_replace('"', '\\"', $t); // escape double quote
            if (! is_numeric($t)) // quote only non-numeric values
                $t = '"' .addslashes($t) . '"';
            $result[] = $t;
        }
    }
    return '{' . implode(",", $result) . '}'; // format
}


// Экранирование текста для вывода в HTML и XML
function h($s) {
	return htmlspecialchars((string)$s, ENT_QUOTES);
}

function is_book_available($bookid) {
	global $dbh;
	static $stmt = null;
	if ($stmt === null) {
		$stmt = $dbh->prepare("SELECT 1 FROM book_available WHERE bookid=:id");
	}
	$stmt->execute([':id' => $bookid]);
	return $stmt->fetchColumn() !== false;
}

// Параметры адреса, которые сохраняются при листании, переключении фильтров
// и добавлении в избранное: поисковый запрос и сортировка. Остальные фильтры
// хранятся в сессии. Возвращает строку вида "&amp;q=...&amp;sort=...".
function keep_params_amp($with_page = false) {
	$keys = ['q', 'sort'];
	if ($with_page) {
		$keys[] = 'page';
	}
	$s = '';
	foreach ($keys as $k) {
		$v = trim($_GET[$k] ?? '');
		if ($v !== '') {
			$s .= "&amp;$k=" . urlencode($v);
		}
	}
	return $s;
}

function db_has_table($name) {
	global $dbh;
	static $cache = [];
	if (!isset($cache[$name])) {
		$stmt = $dbh->prepare("SELECT to_regclass(:name) IS NOT NULL");
		$stmt->execute([':name' => $name]);
		$cache[$name] = (bool)$stmt->fetchColumn();
	}
	return $cache[$name];
}

/***************************************************************************/
// Избранное: книги, авторы и серии в таблице fav

const FAV_COLUMNS = ['book' => 'bookid', 'author' => 'avtorid', 'seq' => 'seqid'];

function fav_is($uuid, $type, $id) {
	global $dbh;
	if ($uuid == '') {
		return false;
	}
	$col = FAV_COLUMNS[$type];
	$stmt = $dbh->prepare("SELECT 1 FROM fav WHERE user_uuid=:uuid AND $col=:id LIMIT 1");
	$stmt->execute([':uuid' => $uuid, ':id' => $id]);
	return $stmt->fetchColumn() !== false;
}

function fav_set($uuid, $type, $id, $on) {
	global $dbh;
	$col = FAV_COLUMNS[$type];
	// уникальный индекс по (user_uuid, bookid, avtorid, seqid) не ловит дубли
	// из-за NULL в остальных колонках, поэтому сначала удаляем
	$st = $dbh->prepare("DELETE FROM fav WHERE user_uuid=:uuid AND $col=:id");
	$st->execute([':uuid' => $uuid, ':id' => $id]);
	if ($on) {
		$st = $dbh->prepare("INSERT INTO fav (user_uuid, $col) VALUES (:uuid, :id)");
		$st->execute([':uuid' => $uuid, ':id' => $id]);
	}
}

// Кнопка «в избранное». Без JS это обычная ссылка на текущую страницу
// (с сохранением поиска, сортировки и номера страницы), с JS — запрос
// к fav.php без перезагрузки (public/js/fav.js).
// $on и $off — пары [css-класс, html] для состояний «в избранном» и «нет».
function fav_link($type, $id, $is_fav, $class, $on, $off, $title = 'В избранное') {
	$param = ($is_fav ? 'unfav_' : 'fav_') . $type;
	$href = "?$param=$id" . keep_params_amp(true);
	[$cls, $html] = $is_fav ? $on : $off;
	$attr = fn($s) => htmlspecialchars($s, ENT_QUOTES);
	return "<a href='$href' title='" . $attr($title) . "' class='$class $cls'"
		. " data-fav-type='$type' data-fav-id='$id' data-fav='" . ($is_fav ? 1 : 0) . "'"
		. " data-on-class='" . $attr($on[0]) . "' data-off-class='" . $attr($off[0]) . "'"
		. " data-on-html='" . $attr($on[1]) . "' data-off-html='" . $attr($off[1]) . "'>$html</a>";
}

function fav_book_link($bookid) {
	global $user_uuid;
	if ($user_uuid == '') {
		return '';
	}
	$heart = "<i class='fas fa-heart'></i>";
	return fav_link('book', $bookid, fav_is($user_uuid, 'book', $bookid), 'btn btn-sm',
		['btn-primary', $heart], ['btn-outline-secondary', $heart]);
}

/***************************************************************************/
// Рейтинг книги по оценкам читателей Флибусты (таблица book_rating
// собирается из librate в tools/update_vectors.sql)

function book_rating($bookid) {
	global $dbh;
	static $stmt = null;
	if (!db_has_table('book_rating')) {
		return null;
	}
	if ($stmt === null) {
		$stmt = $dbh->prepare("SELECT votes, rating FROM book_rating WHERE bookid=:id");
	}
	$stmt->execute([':id' => $bookid]);
	$r = $stmt->fetch();
	return ($r && $r->votes > 0) ? $r : null;
}

function plural_ru($n, $one, $few, $many) {
	$n = abs($n) % 100;
	if ($n >= 11 && $n <= 14) {
		return $many;
	}
	return match ($n % 10) {
		1 => $one,
		2, 3, 4 => $few,
		default => $many,
	};
}

function rating_html($r, $compact = false) {
	if (!$r) {
		return '';
	}
	$value = number_format($r->rating, 1, ',', '');
	$votes = "$r->votes " . plural_ru($r->votes, 'оценка', 'оценки', 'оценок');
	$title = "Средняя оценка $value из 5, $votes";
	if ($compact) {
		return "<div class='rating small w-100' title='$title'><i class='fas fa-star'></i> $value</div>";
	}
	return "<div class='rating' title='$title'>" . stars($r->rating) . " $value <span class='text-muted small'>($votes)</span></div>";
}

/***************************************************************************/

function book_translators($bookid) {
	global $dbh;
	$stmt = $dbh->prepare("SELECT t.translatorid, an.lastname, an.firstname, an.middlename, an.nickname
		FROM libtranslator t
		JOIN libavtorname an ON an.avtorid=t.translatorid
		WHERE t.bookid=:id ORDER BY t.pos");
	$stmt->execute([':id' => $bookid]);
	return $stmt->fetchAll();
}

// Книга, которой на Флибусте заменили эту (объединение дублей и редакций).
// realid — конечная книга цепочки замен.
function book_replacement($bookid) {
	global $dbh;
	static $stmt = null;
	if ($stmt === null) {
		$stmt = $dbh->prepare("SELECT b.bookid, b.title FROM libjoinedbooks j
			JOIN libbook b ON b.bookid=COALESCE(NULLIF(j.realid, 0), j.goodid)
			WHERE j.badid=:id AND b.bookid<>j.badid AND b.deleted='0' LIMIT 1");
	}
	$stmt->execute([':id' => $bookid]);
	return $stmt->fetch() ?: null;
}

// Другие издания той же книги (таблица book_editions собирается
// в tools/update_vectors.sql): сначала с файлом, потом новые.
function book_other_editions($bookid) {
	global $dbh;
	if (!db_has_table('book_editions')) {
		return [];
	}
	$rating = db_has_table('book_rating');
	$stmt = $dbh->prepare("SELECT b.bookid, b.title, b.year, b.filetype, b.filesize, b.lang,
			EXISTS (SELECT 1 FROM book_available ba WHERE ba.bookid=b.bookid) available,
			(SELECT string_agg(trim(concat_ws(' ', an.firstname, an.lastname, an.nickname)), ', ' ORDER BY t.pos)
				FROM libtranslator t JOIN libavtorname an ON an.avtorid=t.translatorid
				WHERE t.bookid=b.bookid) translators,
			(SELECT string_agg(sn.seqname || CASE WHEN ls.seqnumb>0 THEN ' ' || ls.seqnumb ELSE '' END, '; ')
				FROM libseq ls JOIN libseqname sn USING(seqid)
				WHERE ls.bookid=b.bookid) series,
			" . ($rating ? 'r.votes, r.rating' : 'NULL votes, NULL rating') . "
		FROM book_editions me
		JOIN book_editions e ON e.workid=me.workid AND e.bookid<>me.bookid
		JOIN libbook b ON b.bookid=e.bookid
		" . ($rating ? 'LEFT JOIN book_rating r ON r.bookid=b.bookid' : '') . "
		WHERE me.bookid=:id AND b.deleted='0'
		ORDER BY available DESC, NULLIF(b.year, 0) DESC NULLS LAST, b.bookid DESC");
	$stmt->execute([':id' => $bookid]);
	return $stmt->fetchAll();
}

// Список других изданий: в поиске свёрнут («Ещё 3 издания»), на странице книги раскрыт.
function editions_html($book, $webroot = '', $open = false) {
	$list = book_other_editions($book->bookid);
	if (!$list) {
		return '';
	}
	$n = count($list);
	$summary = $open ? "Другие издания ($n)"
		: 'Ещё ' . $n . ' ' . plural_ru($n, 'издание', 'издания', 'изданий');
	$s = "<details class='editions small my-1'" . ($open ? ' open' : '') . "><summary>$summary</summary><ul class='mb-1'>";
	$norm = fn($t) => str_replace('ё', 'е', mb_strtolower(trim($t)));
	foreach ($list as $e) {
		// ссылка — год, формат и размер; название — только если отличается
		$link = [];
		if ($e->year > 0) {
			$link[] = $e->year;
		}
		$link[] = trim($e->filetype);
		$link[] = $e->filesize >= 1048576
			? number_format($e->filesize / 1048576, 1, ',', '') . ' МБ'
			: ceil($e->filesize / 1024) . ' КБ';
		$parts = [];
		if ($norm($e->title) !== $norm($book->title)) {
			$parts[] = '«' . htmlspecialchars($e->title) . '»';
		}
		if (trim($e->lang) !== trim($book->lang)) {
			$parts[] = trim($e->lang);
		}
		if ($e->translators) {
			$parts[] = 'пер. ' . htmlspecialchars($e->translators);
		}
		if ($e->series) {
			$parts[] = htmlspecialchars($e->series);
		}
		$s .= "<li><a href='$webroot/book/view/$e->bookid'>" . implode(', ', $link) . "</a>";
		if ($parts) {
			$s .= ' — ' . implode(', ', $parts);
		}
		if ($e->votes > 0) {
			$s .= " <span class='rating' title='Средняя оценка, $e->votes " . plural_ru($e->votes, 'оценка', 'оценки', 'оценок') . "'>"
				. "<i class='fas fa-star'></i> " . number_format($e->rating, 1, ',', '') . "</span>";
		}
		if (!$e->available) {
			$s .= " <span class='text-muted'>(нет файла)</span>";
		}
		$s .= "</li>";
	}
	return $s . "</ul></details>";
}

function convert_menu($book, $webroot = '') {
	if (trim($book->filetype) != 'fb2') {
		return '';
	}
	$h = "<div class='btn-group' role='group'>";
	$h .= "<button type='button' class='btn btn-outline-success btn-sm dropdown-toggle' data-bs-toggle='dropdown' aria-expanded='false' title='Скачать в другом формате'></button>";
	$h .= "<ul class='dropdown-menu'>";
	foreach (array_keys(CONVERT_FORMATS) as $fmt) {
		$h .= "<li><a class='dropdown-item' href='$webroot/convert.php?id=$book->bookid&amp;fmt=$fmt'>" . strtoupper($fmt) . "</a></li>";
	}
	$h .= "</ul></div>";
	return $h;
}

function opds_convert_links($bookid, $webroot = '') {
	foreach (CONVERT_FORMATS as $fmt => $mime) {
		echo "\n <link href='$webroot/convert.php?id=$bookid&amp;fmt=$fmt' rel='http://opds-spec.org/acquisition/open-access' type='$mime' />";
	}
}

function book_small_pg($book, $webroot='',$full = false) {
	global $dbh, $user_uuid;
	if (!isset($book->bookid)) {
		return;
	}
	echo "<div class='col-sm-2 col-6 mb-3'>";
	echo "<div style='height: 100%' class='cover rounded text-center d-flex align-items-end flex-column'>";
	echo "<a class='w-100' href='$webroot/book/view/$book->bookid'>";
	echo "<img class='w-100 card-image rounded-top' src='$webroot/extract_cover.php?id=$book->bookid&small' />";

	$dt =DateTime::createFromFormat('Y-m-d H:i:se', $book->time)->format('Y-m-d');
	if (trim($book->filetype) == 'fb2') {
		$ft = 'success';
		$fhref = "$webroot/fb2.php?id=$book->bookid";
	} else {
		$ft = 'secondary';
		$fhref = "$webroot/usr.php?id=$book->bookid";
	}

	if ($book->year != 0) {
		$year = $book->year;
	} else {
		$year = $dt;
	}

	echo "<div>$book->title</div></a>";
	echo rating_html(book_rating($book->bookid), true);
	if ($new = book_replacement($book->bookid)) {
		echo "<a class='badge bg-warning text-dark' href='$webroot/book/view/$new->bookid' title='" . htmlspecialchars($new->title, ENT_QUOTES) . "'>есть новая редакция</a>";
	}
	echo "<div class='btn-group w-100 mt-auto' role='group'>";
	echo "<button type='button' class='btn btn-outline-secondary btn-sm'>$year</button>";
	if (is_book_available($book->bookid)) {
		echo "<a href='$fhref' title='Скачать' type='button' class='btn btn-outline-$ft btn-sm'>$book->filetype</a>";
		echo convert_menu($book, $webroot);
	} else {
		echo "<button type='button' class='btn btn-outline-secondary btn-sm' disabled title='Файла нет в локальном архиве'>нет файла</button>";
	}
//	echo "<button type='button' class='btn btn-outline-secondary btn-sm'>$book->lang</button>";
	echo fav_book_link($book->bookid);
	echo "</div></div></div>\n";
}

function book_info_pg($book, $webroot = '', $full = false) {
	global $dbh, $user_uuid;
	if (!isset($book->bookid)) {
		return;
	}
	echo "<div class='hic card mb-3' itemscope='' itemtype='http://schema.org/Book'>";
//	echo "<div class='card-header'>";
	echo "<h4 class='rounded-top' style='background: #d0d0d0;'><a class='book-link' href='$webroot/book/view/$book->bookid'><i class='fas'></i> $book->title</h4></a>";
//	echo "</div>";
	echo "<div class='card-body'>";
	echo "<div class='row'>";
	echo "<div class='col-sm-2'>";
	echo "<img class='w-100 card-image rounded cover' src='$webroot/extract_cover.php?id=$book->bookid&small' />";

	$dt =DateTime::createFromFormat('Y-m-d H:i:se', $book->time)->format('Y-m-d');
	if (trim($book->filetype) == 'fb2') {
		$ft = 'success';
		$fhref = "$webroot/fb2.php?id=$book->bookid";
	} else {
		$ft = 'secondary';
		$fhref = "$webroot/usr.php?id=$book->bookid";
	}

	if ($book->year != 0) {
		$year = $book->year;
	} else {
		$year = $dt;
	}
	

	echo "<div class='btn-group w-100 mt-1' role='group'>";
	echo "<button type='button' class='btn btn-outline-secondary btn-sm'>$year</button>";
	if (is_book_available($book->bookid)) {
		echo "<a href='$fhref' title='Скачать' type='button' class='btn btn-outline-$ft btn-sm'>$book->filetype</a>";
		echo convert_menu($book, $webroot);
	} else {
		echo "<button type='button' class='btn btn-outline-secondary btn-sm' disabled title='Файла нет в локальном архиве'>нет файла</button>";
	}
//	echo "<button type='button' class='btn btn-outline-secondary btn-sm'>$book->lang</button>";
	echo fav_book_link($book->bookid);
	echo "</div>";
	
	echo "</div><div class='col-sm-10'>";
	echo "<div class='authors-list'>";
	$stmt = $dbh->prepare("SELECT AvtorId, LastName, FirstName, nickname, middlename, File FROM libavtor a
		LEFT JOIN libavtorname USING(AvtorId)
		LEFT JOIN libapics USING(AvtorId)
		WHERE a.BookId=:id");
	$stmt->bindParam(":id", $book->bookid);
	$stmt->execute();
	while ($a = $stmt->fetch()) {
		echo "<div class='badge rounded-pill author'>";
		if ($a->file != '') {
			echo "<img class='rounded-circle contact' src='$webroot/extract_author.php?id=$a->avtorid' />";	
		}
		echo "<a href='$webroot/author/view/$a->avtorid'>$a->lastname $a->middlename $a->firstname $a->nickname</a>";
		echo "</div>";
	}
	echo "</div>";

	$translators = book_translators($book->bookid);
	if ($translators) {
		echo "<div class='translators-list small'>Перевод: ";
		$links = [];
		foreach ($translators as $t) {
			$links[] = "<a href='$webroot/author/view/$t->translatorid'>" . trim("$t->firstname $t->middlename $t->lastname $t->nickname") . "</a>";
		}
		echo implode(', ', $links), "</div>";
	}

	echo rating_html(book_rating($book->bookid));

	if ($new = book_replacement($book->bookid)) {
		echo "<div class='alert alert-warning py-1 px-2 my-1 small'>На Флибусте эта книга заменена новой редакцией: ";
		echo "<a href='$webroot/book/view/$new->bookid'>" . htmlspecialchars($new->title) . "</a></div>";
	}


	echo "<div style='margin-bottom: 3px;'>";
	$genres = $dbh->prepare("SELECT GenreId, GenreDesc FROM libgenre 
		JOIN libgenrelist USING(GenreId)
		WHERE BookId=$book->bookid");
	$genres->execute();
	while ($g = $genres->fetch()) {
		echo "<a class='badge bg-success p-1 text-white' href='$webroot/?gid=$g->genreid'>$g->genredesc</a> ";
	}
	echo "</div>";
	
	echo "<div style='margin-bottom: 3px;'>";
	$seq = $dbh->prepare("SELECT SeqId, SeqName, SeqNumb FROM libseq
				JOIN libseqname USING(SeqId)
				WHERE BookId=:id");
	$seq->bindParam(":id", $book->bookid);
	$seq->execute();
	while ($s = $seq->fetch()) {
		echo "<a class='badge bg-danger p-1 text-white' href='$webroot/?sid=$s->seqid'>$s->seqname ";
		if ($s->seqnumb > 0) {
			echo " $s->seqnumb";
		}
		echo "</a> ";
	}
	echo "</div>";

	echo "<div style='margin-bottom: 3px;'>";
	if ($book->keywords != '') {
		$kw = explode(",", $book->keywords);
		foreach ($kw as $k) {
			echo "<a class='badge bg-secondary p-1 text-white' href='#'>$k</a> ";
		}
	}
	echo "</div>";

	// в результатах поиска editions приходит из запроса, на странице книги список показывается всегда
	if ($full || ($book->editions ?? 0) > 1) {
		echo editions_html($book, $webroot, $full);
	}

	echo "<div style='font-size: 0.8em;'>";
	if (isset($book->body)) {
		if ($full) {
			echo "<p>" . trim($book->body) . "</p>";
		} else {
			echo "<p>" . cut_str(trim(strip_tags($book->body))) . "</p>";
		}
	}
	echo "</div>";

	echo "</div>";
	echo "</div>";
	echo "</div></div>\n";
}

date_default_timezone_set('Europe/Minsk');
date_default_timezone_set('Etc/GMT-3');
setlocale(LC_ALL, 'rus_RUS');

$m_time = explode(" ",microtime());
$m_time = $m_time[0] + $m_time[1];
$starttime = $m_time;
$sql_time = 0;


$cdt = date('Y-m-d H:i:s');
$today_from =  date('Y-m-d') . ' 00:00:00';
$today_to   = date('Y-m-d') . ' 23:59:59';


function russian_date() {
 $translation = array(
 "am" => "дп",
 "pm" => "пп",
 "AM" => "ДП",
 "PM" => "ПП",
 "Monday" => "Понедельник",
 "Mon" => "Пн",
 "Tuesday" => "Вторник",
 "Tue" => "Вт",
 "Wednesday" => "Среда",
 "Wed" => "Ср",
 "Thursday" => "Четверг",
 "Thu" => "Чт",
 "Friday" => "Пятница",
 "Fri" => "Пт",
 "Saturday" => "Суббота",
 "Sat" => "Сб",
 "Sunday" => "Воскресенье",
 "Sun" => "Вс",
 "January" => "Января",
 "Jan" => "Янв",
 "February" => "Февраля",
 "Feb" => "Фев",
 "March" => "Марта",
 "Mar" => "Мар",
 "April" => "Апреля",
 "Apr" => "Апр",
 "May" => "Мая",
 "May" => "Мая",
 "June" => "Июня",
 "Jun" => "Июн",
 "July" => "Июля",
 "Jul" => "Июл",
 "August" => "Августа",
 "Aug" => "Авг",
 "September" => "Сентября",
 "Sep" => "Сен",
 "October" => "Октября",
 "Oct" => "Окт",
 "November" => "Ноября",
 "Nov" => "Ноя",
 "December" => "Декабря",
 "Dec" => "Дек",
 "st" => "ое",
 "nd" => "ое",
 "rd" => "е",
 "th" => "ое",
 );
 if (func_num_args() > 1) {
	$timestamp = func_get_arg(1);
	return strtr(date(func_get_arg(0), $timestamp), $translation);
 } else {
	return strtr(date(func_get_arg(0)), $translation);
 };
}
/***************************************************************************/
function transliterate($string){
  $cyr=array(
     "Щ", "Ш", "Ч","Ц", "Ю", "Я", "Ж","А","Б","В",
     "Г","Д","Е","Ё","З","И","Й","К","Л","М","Н",
     "О","П","Р","С","Т","У","Ф","Х","Ь","Ы","Ъ",
     "Э","Є", "Ї","І",
     "щ", "ш", "ч","ц", "ю", "я", "ж","а","б","в",
     "г","д","е","ё","з","и","й","к","л","м","н",
     "о","п","р","с","т","у","ф","х","ь","ы","ъ",
     "э","є", "ї","і", " "
  );
  $lat=array(
     "Shch","Sh","Ch","C","Yu","Ya","J","A","B","V",
     "G","D","e","e","Z","I","y","K","L","M","N",
     "O","P","R","S","T","U","F","H","", 
     "Y","" ,"E","E","Yi","I",
     "shch","sh","ch","c","Yu","Ya","j","a","b","v",
     "g","d","e","e","z","i","y","k","l","m","n",
     "o","p","r","s","t","u","f","h",
     "", "y","" ,"e","e","yi","i", "%20"
  );
  for($i=0; $i<count($cyr); $i++)  {
     $c_cyr = $cyr[$i];
     $c_lat = $lat[$i];
     $string = str_replace($c_cyr, $c_lat, $string);
  }
  $string = 
  	preg_replace(
  		"/([qwrtpsdfghklzxcvbnmQWRTPSDFGHKLZXCVBNM]+)[jJ]e/", 
  		"\${1}e", $string);
/*  $string = 
  	preg_replace(
  		"/([qwrtpsdfghklzxcvbnmQWRTPSDFGHKLZXCVBNM]+)[jJ]/", 
  		"\${1}'", $string);*/
  $string = preg_replace("/([eyuioaEYUIOA]+)[Kk]h/", "\${1}h", $string);
  $string = preg_replace("/^kh/", "h", $string);
  $string = preg_replace("/^Kh/", "H", $string);
  return $string;
}


function stars($rating) {
    $halves = (int)round(min(max($rating, 0), 5) * 2);
    $full = intdiv($halves, 2);
    $half = $halves % 2;
    return str_repeat("<i class='fas fa-star'></i>", $full)
        . str_repeat("<i class='fas fa-star-half-alt'></i>", $half)
        . str_repeat("<i class='far fa-star'></i>", 5 - $full - $half);
}

/***************************************************************************/
function cut_str($string, $maxlen=700) {
    $len = (mb_strlen($string) > $maxlen)
        ? mb_strripos(mb_substr($string, 0, $maxlen), ' ')
        : $maxlen
    ;
    $cutStr = mb_substr($string, 0, $len);
    return (mb_strlen($string) > $maxlen)
        ? $cutStr . '...'
        : $cutStr
    ;
}

/***************************************************************************/
function cut_str2($string, $maxlen=700) {
    $len = (mb_strlen($string) > $maxlen)
        ? mb_strripos(mb_substr($string, 0, $maxlen), ' ')
        : $maxlen
    ;
    $cutStr = mb_substr($string, 0, $len);
    return $cutStr . $len;
}

/***************************************************************************/
function clean_str($input) {
  if (!$input)
	return $input;

  $input = strip_tags($input);

  $input = str_replace ("\n"," ", $input);
  $input = str_replace ("\r","", $input);

  $input = preg_replace("/[^(\w)|(\x7F-\xFF)|^(_,\-,\.,\;,\@)|(\s)]/", " ", $input);

  return $input;
}

/***************************************************************************/
function decode_gurl($webroot,$mobile = false)  {
  global $last_modified, $url, $robot;
  global $sex_post;

 
  $urlx = parse_url(urldecode($_SERVER['REQUEST_URI']));

  //remove leading webroot e.g. http://192.168.1.101/flibusta/authors/index.php should produce module= authors
  // note this assumes path is not utf-8
  $path = $urlx['path'];
  if (!empty($webroot) && str_starts_with($path,$webroot) ) {
		$path = substr($path, strlen($webroot));
  }
  list($x, $module, $action, $var1, $var2, $var3) = array_pad(explode('/', $path), 6, null);
  $url = new stdClass();

  $url->mod = safe_str($module);
  $url->action = safe_str($action);
  $url->var1 = intval($var1);
  $url->var2 = intval($var2);
  $url->var3 = intval($var3); 
  $url->title = '';
  $url->description = '';
  $url->mod_path = '';
  $url->mod_menu = '';
  $url->image = '';
  $url->noindex = 0;
  $url->index = 1;
  $url->follow = 1;
  $url->module_menu = '';
  $url->js = array();
  $url->editor = 0;
  $url->access = 0;
  $url->canonical = '';

  $menu = true;

  if ($url->mod == '') {
    $url->mod ='primary';
  }

  if (file_exists(ROOT_PATH . 'modules/' . $url->mod . '/module.conf')) {
    $last_modified = gmdate('D, d M Y H:i:s', filemtime(ROOT_PATH . 'modules/' . $url->mod . '/index.php')) . ' GMT';
    $url->module = ROOT_PATH . 'modules/' . $url->mod . '/index.php';
    $url->mod_path = ROOT_PATH . 'modules/' . $url->mod . '/';
    include(ROOT_PATH . 'modules/' . $url->mod . '/module.conf');
  } else {
    $menu = false;
    include(ROOT_PATH . 'modules/404/module.conf');
    $url->module = ROOT_PATH . 'modules/404/index.php';
    $url->mod = '404';  
  }

  if ($url->access > 0) {
   // if (!is_admin()) {
      include(ROOT_PATH . 'modules/403/module.conf');
      $url->module = ROOT_PATH . 'modules/403/index.php';
      $url->mod = '403';
      $menu = false;
   // }
  }

  if ( (file_exists(ROOT_PATH . 'modules/' . $url->mod . '/module_menu.php')) && ($menu) ) {
    $url->module_menu = ROOT_PATH . 'modules/' . $url->mod . '/module_menu.php';
  }

  return $url;
}

function safe_str($str) {
        return ($str)?preg_replace("/[^A-Za-z0-9 -_]/", '', $str):$str;
}


function mobile() {
        $devices = array(
                "android" => "android.*mobile",
                "androidtablet" => "android(?!.*mobile)",
                "iphone" => "(iphone|ipod)",
                "ipad" => "(ipad)",
                "generic" => "(kindle|mobile|mmp|midp|pocket|psp|symbian|smartphone|treo|up.browser|up.link|vodafone|wap|opera mini)"
        );
        $isMobile = false;
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
                $userAgent = $_SERVER['HTTP_USER_AGENT'];
        } else {
                $userAgent = "";
        }
        if (isset($_SERVER['HTTP_ACCEPT'])) {
               $accept = $_SERVER['HTTP_ACCEPT'];
        } else {
                $accept = '';
        }
        if (isset($_SERVER['HTTP_X_WAP_PROFILE']) || isset($_SERVER['HTTP_PROFILE'])) {
                $isMobile = true;
        } elseif (strpos($accept, 'text/vnd.wap.wml') > 0 || strpos($accept, 'application/vnd.wap.xhtml+xml') > 0) {
                $isMobile = true;
        } else {
                foreach ($devices as $device => $regexp) {
                        if (preg_match("/" . $devices[strtolower($device)] . "/i", $userAgent)) {
                                $isMobile = true;
                        }
                }
        }
        return $isMobile;
}

function formatSizeUnits($bytes)
    {
        if ($bytes >= 1073741824)
        {
            $bytes = number_format($bytes / 1073741824, 2) . ' GB';
        }
        elseif ($bytes >= 1048576)
        {
            $bytes = number_format($bytes / 1048576, 2) . ' MB';
        }
        elseif ($bytes >= 1024)
        {
            $bytes = number_format($bytes / 1024, 2) . ' KB';
        }
        elseif ($bytes > 1)
        {
            $bytes = $bytes . ' bytes';
        }
        elseif ($bytes == 1)
        {
            $bytes = $bytes . ' byte';
        }
        else
        {
            $bytes = '0 bytes';
        }

        return $bytes;
    }

function opds_book($b,$webroot = '') {
	global $dbh;
	echo "\n<entry> <updated>" . $b->time . "</updated>";
	echo " <id>tag:book:$b->bookid</id>";
	echo " <title>" . htmlspecialchars($b->title) . "</title>";

	$ann = $dbh->prepare("SELECT body annotation FROM libbannotations WHERE bookid=:id LIMIT 1");
	$ann->bindParam(":id", $b->bookid);
	$ann->execute();
	if ($tmp = $ann->fetch()) {
		$an = $tmp->annotation;
	} else {
		$an = '';
	}
	$genres = $dbh->prepare("SELECT genrecode, GenreId, GenreDesc FROM libgenre 
		JOIN libgenrelist USING(GenreId)
		WHERE bookid=:id");
	$genres->bindParam(":id", $b->bookid);
	$genres->execute();
	while ($g = $genres->fetch()) {
		echo "<category term='$webroot/subject/" . urlencode($g->genrecode) . "' label='$g->genredesc'/>";
	}

	$sq = '';
	$seq = $dbh->prepare("SELECT SeqId, SeqName, SeqNumb FROM libseq
		JOIN libseqname USING(SeqId)
		WHERE BookId=:id");
	$seq->bindParam(":id", $b->bookid);
	$seq->execute();
	while ($s = $seq->fetch()) {
		$ssq = $s->seqname;
		if ($s->seqnumb > 0) {
			$ssq .= " ($s->seqnumb) ";
		}
		$sq .= $ssq;
		echo " <link href='$webroot/opds/list?seq_id=".$s->seqid."' rel='related' type='application/atom+xml' title='Все книги серии &quot;$ssq&quot;' />";
	}
	if ($sq != '') {
		$sq = "Сборник: $sq";
	}


	echo "<author>";
	$au = $dbh->prepare("SELECT AvtorId, LastName, FirstName, nickname, middlename, File FROM libavtor a
		LEFT JOIN libavtorname USING(AvtorId)
		LEFT JOIN libapics USING(AvtorId)
		WHERE a.bookid=:id");
	$au->bindParam(":id", $b->bookid);
	$au->execute();
	$authors = $au->fetchAll();
	foreach ($authors as $a) {
		echo "<name>$a->lastname $a->firstname $a->middlename</name>";
		echo "<uri>/opds/author?author_id=$a->avtorid</uri>";
	}
	echo "</author>";
	foreach ($authors as $a) {
		echo "\n <link href='$webroot/opds/list?author_id=$a->avtorid' rel='related' type='application/atom+xml' title='Все книги автора $a->lastname $a->firstname $a->middlename' />";
	}
	echo " <dc:language>" . trim($b->lang) . "</dc:language>";
	if ($b->year > 0) {
		echo " <dc:issued>$b->year</dc:issued>";
	}
	
	// Include the type of the book as <dc:format> element
	echo " <dc:format>" . trim($b->filetype) . "</dc:format>";
	
	// Include the size of the book as <dcterms:extent> element using the FileSize attribute from $b
	echo " <dcterms:extent>" . formatSizeUnits($b->filesize) . " bytes</dcterms:extent>";
	
	echo "\n <summary type='text'>" . strip_tags($an);
	echo "\n $sq ";
	echo "\n $b->keywords";
	if ($b->year > 0) {
		echo "\n Год издания: $b->year";
	}
	echo "\n Формат: $b->filetype";
	echo "\n Язык: $b->lang";
	echo "\n Размер: " . formatSizeUnits($b->filesize);
	echo "\n </summary>";

	echo "\n <link rel='http://opds-spec.org/image/thumbnail' href='$webroot/extract_cover.php?id=$b->bookid' type='image/jpeg'/>";
	echo "\n <link rel='http://opds-spec.org/image' href='$webroot/extract_cover.php?id=$b->bookid' type='image/jpeg'/>";
	if (trim($b->filetype) == 'fb2') {
		$ur = 'fb2';
	} else {
		$ur = 'usr';
	}
	echo "\n <link href='$webroot/$ur.php?id=$b->bookid' rel='http://opds-spec.org/acquisition/open-access' type='application/" . trim($b->filetype) . "' />";
	if ($ur == 'fb2') {
		opds_convert_links($b->bookid, $webroot);
	}
	echo "\n <link href='$webroot/book/view/$b->bookid' rel='alternate' type='text/html' title='Книга на сайте' />";

	echo "</entry>\n";
}
