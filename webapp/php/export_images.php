<?php
// 一括エクスポート: posts.imgdata → public/image/{id}.{ext}
// 実行: php export_images.php
$host = $_SERVER['ISUCONP_DB_HOST'] ?? getenv('ISUCONP_DB_HOST') ?: 'localhost';
$port = $_SERVER['ISUCONP_DB_PORT'] ?? getenv('ISUCONP_DB_PORT') ?: 3306;
$user = getenv('ISUCONP_DB_USER') ?: 'isuconp';
$pass = getenv('ISUCONP_DB_PASSWORD') ?: 'isuconp';
$name = getenv('ISUCONP_DB_NAME') ?: 'isuconp';

$dir = dirname(__DIR__) . '/public/image';
@mkdir($dir, 0775, true);

$ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];

$db = new PDO("mysql:dbname={$name};host={$host};port={$port}", $user, $pass);
// ストリーミング（全件メモリに載せない）
$db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

$stmt = $db->query('SELECT `id`, `mime`, `imgdata` FROM `posts` ORDER BY `id`');
$count = 0; $bytes = 0;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $e = $ext[$row['mime']] ?? null;
    if ($e === null) continue;
    $path = "{$dir}/{$row['id']}.{$e}";
    file_put_contents($path, $row['imgdata']);
    $count++;
    $bytes += strlen($row['imgdata']);
    if ($count % 1000 === 0) {
        fwrite(STDERR, "exported {$count} (".round($bytes/1024/1024)."MB)\n");
    }
}
fwrite(STDERR, "DONE: exported {$count} images, ".round($bytes/1024/1024)."MB\n");
