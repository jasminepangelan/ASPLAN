<?php
// Simple EXPLAIN runner for environments without mysql/docker clients.
// Usage:
//   set RAILWAY_HOST, RAILWAY_PORT, RAILWAY_USER, RAILWAY_PASSWORD, RAILWAY_DATABASE in your shell
//   php dev/run_explain.php dev/query_explain_sample.sql > explain_output.txt

$env = function($k, $default = null) {
    $v = getenv($k);
    return $v !== false ? $v : $default;
};

$host = $env('RAILWAY_HOST');
$port = (int)$env('RAILWAY_PORT', 3306);
$user = $env('RAILWAY_USER');
$pass = $env('RAILWAY_PASSWORD');
$db   = $env('RAILWAY_DATABASE');

if ($host === null || $user === null || $db === null) {
    fwrite(STDERR, "Missing required env vars. Set RAILWAY_HOST, RAILWAY_USER, RAILWAY_DATABASE and optionally RAILWAY_PORT, RAILWAY_PASSWORD\n");
    exit(2);
}

$file = $argv[1] ?? null;
if (!$file || !file_exists($file)) {
    fwrite(STDERR, "Provide a query file path as first argument (e.g. dev/query_explain_sample.sql)\n");
    exit(3);
}

$sql = file_get_contents($file);
if ($sql === false) {
    fwrite(STDERR, "Unable to read query file: $file\n");
    exit(4);
}

$mysqli = new mysqli($host, $user, $pass, $db, $port);
if ($mysqli->connect_errno) {
    fwrite(STDERR, "Connect error ({$mysqli->connect_errno}): {$mysqli->connect_error}\n");
    exit(5);
}

$parts = preg_split('/;\s*\n/', $sql);
foreach ($parts as $part) {
    $q = trim($part);
    if ($q === '') continue;
    echo "-- QUERY START --\n";
    echo $q . "\n";
    $res = $mysqli->query($q);
    if ($res === false) {
        echo "ERROR: " . $mysqli->error . "\n";
        continue;
    }
    if ($res === true) {
        echo "OK\n";
        continue;
    }
    // print header
    $fields = $res->fetch_fields();
    $names = array_map(function($f){ return $f->name; }, $fields);
    echo implode("\t", $names) . "\n";
    while ($row = $res->fetch_assoc()) {
        $cols = [];
        foreach ($names as $n) {
            $cols[] = isset($row[$n]) ? $row[$n] : '';
        }
        echo implode("\t", $cols) . "\n";
    }
    $res->free();
    echo "-- QUERY END --\n\n";
}

$mysqli->close();

exit(0);
