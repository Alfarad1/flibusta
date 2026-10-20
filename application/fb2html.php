<?php
// FB2 → HTML для читалки. Выводятся только известные элементы FB2 (белый список),
// текст экранируется, поэтому HTML и скрипты внутри книги не попадают на страницу.
// Результат — текст, оглавление по заголовкам разделов, сноски для всплывающих
// подсказок и картинки — кэшируется в cache/reader/<версия>/<id>. Там же xp.txt: для каждого
// блока текста (абзаца, заголовка… — тех, по которым читалка запоминает место, «p:номер:доля»)
// его адрес в KOReader (XPointer crengine), чтобы переносить позицию между сайтом и Kindle.

const FB2HTML_VERSION = 1;
const FB2HTML_CACHE = ROOT_PATH . 'cache/reader/v' . FB2HTML_VERSION;

class Fb2Html {
	public $html = '';
	public $toc = [];      // [уровень, id, текст]
	public $notes = [];    // id сноски => HTML
	public $images = [];   // id картинки => [файл, mime, ширина, высота]
	public $cover = null;  // id картинки обложки
	public $lang = 'ru';
	public $xps = [];      // XPointer каждого блока текста по порядку; '' — блок не из FB2

	private $bookid;
	private $webroot;
	private $note_ids = [];
	private $section_n = 0;
	private $blk = [];     // XPointer блоков: 'main' — текст, id сноски — сноска
	private $cur = 'main';
	// spl_object_id элемента => [номер среди одноимённых соседей, их число, сам элемент —
	// ссылка держит объект DOM, иначе PHP отдаёт его id другому узлу]
	private $sib = [];

	function __construct($bookid, $webroot) {
		$this->bookid = $bookid;
		$this->webroot = $webroot;
	}

	// $data — содержимое файла .fb2; false, если файл не разбирается
	function convert($data, $img_dir) {
		$doc = new DOMDocument();
		$ok = @$doc->loadXML($data, LIBXML_PARSEHUGE | LIBXML_NONET | LIBXML_COMPACT);
		if (!$ok || !$doc->documentElement) {
			return false;
		}
		$root = $doc->documentElement;
		foreach ($this->children($root, 'binary') as $b) {
			$this->save_image($b, $img_dir);
		}
		$bodies = $this->children($root, 'body');
		if (!$bodies) {
			return false;
		}
		foreach ($this->children($root, 'description') as $d) {
			foreach ($this->children($d, 'title-info') as $ti) {
				foreach ($this->children($ti, 'lang') as $l) {
					$this->lang = preg_replace('/[^a-z-]/', '', strtolower(trim($l->textContent))) ?: 'ru';
				}
				foreach ($this->children($ti, 'coverpage') as $cp) {
					foreach ($this->children($cp, 'image') as $img) {
						$this->cover = ltrim($this->href($img), '#');
					}
				}
			}
		}
		// первый body — текст книги, остальные (notes, comments) — сноски
		$main = array_shift($bodies);
		foreach ($bodies as $body) {
			foreach ($this->children($body, 'section') as $s) {
				$this->collect_notes($s);
			}
		}
		$html = '';
		if ($this->cover && isset($this->images[$this->cover])) {
			$html .= "<div class='cover-page'>" . $this->img_tag($this->cover, '') . "</div>";
			$this->xps[] = '';
		}
		$this->cur = 'main';
		$html .= $this->node_children($main, 0);
		array_push($this->xps, ...($this->blk['main'] ?? []));
		if ($this->notes) {
			$this->xps[] = '';
			foreach (array_keys($this->notes) as $id) {
				array_push($this->xps, ...($this->blk[$id] ?? []));
			}
			// сноски и в конце книги: на них ведут ссылки, если всплывающее окно не открылось
			$html .= "<section class='notes'><h2 id='notes'>Примечания</h2>";
			foreach ($this->notes as $id => $note) {
				$html .= "<div class='note' id='" . $this->id_attr($id) . "'>$note</div>";
			}
			$html .= "</section>";
		}
		$this->html = $html;
		return true;
	}

	// Адрес элемента в KOReader: /FictionBook/body[1]/section[2]/p[5] — номер ставится,
	// только если у родителя несколько одноимённых элементов (как в crengine)
	private function xp($n) {
		$path = '';
		for (; $n && $n->nodeType === XML_ELEMENT_NODE; $n = $n->parentNode) {
			$p = $n->parentNode;
			$num = '';
			if ($p && $p->nodeType === XML_ELEMENT_NODE) {
				if (!isset($this->sib[spl_object_id($n)])) {
					$count = $idx = [];
					foreach ($p->childNodes as $c) {
						if ($c->nodeType === XML_ELEMENT_NODE) {
							$idx[spl_object_id($c)] = [$c->localName, $count[$c->localName] = ($count[$c->localName] ?? 0) + 1, $c];
						}
					}
					foreach ($idx as $oid => [$name, $i, $c]) {
						$this->sib[$oid] = [$i, $count[$name], $c];
					}
				}
				[$i, $cnt] = $this->sib[spl_object_id($n)];
				$num = $cnt > 1 ? "[$i]" : '';
			}
			$path = '/' . $n->localName . $num . $path;
		}
		return $path;
	}

	// запоминает адрес очередного блока текста
	private function blk($n) {
		$this->blk[$this->cur][] = $this->xp($n);
	}

	private function children($node, $name) {
		$r = [];
		foreach ($node->childNodes as $c) {
			if ($c->nodeType === XML_ELEMENT_NODE && $c->localName === $name) {
				$r[] = $c;
			}
		}
		return $r;
	}

	// l:href / xlink:href — атрибут href из любого пространства имён
	private function href($el) {
		foreach ($el->attributes as $a) {
			if ($a->localName === 'href') {
				return trim($a->value);
			}
		}
		return '';
	}

	// id элементов книги получают префикс, чтобы не пересекаться с id интерфейса
	private function id_attr($id) {
		return 'b-' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $id);
	}

	private function save_image($b, $dir) {
		$id = $b->getAttribute('id');
		$data = base64_decode(preg_replace('/\s+/', '', $b->textContent), true);
		if ($id === '' || $data === false || $data === '') {
			return;
		}
		$size = @getimagesizefromstring($data);
		$mime = $size['mime'] ?? $b->getAttribute('content-type');
		if (!preg_match('#^image/(jpeg|png|gif|webp|svg\+xml|bmp)$#', $mime)) {
			return;
		}
		$file = count($this->images) . '.img';
		if (file_put_contents("$dir/$file", $data) === false) {
			return;
		}
		$this->images[$id] = [$file, $mime, $size[0] ?? 0, $size[1] ?? 0];
	}

	private function img_tag($id, $class) {
		if (!isset($this->images[$id])) {
			return '';
		}
		[$file, , $w, $h] = $this->images[$id];
		$n = array_search($id, array_keys($this->images));
		// размеры заранее: место под картинку резервируется, и позиция чтения не съезжает
		$wh = ($w && $h) ? " width='$w' height='$h'" : '';
		return "<img class='$class' src='{$this->webroot}/reader_image.php?id={$this->bookid}&amp;n=$n'$wh alt='' loading='lazy'>";
	}

	private function collect_notes($section) {
		$id = $section->getAttribute('id');
		if ($id !== '') {
			$this->note_ids[$id] = true;
			$this->cur = $id;
			$this->blk[$id] = [];
			$html = '';
			foreach ($section->childNodes as $c) {
				if ($c->nodeType === XML_ELEMENT_NODE && $c->localName === 'title') {
					$html .= "<div class='note-title'>" . $this->inline_children($c) . "</div>";
				} elseif ($c->nodeType === XML_ELEMENT_NODE && $c->localName !== 'section') {
					$html .= $this->node($c, 9);
				}
			}
			$this->notes[$id] = $html;
		}
		foreach ($this->children($section, 'section') as $s) {
			$this->collect_notes($s);
		}
	}

	private function node_children($node, $depth) {
		$s = '';
		foreach ($node->childNodes as $c) {
			$s .= $this->node($c, $depth);
		}
		return $s;
	}

	// текст заголовка: абзацы title через перевод строки
	private function inline_children($node) {
		$parts = [];
		foreach ($node->childNodes as $c) {
			if ($c->nodeType === XML_ELEMENT_NODE && $c->localName === 'p') {
				$parts[] = $this->inline($c);
			} elseif ($c->nodeType === XML_TEXT_NODE && trim($c->data) !== '') {
				$parts[] = h($c->data);
			}
		}
		return implode('<br>', $parts);
	}

	private function id_of($el) {
		$id = $el->getAttribute('id');
		return $id !== '' ? " id='" . $this->id_attr($id) . "'" : '';
	}

	private function node($n, $depth) {
		if ($n->nodeType === XML_TEXT_NODE || $n->nodeType === XML_CDATA_SECTION_NODE) {
			return trim($n->data) === '' ? '' : h($n->data);
		}
		if ($n->nodeType !== XML_ELEMENT_NODE) {
			return '';
		}
		$id = $this->id_of($n);
		switch ($n->localName) {
			case 'section':
				return "<section$id>" . $this->node_children($n, $depth + 1) . "</section>";
			case 'title':
				$text = $this->inline_children($n);
				if ($depth >= 9) {
					return "<div class='note-title'>$text</div>";
				}
				$this->blk($n);
				$level = min(6, $depth + 1);
				$tid = 's' . (++$this->section_n);
				$plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<br>', ' ', $text)), ENT_QUOTES)));
				if ($depth > 0 && $plain !== '') {
					$this->toc[] = [$depth, $tid, mb_substr($plain, 0, 120)];
				}
				return "<h$level id='$tid' class='title'>$text</h$level>";
			case 'subtitle':
				$this->blk($n);
				return "<p class='subtitle'$id>" . $this->inline($n) . "</p>";
			case 'p':
				$this->blk($n);
				return "<p$id>" . $this->inline($n) . "</p>";
			case 'empty-line':
				$this->blk($n);
				return "<div class='empty-line'></div>";
			case 'epigraph':
				return "<blockquote class='epigraph'$id>" . $this->node_children($n, $depth) . "</blockquote>";
			case 'cite':
				return "<blockquote class='cite'$id>" . $this->node_children($n, $depth) . "</blockquote>";
			case 'annotation':
				return "<div class='annotation'$id>" . $this->node_children($n, $depth) . "</div>";
			case 'text-author':
				$this->blk($n);
				return "<p class='text-author'>" . $this->inline($n) . "</p>";
			case 'poem':
				$s = '';
				foreach ($n->childNodes as $c) {
					if ($c->nodeType === XML_ELEMENT_NODE && $c->localName === 'title') {
						$this->blk($c);
						$s .= "<p class='poem-title'>" . $this->inline_children($c) . "</p>";
					} else {
						$s .= $this->node($c, $depth);
					}
				}
				return "<div class='poem'$id>$s</div>";
			case 'stanza':
				return "<div class='stanza'>" . $this->node_children($n, $depth) . "</div>";
			case 'v':
				$this->blk($n);
				return "<p class='v'>" . $this->inline($n) . "</p>";
			case 'date':
				$this->blk($n);
				return "<p class='date'>" . $this->inline($n) . "</p>";
			case 'image':
				$this->blk($n);
				return "<div class='image'$id>" . $this->img_tag(ltrim($this->href($n), '#'), '') . "</div>";
			case 'table':
				return "<div class='table-wrap'><table$id>" . $this->table_rows($n) . "</table></div>";
			default:
				return $this->node_children($n, $depth);
		}
	}

	private function table_rows($t) {
		$s = '';
		foreach ($t->childNodes as $tr) {
			if ($tr->nodeType !== XML_ELEMENT_NODE || $tr->localName !== 'tr') {
				continue;
			}
			$this->blk($tr);
			$s .= '<tr>';
			foreach ($tr->childNodes as $td) {
				if ($td->nodeType === XML_ELEMENT_NODE && in_array($td->localName, ['td', 'th'])) {
					$span = '';
					foreach (['colspan', 'rowspan'] as $a) {
						if ($v = intval($td->getAttribute($a))) {
							$span .= " $a='$v'";
						}
					}
					$s .= "<{$td->localName}$span>" . $this->inline($td) . "</{$td->localName}>";
				}
			}
			$s .= '</tr>';
		}
		return $s;
	}

	private function inline($n) {
		$s = '';
		foreach ($n->childNodes as $c) {
			if ($c->nodeType === XML_TEXT_NODE || $c->nodeType === XML_CDATA_SECTION_NODE) {
				$s .= h($c->data);
				continue;
			}
			if ($c->nodeType !== XML_ELEMENT_NODE) {
				continue;
			}
			$tag = ['emphasis' => 'em', 'strong' => 'strong', 'strikethrough' => 's',
				'sub' => 'sub', 'sup' => 'sup', 'code' => 'code'][$c->localName] ?? null;
			if ($tag) {
				$s .= "<$tag>" . $this->inline($c) . "</$tag>";
			} elseif ($c->localName === 'a') {
				$s .= $this->link($c);
			} elseif ($c->localName === 'image') {
				$s .= $this->img_tag(ltrim($this->href($c), '#'), 'inline');
			} else {
				$s .= $this->inline($c);
			}
		}
		return $s;
	}

	private function link($a) {
		$href = $this->href($a);
		$text = $this->inline($a);
		if (str_starts_with($href, '#')) {
			$target = substr($href, 1);
			if (isset($this->note_ids[$target]) || $a->getAttribute('type') === 'note') {
				return "<a class='note-ref' href='#" . $this->id_attr($target) . "' data-note='" . h($target) . "'>$text</a>";
			}
			return "<a href='#" . $this->id_attr($target) . "'>$text</a>";
		}
		if (preg_match('#^https?://#i', $href)) {
			return "<a href='" . h($href) . "' target='_blank' rel='noopener noreferrer'>$text</a>";
		}
		return $text;
	}
}

// Готовая к показу книга из кэша (или после конвертации): объект с полями
// html, toc, notes, lang; null — файла нет; false — файл не разбирается.
function fb2html_book($bookid, $webroot) {
	global $dbh;
	$dir = FB2HTML_CACHE . "/$bookid";
	$meta = "$dir/book.json";
	if (is_file($meta) && ($r = json_decode(file_get_contents($meta)))) {
		return $r;
	}
	$stmt = $dbh->prepare("SELECT filename FROM book_zip WHERE :id BETWEEN start_id AND end_id AND usr=0 LIMIT 1");
	$stmt->execute([':id' => $bookid]);
	$zip_name = $stmt->fetchColumn();
	$zip = new ZipArchive();
	if (!$zip_name || $zip->open(ROOT_PATH . "flibusta/" . $zip_name) !== true) {
		return null;
	}
	$data = $zip->getFromName("$bookid.fb2");
	$zip->close();
	if ($data === false) {
		return null;
	}
	$tmp = "$dir.tmp" . getmypid();
	if (!is_dir($tmp) && !@mkdir($tmp, 0775, true)) {
		error_log("fb2html: cannot create $tmp");
		$tmp = sys_get_temp_dir() . "/fb2html-$bookid-" . getmypid();
		@mkdir($tmp, 0775, true);
	}
	// DOM большой книги с картинками не помещается в стандартные 128 МБ
	ini_set('memory_limit', '512M');
	// webroot не кэшируется: в HTML ссылки на картинки через плейсхолдер
	$conv = new Fb2Html($bookid, '{WEBROOT}');
	if (!$conv->convert($data, $tmp)) {
		array_map('unlink', glob("$tmp/*"));
		@rmdir($tmp);
		return false;
	}
	$r = (object)['html' => $conv->html, 'toc' => $conv->toc, 'notes' => $conv->notes,
		'lang' => $conv->lang, 'images' => $conv->images];
	$json = json_encode($r, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
	file_put_contents("$tmp/book.json", $json);
	file_put_contents("$tmp/xp.txt", implode("\n", $conv->xps));
	// готовый каталог появляется целиком; если его успел создать параллельный запрос — оставляем тот
	if (!@rename($tmp, $dir)) {
		array_map('unlink', glob("$tmp/*"));
		@rmdir($tmp);
	}
	return json_decode($json);
}

// Адреса блоков текста в KOReader (см. xp.txt выше); null — книги нет или она не разбирается.
// Для книг, разобранных до появления xp.txt, он досчитывается.
function fb2html_xpointers($bookid) {
	$file = FB2HTML_CACHE . "/$bookid/xp.txt";
	if (!is_file($file)) {
		$fresh = !is_dir(dirname($file));
		if (!fb2html_book($bookid, '')) {
			return null;
		}
		if (!$fresh && !is_file($file)) {
			[$data, $ext] = kindle_book_file($bookid);
			$tmp = sys_get_temp_dir() . "/fb2xp-$bookid-" . getmypid();
			@mkdir($tmp, 0775, true);
			ini_set('memory_limit', '512M');
			$conv = new Fb2Html($bookid, '');
			$ok = $ext === 'fb2' && $conv->convert($data, $tmp);
			array_map('unlink', glob("$tmp/*"));
			@rmdir($tmp);
			if (!$ok) {
				return null;
			}
			file_put_contents("$file.tmp" . getmypid(), implode("\n", $conv->xps));
			rename("$file.tmp" . getmypid(), $file);
		}
	}
	return explode("\n", file_get_contents($file));
}
