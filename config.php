<?php
/**
 * config.php
 *
 * Central configuration for the video hosting project.
 * - Edit DB constants if you want MySQL integration. Leave empty to disable DB.
 * - Set GLOBAL_DOWNLOAD_PASSWORD to require a shared password for downloads.
 * - Adjust MAX_FILESIZE_BYTES and ALLOWED_MIME for upload restrictions.
 *
 * This file is optional but recommended for customizing behavior.
 */

return (object) [
    // Upload directory (relative to this file). Ensure writable by webserver.
    'upload_dir' => __DIR__ . '/uploads',

    // Metadata file path (JSON used if MySQL is not configured)
    'metadata_file' => __DIR__ . '/metadata.json',

    // Maximum upload size in bytes (default 500 MB). Note: PHP's upload_max_filesize and post_max_size
    // must be >= this value in php.ini (or overridden in .htaccess if supported).
    'max_filesize_bytes' => 500 * 1024 * 1024,

    // Allowed MIME types for uploaded videos. Keep a reasonable list for safety.
    'allowed_mime' => [
        'video/mp4',
        'video/webm',
        'video/ogg',
        'video/quicktime', // mov
        'video/x-msvideo', // avi
        'video/x-matroska' // mkv
    ],

    // Optional global download password. If set to non-empty string, downloads require ?pw=PASSWORD
    // or submission via form to allow download. Leave empty to disable.
    'global_download_password' => '',

    // Optional MySQL configuration. To enable, fill in DSN, user and pass. Example DSN for MySQL:
    // "mysql:host=localhost;dbname=your_db_name;charset=utf8mb4"
    // If left empty, metadata.json will be used for metadata storage.
    'pdo_dsn' => '', // e.g., "mysql:host=localhost;dbname=videos;charset=utf8mb4"
    'pdo_user' => '',
    'pdo_pass' => '',

    // If using MySQL, table name to store file metadata.
    'pdo_table' => 'files',

    // Retention days used by cleanup.php (cron) - files older than this may be auto-deleted. 0 to disable.
    'retention_days' => 30,
];
