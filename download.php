<?php
/**
 * download.php
 *
 * Serves files to clients with Content-Disposition: attachment.
 * - Validates requested ID against metadata.json or MySQL.
 * - Optionally requires a global or per-file password.
 * - Increments download counter.
 * - Streams the file with proper headers.
 *
 * Note: This implementation does not implement HTTP Range requests for partial streaming.
 * If you need seekable streaming, consider serving the file directly by URL (webserver) or implementing Range support.
 */

$config = require __DIR__ . '/config.php';

// Helper: show simple HTML form/message
function htmlMessage($title, $html) {
    echo "<!doctype html><html><head><meta charset='utf-8'><title>$title</title></head><body>$html</body></html>";
    exit;
}

// Fetch file metadata by id (from JSON or DB)
function get_metadata_entry($id, $config) {
    if (!empty($config->pdo_dsn) && !empty($config->pdo_user)) {
        try {
            $pdo = new PDO($config->pdo_dsn, $config->pdo_user, $config->pdo_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $stmt = $pdo->prepare("SELECT * FROM `{$config->pdo_table}` WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                // Map DB fields to expected array keys
                return [
                    'id' => $row['id'],
                    'original_name' => $row['original_name'],
                    'stored_name' => $row['stored_name'],
                    'size' => (int)$row['size'],
                    'mime' => $row['mime'],
                    'uploaded_at' => $row['uploaded_at'],
                    'downloads' => (int)$row['downloads'],
                    'title' => $row['title'],
                    'password' => $row['password'],
                ];
            }
        } catch (Exception $e) {
            error_log("PDO get error: " . $e->getMessage());
            // fallback to JSON
        }
    }

    // JSON fallback
    $metaFile = $config->metadata_file;
    if (!file_exists($metaFile)) return null;
    $contents = file_get_contents($metaFile);
    $arr = json_decode($contents, true);
    if (!is_array($arr)) return null;
    foreach ($arr as $entry) {
        if (isset($entry['id']) && $entry['id'] === $id) return $entry;
    }
    return null;
}

// Update downloads counter (DB or JSON)
function increment_downloads($id, $config) {
    if (!empty($config->pdo_dsn) && !empty($config->pdo_user)) {
        try {
            $pdo = new PDO($config->pdo_dsn, $config->pdo_user, $config->pdo_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $stmt = $pdo->prepare("UPDATE `{$config->pdo_table}` SET downloads = downloads + 1 WHERE id = :id");
            $stmt->execute([':id' => $id]);
            return;
        } catch (Exception $e) {
            error_log("PDO increment error: " . $e->getMessage());
            // fallback to JSON
        }
    }

    // JSON fallback
    $metaFile = $config->metadata_file;
    $tries = 0;
    while ($tries < 3) {
        $tries++;
        $fp = fopen($metaFile, 'c+');
        if (!$fp) break;
        if (flock($fp, LOCK_EX)) {
            $contents = stream_get_contents($fp);
            $arr = [];
            if ($contents !== false && trim($contents) !== '') {
                $arr = json_decode($contents, true);
                if (!is_array($arr)) $arr = [];
            }
            $changed = false;
            foreach ($arr as &$entry) {
                if (isset($entry['id']) && $entry['id'] === $id) {
                    $entry['downloads'] = isset($entry['downloads']) ? ((int)$entry['downloads'] + 1) : 1;
                    $changed = true;
                    break;
                }
            }
            if ($changed) {
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($arr, JSON_PRETTY_PRINT));
                fflush($fp);
            }
            flock($fp, LOCK_UN);
            fclose($fp);
            break;
        } else {
            fclose($fp);
            usleep(50000);
        }
    }
}

// Main
if (empty($_GET['id'])) {
    htmlMessage('Error', '<p>Missing id parameter.</p>');
}
$id = $_GET['id'];
$entry = get_metadata_entry($id, $config);
if (!$entry) {
    htmlMessage('Not found', '<p>File not found.</p>');
}

$filePath = rtrim($config->upload_dir, '/\\') . DIRECTORY_SEPARATOR . $entry['stored_name'];
if (!file_exists($filePath)) {
    htmlMessage('Not found', '<p>File missing on server.</p>');
}

// Password protection: either global or per-file
$pwRequired = false;
$expectedPw = '';

// Global password
if (!empty($config->global_download_password)) {
    $pwRequired = true;
    $expectedPw = $config->global_download_password;
}

// Per-file password (overrides if set)
if (!empty($entry['password'])) {
    $pwRequired = true;
    $expectedPw = $entry['password'];
}

if ($pwRequired) {
    $provided = '';
    // Accept password via GET 'pw' or POST 'pw'
    if (isset($_REQUEST['pw'])) $provided = (string)$_REQUEST['pw'];
    if ($provided !== $expectedPw) {
        // Show password form
        $form = "<h1>Protected download</h1>";
        $form .= "<p>This file requires a password to download.</p>";
        $form .= "<form method='post'>";
        $form .= "<input type='hidden' name='id' value='" . htmlspecialchars($id, ENT_QUOTES) . "'>";
        $form .= "<label>Password: <input type='password' name='pw'></label> ";
        $form .= "<button type='submit'>Download</button>";
        $form .= "</form>";
        htmlMessage('Password required', $form);
    }
}

// Serve file with headers
$originalName = $entry['original_name'];
$mimeType = $entry['mime'] ?? mime_content_type($filePath);
$filesize = filesize($filePath);

// Clean output buffers
while (ob_get_level()) ob_end_clean();

header('Content-Description: File Transfer');
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . $filesize);
$dispName = str_replace(["\r", "\n"], '', $originalName); // sanitize
header('Content-Disposition: attachment; filename="' . basename($dispName) . '"');
header('Cache-Control: public, must-revalidate, max-age=0');
header('Pragma: public');
header('Expires: 0');

// Increment downloads (best-effort, after headers)
increment_downloads($id, $config);

// Output file
$chunkSize = 8 * 1024 * 1024;
$handle = fopen($filePath, 'rb');
if ($handle === false) {
    htmlMessage('Error', '<p>Failed to open file for reading.</p>');
}
while (!feof($handle)) {
    echo fread($handle, $chunkSize);
    flush();
}
fclose($handle);
exit;
