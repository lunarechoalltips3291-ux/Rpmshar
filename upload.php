<?php
/**
 * upload.php
 *
 * Handles video uploads:
 * - Validates file size and MIME type.
 * - Generates a unique ID + stored filename to prevent collisions.
 * - Moves uploaded file to uploads/ directory.
 * - Updates metadata.json (or MySQL if configured).
 * - Returns JSON when the request is an XHR (AJAX) call; otherwise a simple HTML page.
 *
 * IMPORTANT:
 * Ensure the uploads/ directory exists and is writable by the web server (chmod 755 or 775).
 */

$config = require __DIR__ . '/config.php';

// Helper: send JSON response and exit
function jsonResponse($data, $status = 200) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// Helper: send simple HTML fallback
function htmlPage($title, $body) {
    echo "<!doctype html><html><head><meta charset='utf-8'><title>$title</title></head><body>$body</body></html>";
    exit;
}

// Ensure uploads directory exists
if (!is_dir($config->upload_dir)) {
    if (!mkdir($config->upload_dir, 0755, true)) {
        if (headers_sent()) {
            htmlPage("Upload error", "Failed to create uploads directory.");
        }
        jsonResponse(['error' => 'Failed to create uploads directory.'], 500);
    }
}

// Check method and file presence
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['video'])) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        jsonResponse(['error' => 'No file sent.'], 400);
    } else {
        htmlPage('Upload', '<p>No file uploaded. <a href="index.html">Back</a></p>');
    }
}

$file = $_FILES['video'];

// Check PHP upload errors
if ($file['error'] !== UPLOAD_ERR_OK) {
    $err = $file['error'];
    $msg = "Upload error (code $err).";
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        jsonResponse(['error' => $msg], 400);
    } else {
        htmlPage('Upload error', "<p>$msg</p><p><a href='index.html'>Back</a></p>");
    }
}

// Validate filesize
if ($file['size'] > $config->max_filesize_bytes) {
    $msg = "File exceeds the maximum allowed size.";
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        jsonResponse(['error' => $msg], 400);
    } else {
        htmlPage('Upload error', "<p>$msg</p><p><a href='index.html'>Back</a></p>");
    }
}

// Validate MIME type using finfo
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
if (!in_array($mime, $config->allowed_mime)) {
    $msg = "Invalid file type: $mime";
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        jsonResponse(['error' => $msg], 400);
    } else {
        htmlPage('Upload error', "<p>$msg</p><p><a href='index.html'>Back</a></p>");
    }
}

// Generate unique ID and filename (random hex + original extension)
try {
    $id = bin2hex(random_bytes(10)); // 20 hex chars
} catch (Exception $e) {
    $id = uniqid();
}
$origName = basename($file['name']);
$ext = pathinfo($origName, PATHINFO_EXTENSION);
$ext = preg_replace('/[^A-Za-z0-9]/', '', $ext); // sanitize extension
$storedName = $id . ($ext ? '.' . $ext : '');
$targetPath = rtrim($config->upload_dir, '/\\') . DIRECTORY_SEPARATOR . $storedName;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    $msg = "Failed to move uploaded file.";
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        jsonResponse(['error' => $msg], 500);
    } else {
        htmlPage('Upload error', "<p>$msg</p><p><a href='index.html'>Back</a></p>");
    }
}

// Build metadata entry
$entry = [
    'id' => $id,
    'original_name' => $origName,
    'stored_name' => $storedName,
    'size' => (int) $file['size'],
    'mime' => $mime,
    'uploaded_at' => date('c'),
    'downloads' => 0,
    'title' => isset($_POST['title']) ? substr(trim($_POST['title']), 0, 250) : '',
    'password' => isset($_POST['password']) && $_POST['password'] !== '' ? substr($_POST['password'], 0, 100) : '' // optional per-file password
];

// Update metadata.json (safe with locking) unless DB is configured
$metadata_updated = false;
if (!empty($config->pdo_dsn) && !empty($config->pdo_user)) {
    // Try to store metadata in MySQL via PDO
    try {
        $pdo = new PDO($config->pdo_dsn, $config->pdo_user, $config->pdo_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        // Create table if not exists (simple schema)
        $sql = "CREATE TABLE IF NOT EXISTS `{$config->pdo_table}` (
            `id` varchar(64) NOT NULL PRIMARY KEY,
            `original_name` varchar(512),
            `stored_name` varchar(512),
            `size` int,
            `mime` varchar(128),
            `uploaded_at` datetime,
            `downloads` int default 0,
            `title` varchar(255),
            `password` varchar(255)
        ) CHARACTER SET utf8mb4";
        $pdo->exec($sql);

        // Insert
        $stmt = $pdo->prepare("INSERT INTO `{$config->pdo_table}` (id, original_name, stored_name, size, mime, uploaded_at, downloads, title, password) VALUES (:id, :original_name, :stored_name, :size, :mime, :uploaded_at, :downloads, :title, :password)");
        $stmt->execute([
            ':id' => $entry['id'],
            ':original_name' => $entry['original_name'],
            ':stored_name' => $entry['stored_name'],
            ':size' => $entry['size'],
            ':mime' => $entry['mime'],
            ':uploaded_at' => date('Y-m-d H:i:s'),
            ':downloads' => $entry['downloads'],
            ':title' => $entry['title'],
            ':password' => $entry['password']
        ]);
        $metadata_updated = true;
    } catch (Exception $e) {
        // fallback to JSON file if DB fails
        error_log("PDO error: " . $e->getMessage());
        $metadata_updated = false;
    }
}

if (!$metadata_updated) {
    // Use metadata.json (append)
    $metaFile = $config->metadata_file;
    $tries = 0;
    $success = false;
    while ($tries < 3 && !$success) {
        $tries++;
        $fp = fopen($metaFile, 'c+'); // create if not exists
        if (!$fp) break;
        if (flock($fp, LOCK_EX)) {
            // Read existing
            clearstatcache(true, $metaFile);
            $contents = stream_get_contents($fp);
            $arr = [];
            if ($contents !== false && trim($contents) !== '') {
                $arr = json_decode($contents, true);
                if (!is_array($arr)) $arr = [];
            }
            $arr[] = $entry;
            // Truncate and write
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($arr, JSON_PRETTY_PRINT));
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
            $success = true;
            break;
        } else {
            fclose($fp);
            usleep(50000); // wait 50ms
        }
    }
    if (!$success) {
        // Not fatal; log error
        error_log("Failed to update metadata.json");
    }
}

// Build response data
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
    . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';

$previewUrl = $baseUrl . 'preview.php?id=' . urlencode($entry['id']);
$downloadUrl = $baseUrl . 'download.php?id=' . urlencode($entry['id']);

// If request is XHR, return JSON
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    jsonResponse([
        'success' => true,
        'id' => $entry['id'],
        'preview' => $previewUrl,
        'download' => $downloadUrl,
        'entry' => $entry
    ]);
} else {
    // HTML fallback
    $html = "<h1>Upload successful</h1>";
    $html .= "<p>Title: " . htmlspecialchars($entry['title']) . "</p>";
    $html .= "<p>Original filename: " . htmlspecialchars($entry['original_name']) . "</p>";
    $html .= "<p>Preview: <a href='$previewUrl'>$previewUrl</a></p>";
    $html .= "<p>Download: <a href='$downloadUrl'>$downloadUrl</a></p>";
    $html .= "<p><a href='index.html'>Back to home</a></p>";
    htmlPage('Upload successful', $html);
}
