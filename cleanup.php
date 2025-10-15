<?php
/**
 * cleanup.php
 *
 * Optional script intended to be run as a cron job.
 * - Deletes files older than retention_days as specified in config.php (if > 0).
 * - Removes their metadata entries from metadata.json (or DB).
 *
 * To use:
 * - Place on server and configure a cron that hits it (CLI: php cleanup.php) or run via web (less ideal).
 */

$config = require __DIR__ . '/config.php';

$retention = (int) $config->retention_days;
if ($retention <= 0) {
    echo "Retention disabled. Exiting.\n";
    exit;
}

$threshold = strtotime("-{$retention} days");
$deleted = 0;

// Helper to remove entries in JSON
function remove_entries_older_than($timestamp, $config, &$deleted) {
    $metaFile = $config->metadata_file;
    if (!file_exists($metaFile)) return;
    $fp = fopen($metaFile, 'c+');
    if (!$fp) return;
    if (flock($fp, LOCK_EX)) {
        $contents = stream_get_contents($fp);
        $arr = [];
        if ($contents !== false && trim($contents) !== '') $arr = json_decode($contents, true);
        if (!is_array($arr)) $arr = [];
        $new = [];
        foreach ($arr as $entry) {
            $uploaded = isset($entry['uploaded_at']) ? strtotime($entry['uploaded_at']) : 0;
            if ($uploaded > 0 && $uploaded < $timestamp) {
                // delete file
                $path = rtrim($config->upload_dir, '/\\') . DIRECTORY_SEPARATOR . $entry['stored_name'];
                if (file_exists($path)) unlink($path);
                $deleted++;
            } else {
                $new[] = $entry;
            }
        }
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($new, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

// If using DB, delete DB records and files accordingly
if (!empty($config->pdo_dsn) && !empty($config->pdo_user)) {
    try {
        $pdo = new PDO($config->pdo_dsn, $config->pdo_user, $config->pdo_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $stmt = $pdo->query("SELECT id, stored_name, uploaded_at FROM `{$config->pdo_table}`");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $uploaded = isset($row['uploaded_at']) ? strtotime($row['uploaded_at']) : 0;
            if ($uploaded > 0 && $uploaded < $threshold) {
                $path = rtrim($config->upload_dir, '/\\') . DIRECTORY_SEPARATOR . $row['stored_name'];
                if (file_exists($path)) {
                    unlink($path);
                    $deleted++;
                }
                $del = $pdo->prepare("DELETE FROM `{$config->pdo_table}` WHERE id = :id");
                $del->execute([':id' => $row['id']]);
            }
        }
    } catch (Exception $e) {
        // fallback to JSON
        error_log("PDO cleanup error: " . $e->getMessage());
        remove_entries_older_than($threshold, $config, $deleted);
    }
} else {
    remove_entries_older_than($threshold, $config, $deleted);
}

echo "Cleanup complete. Files deleted: {$deleted}\n";
