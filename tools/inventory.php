<?php
$root = dirname(__DIR__) . '/app';
$patterns = [
    'mysql_date' => '/\b(?:CURDATE|DATE_FORMAT|DATE_SUB|YEAR|MONTH|DAY)\s*\(/i',
    'mysql_upsert' => '/INSERT\s+IGNORE|ON\s+DUPLICATE\s+KEY|\bVALUES\s*\(\s*(?:quantity|item|is_)/i',
    'mysql_helpers' => '/\b(?:IFNULL|GROUP_CONCAT|FIELD|LAST_INSERT_ID|SHOW\s+COLUMNS|DESCRIBE)\b/i',
    'generated_id' => '/lastInsertId\s*\(/i',
    'local_file_storage' => '/move_uploaded_file|\bunlink\s*\(|\breadfile\s*\(|\bis_file\s*\(/i',
    'session' => '/session_start|session_save_path|session_regenerate_id|session_destroy/i',
];
$result = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file->getExtension() !== 'php') continue;
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    foreach (file($file->getPathname()) as $index => $line) {
        foreach ($patterns as $kind => $pattern) {
            if (preg_match($pattern, $line)) $result[$kind][] = $relative . ':' . ($index + 1);
        }
    }
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
