<?php
/**
 * preview.php
 *
 * Displays an HTML5 video player for a given file id.
 * - Reads metadata (JSON or MySQL) and checks file existence.
 * - Uses the webserver to serve the video file directly: the <video> src points to the uploads/ file.
 *   This allows efficient streaming and seeking if the server supports Range requests.
 *
 * If you want preview protection, you'd need to stream through PHP with Range support (not implemented here).
 */

$config = require __DIR__ . '/config.php';

function htmlPage($title, $body) {
    echo "<!doctype html><html><head><meta charset='utf-8'><title>{$title}</title>";
    echo "<link rel='stylesheet' href='css/style.css'>";
    echo "</head><body>{$body}</body></html>";
    exit;
}

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
        }
    }

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

if (empty($_GET['id'])) {
    htmlPage('Error', '<p>Missing id parameter. <a href="index.html">Home</a></p>');
}
$id = $_GET['id'];
$entry = get_metadata_entry($id, $config);
if (!$entry) {
    htmlPage('Not found', '<p>File not found. <a href="index.html">Home</a></p>');
}

$filePath = 'uploads/' . $entry['stored_name'];
if (!file_exists($config->upload_dir . DIRECTORY_SEPARATOR . $entry['stored_name'])) {
    htmlPage('Missing', '<p>File is missing on server. <a href="index.html">Home</a></p>');
}

// Build page with HTML5 video player
$title = htmlspecialchars($entry['title'] ?: $entry['original_name']);
$downloadUrl = 'download.php?id=' . urlencode($entry['id']);

$body = "<main class='container preview'>";
$body .= "<h1>" . $title . "</h1>";
$body .= "<div class='video-wrapper'>";
$body .= "<video controls controlsList='nodownload' style='max-width:100%;height:auto;' preload='metadata'>";
$body .= "<source src='" . htmlspecialchars($filePath, ENT_QUOTES) . "' type='" . htmlspecialchars($entry['mime']) . "'>";
$body .= "Your browser does not support HTML5 video.";
$body .= "</video>";
$body .= "</div>";
$body .= "<div class='meta'>";
$body .= "<p>Filename: " . htmlspecialchars($entry['original_name']) . "</p>";
$body .= "<p>Size: " . number_format($entry['size'] / 1024 / 1024, 2) . " MB</p>";
$body .= "<p>Uploaded: " . htmlspecialchars($entry['uploaded_at']) . "</p>";
$body .= "<p>Downloads: " . (int)$entry['downloads'] . "</p>";
$body .= "<p><a class='download-btn' href='{$downloadUrl}'>Download</a> &nbsp; <a href='index.html'>Home</a></p>";
$body .= "</div>";
$body .= "</main>";

htmlPage($title, $body);
