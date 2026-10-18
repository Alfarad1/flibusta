<?php
// Картинки FB2 для читалки: извлекаются при конвертации книги (fb2html.php)
include('../init.php');
$id = intval($_GET['id'] ?? 0);
$n = intval($_GET['n'] ?? -1);
$book = $id > 0 ? fb2html_book($id, '') : null;
$img = $book ? (array_values((array)$book->images)[$n] ?? null) : null;
$file = $img ? FB2HTML_CACHE . "/$id/" . basename($img[0]) : '';
if (!$img || !is_file($file)) {
	http_response_code(404);
	die();
}
header('Content-Type: ' . $img[1]);
header('Content-Length: ' . filesize($file));
header('Cache-Control: public, max-age=31536000, immutable');
readfile($file);
