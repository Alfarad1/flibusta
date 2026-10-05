<?php
include('../init.php');
header('Cache-Control: public, max-age=86400');

$small = isset($_GET['small']);
if (isset($_GET['id'])) {
	$id = intval($_GET['id']);
} else {
	$id = intval($_GET['sid'] ?? 0);
	$small = true;
}

$covers_dir = ROOT_PATH . 'cache/covers/';
$full_path = $covers_dir . "$id.jpg";
$small_path = $covers_dir . "$id-small.jpg";
// Marker for books that are present in the archives but have no cover.
$none_path = $covers_dir . "$id.none";

function send_cached($path) {
	$fmtimestamp = filemtime($path);
	header("Content-type: image/jpeg");
	if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && $fmtimestamp <= strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
		header($_SERVER['SERVER_PROTOCOL'] . ' 304 Not Modified');
		exit;
	}
	header("Expires: " . gmdate("D, d M Y H:i:s", $fmtimestamp + 60*60*24) . " GMT");
	header("Last-Modified: " . gmdate("D, d M Y H:i:s", $fmtimestamp) . " GMT");
	readfile($path);
	exit;
}

function send_none() {
	header("Content-type: image/jpeg");
	readfile(ROOT_PATH . 'none.jpg');
	exit;
}

function resize_cover($data, $max_w, $max_h) {
	$src = @imagecreatefromstring($data);
	if ($src === false) {
		return null;
	}
	$w = imagesx($src);
	$h = imagesy($src);
	$scale = min(1, $max_w / $w, $max_h / $h);
	$nw = max(1, (int)round($w * $scale));
	$nh = max(1, (int)round($h * $scale));
	$thumb = imagecreatetruecolor($nw, $nh);
	imagecopyresampled($thumb, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
	imagedestroy($src);
	return $thumb;
}

function fb2_cover($data) {
	$prev = libxml_use_internal_errors(true);
	$fb2 = simplexml_load_string($data, 'SimpleXMLElement', LIBXML_PARSEHUGE);
	libxml_clear_errors();
	libxml_use_internal_errors($prev);
	if ($fb2 === false || !isset($fb2->binary)) {
		return null;
	}

	$cover_id = '';
	$image = $fb2->description->{'title-info'}->coverpage->image ?? null;
	if ($image !== null) {
		foreach ($image->attributes('http://www.w3.org/1999/xlink') as $attr) {
			$cover_id = ltrim((string)$attr, '#');
			break;
		}
	}

	$fallback = null;
	foreach ($fb2->binary as $binary) {
		$bid = (string)$binary->attributes()['id'];
		if ($cover_id !== '' && $bid === $cover_id) {
			return base64_decode((string)$binary);
		}
		if ($fallback === null && (stripos($bid, 'cover') !== false || stripos($bid, 'obloj') !== false)) {
			$fallback = $binary;
		}
	}
	return $fallback === null ? null : base64_decode((string)$fallback);
}

function epub_cover($data, $id) {
	$tmp = ROOT_PATH . "cache/tmp/$id.tmp";
	if (@file_put_contents($tmp, $data) === false) {
		return null;
	}
	include_once(ROOT_PATH . 'epub.php');
	try {
		$im = (new EPub($tmp))->Cover();
		$cover = ($im['found'] ?? '') != '' ? $im['data'] : null;
	} catch (Throwable $e) {
		$cover = null;
	}
	@unlink($tmp);
	return $cover;
}

// Returns cover bytes, null if the book has no cover, false if the book file is unavailable.
function find_cover($dbh, $id) {
	$stmt = $dbh->prepare("SELECT file FROM libbpics WHERE BookId=:id LIMIT 1");
	$stmt->execute([':id' => $id]);
	$pic = $stmt->fetch();
	$attached = ROOT_PATH . 'cache/lib.b.attached.zip';
	if ($pic && is_file($attached)) {
		$zip = new ZipArchive();
		if ($zip->open($attached) === true) {
			$data = $zip->getFromName($pic->file);
			$zip->close();
			if ($data !== false && strlen($data) > 100) {
				return $data;
			}
		}
	}

	$stmt = $dbh->prepare("SELECT filetype FROM libbook WHERE BookId=:id LIMIT 1");
	$stmt->execute([':id' => $id]);
	$book = $stmt->fetch();
	if (!$book) {
		return null;
	}
	$type = trim($book->filetype);

	$stmt = $dbh->prepare("SELECT filename FROM book_zip WHERE :id BETWEEN start_id AND end_id AND usr=:usr LIMIT 1");
	$stmt->execute([':id' => $id, ':usr' => $type == 'fb2' ? 0 : 1]);
	$zip_row = $stmt->fetch();
	if (!$zip_row) {
		return false;
	}

	$stmt = $dbh->prepare("SELECT filename FROM libfilename WHERE BookId=:id LIMIT 1");
	$stmt->execute([':id' => $id]);
	$fn = $stmt->fetch();
	$filename = ($fn && $fn->filename != '') ? $fn->filename : "$id.$type";

	$zip = new ZipArchive();
	if ($zip->open(ROOT_PATH . 'flibusta/' . $zip_row->filename) !== true) {
		return false;
	}
	$data = $zip->getFromName($filename);
	$zip->close();
	if ($data === false) {
		return false;
	}

	if ($type == 'fb2') {
		$cover = fb2_cover($data);
	} elseif ($type == 'epub') {
		$cover = epub_cover($data, $id);
	} else {
		$cover = null;
	}
	return ($cover !== null && strlen($cover) > 100) ? $cover : null;
}

if ($id <= 0) {
	send_none();
}
if (is_file($small ? $small_path : $full_path)) {
	send_cached($small ? $small_path : $full_path);
}
if (is_file($none_path)) {
	send_none();
}

$cover = find_cover($dbh, $id);
if ($cover === null) {
	@touch($none_path);
	send_none();
}
if ($cover === false) {
	send_none();
}

@file_put_contents($full_path, $cover);
$thumb = resize_cover($cover, 300, 400);
if ($thumb === null) {
	@unlink($full_path);
	@touch($none_path);
	send_none();
}
@imagejpeg($thumb, $small_path, 75);

header("Content-type: image/jpeg");
if ($small) {
	imagejpeg($thumb, null, 75);
} else {
	echo $cover;
}
imagedestroy($thumb);
