<?php
function nl2p($string) {
	$paragraphs = '';
	foreach (explode("\n", $string) as $line) {
		if (trim($line)) {
			$paragraphs .= '<p>' . h($line) . '</p>';
		}
	}
	return $paragraphs;
}

// имя файла в архиве — из libfilename, как в usr.php
$stmt = $dbh->prepare("SELECT filename FROM libfilename WHERE bookid=:id");
$stmt->execute([':id' => $url->var1]);
$content = $zip->getFromName($stmt->fetchColumn() ?: "$url->var1.txt");
if (!mb_detect_encoding($content, 'UTF-8', true)) {
	$content =iconv('windows-1251//IGNORE', 'UTF-8//IGNORE', $content);
}
$content = nl2p($content);
echo "<section>";
echo str_replace("<p>***</p>",  '<div class="divider div-transparent div-dot"></div>', str_replace("section>>", "section>", $content));
echo "</section>";

