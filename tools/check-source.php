<?php
$root = dirname(__DIR__);
$failures = [];
$checked = 0;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file->getExtension() !== 'php') continue;
    $output = []; $status = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $output, $status);
    $checked++;
    if ($status !== 0) $failures[] = implode("\n", $output);
}
foreach (['app/generate_hash.php', 'app/.git', 'app/uploads', '.env', 'private-data'] as $path) {
    if (file_exists($root . '/' . $path)) $failures[] = 'Unexpected private/local content: ' . $path;
}
$sql = file_get_contents($root . '/supabase/migrations/202610290001_initial_schema.sql');
foreach (['roles','users','categories','order_statuses','stores','menu_items','daily_menu','orders','order_items','payments','order_status_logs','sales_records'] as $table) {
    if (!str_contains($sql, 'CREATE TABLE public.' . $table . ' (')) $failures[] = 'Missing table: ' . $table;
    if (!str_contains($sql, 'ALTER TABLE public.' . $table . ' ENABLE ROW LEVEL SECURITY;')) $failures[] = 'Missing RLS: ' . $table;
}
if (preg_match('/INSERT\s+INTO\s+(?:public\.)?(?:users|orders|payments)\b/i', $sql)) $failures[] = 'Unexpected real/dummy account or transaction seeds';
if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL); exit(1); }
echo 'PASS: ' . $checked . ' copied PHP files parse; private files excluded; 12 draft tables have RLS; no customer-data seeds.' . PHP_EOL;
echo 'NOTE: these checks do not execute or validate SQL against PostgreSQL.' . PHP_EOL;
