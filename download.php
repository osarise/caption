<?php
// ClearFrame - Download Handler
// Serves processed video files and cleans temp files after download

if (!isset($_GET['file'])) {
    http_response_code(400);
    exit('Invalid request');
}

$session_id = $_GET['file'];

// Validate session ID (prevent directory traversal)
if (!preg_match('/^cf_[a-f0-9_.]+$/', $session_id)) {
    http_response_code(400);
    exit('Invalid file');
}

$output_dir = sys_get_temp_dir() . '/clearframe_outputs';
$output_file = $output_dir . '/' . $session_id . '_output.mp4';

// Check file exists
if (!file_exists($output_file)) {
    http_response_code(404);
    exit('File not found or expired');
}

// Get file size
$filesize = filesize($output_file);

// Send file
header('Content-Type: video/mp4');
header('Content-Disposition: attachment; filename="clearframe-output.mp4"');
header('Content-Length: ' . $filesize);
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Stream the file
readfile($output_file);

// Clean up after download
register_shutdown_function(function() use ($output_file, $output_dir, $session_id) {
    @unlink($output_file);
    // Clean up old files (older than 1 hour)
    $cutoff = time() - 3600;
    foreach (glob($output_dir . '/*') as $file) {
        if (is_file($file) && filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
});
?>
