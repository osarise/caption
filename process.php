<?php
// ClearFrame - Diagnostic Process Handler
// Shows detailed error messages for debugging

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

// Helper function
function respond($success, $message = '', $data = []) {
    exit(json_encode(array_merge(['success' => $success, 'message' => $message], $data)));
}

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method: ' . $_SERVER['REQUEST_METHOD']);
}

// Check if file was uploaded
if (!isset($_FILES['video'])) {
    respond(false, 'No file uploaded - $_FILES[\'video\'] is missing');
}

$file = $_FILES['video'];

// Check upload errors
if ($file['error'] !== UPLOAD_ERR_OK) {
    $errors = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize in php.ini',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE in form',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'File upload stopped by extension',
    ];
    $error_msg = $errors[$file['error']] ?? 'Unknown upload error';
    respond(false, 'Upload error: ' . $error_msg . ' (Code: ' . $file['error'] . ')');
}

// Get file info
$filename = $file['name'];
$tmp_path = $file['tmp_name'];
$filesize = $file['size'];

// Validate file exists
if (!file_exists($tmp_path) || !is_file($tmp_path)) {
    respond(false, 'Temp file not found at: ' . $tmp_path);
}

// Check MIME type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
if (!$finfo) {
    respond(false, 'Could not initialize file info function');
}

$mime = finfo_file($finfo, $tmp_path);
finfo_close($finfo);

$allowed_mimes = ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska', 'video/webm', 'application/octet-stream'];

if (!in_array($mime, $allowed_mimes)) {
    respond(false, 'Invalid file type: ' . $mime . ' (File: ' . $filename . '). Allowed: MP4, MOV, AVI, MKV, WebM');
}

// Check file size
if ($filesize > 500 * 1024 * 1024) {
    respond(false, 'File too large: ' . round($filesize / 1024 / 1024, 1) . 'MB (max 500MB)');
}

// Create temp directories
$upload_dir = sys_get_temp_dir() . '/clearframe_uploads';
$output_dir = sys_get_temp_dir() . '/clearframe_outputs';

if (!@mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
    respond(false, 'Cannot create upload directory: ' . $upload_dir);
}

if (!@mkdir($output_dir, 0755, true) && !is_dir($output_dir)) {
    respond(false, 'Cannot create output directory: ' . $output_dir);
}

// Check directory permissions
if (!is_writable($upload_dir)) {
    respond(false, 'Upload directory is not writable: ' . $upload_dir);
}

if (!is_writable($output_dir)) {
    respond(false, 'Output directory is not writable: ' . $output_dir);
}

// Generate unique ID
$session_id = uniqid('cf_', true);
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION)) ?: 'mp4';
$input_file = $upload_dir . '/' . $session_id . '_input.' . $ext;
$output_file = $output_dir . '/' . $session_id . '_output.mp4';

// Move uploaded file
if (!move_uploaded_file($tmp_path, $input_file)) {
    respond(false, 'Failed to move uploaded file from ' . $tmp_path . ' to ' . $input_file);
}

if (!file_exists($input_file)) {
    respond(false, 'Input file not found after move: ' . $input_file);
}

// Get parameters
$y_pos = intval($_POST['y_pos'] ?? 80);
$h_size = intval($_POST['h_size'] ?? 15);
$fill_method = $_POST['fill_method'] ?? 'black';
$quality = intval($_POST['quality'] ?? 28);

// Validate parameters
$y_pos = max(0, min(90, $y_pos));
$h_size = max(5, min(50, $h_size));
$quality = max(0, min(51, $quality));

// ===== CRITICAL: Check FFmpeg =====
$ffmpeg_path = trim(shell_exec('which ffmpeg 2>/dev/null') ?? '');

if (!$ffmpeg_path) {
    @unlink($input_file);
    respond(false, 
        'FFmpeg is NOT installed on this server. Contact InfinityFree support to enable FFmpeg.'
    );
}

if (!file_exists($ffmpeg_path)) {
    @unlink($input_file);
    respond(false, 'FFmpeg not found at: ' . $ffmpeg_path);
}

// Test FFmpeg version
$version_output = shell_exec($ffmpeg_path . ' -version 2>&1 | head -1');
if (!$version_output || strpos($version_output, 'ffmpeg') === false) {
    @unlink($input_file);
    respond(false, 'FFmpeg not responding: ' . $version_output);
}

// Get video duration
$duration_cmd = $ffmpeg_path . ' -i ' . escapeshellarg($input_file) . ' 2>&1 | grep Duration';
$duration_output = shell_exec($duration_cmd);

$duration = 0;
if ($duration_output && preg_match('/Duration: (\d+):(\d+):([\d.]+)/', $duration_output, $m)) {
    $duration = ($m[1] * 3600) + ($m[2] * 60) + floatval($m[3]);
}

if ($duration === 0) {
    @unlink($input_file);
    respond(false, 'Could not determine video duration. File may be invalid or corrupted.');
}

// Validate duration (max 10 minutes)
if ($duration > 600) {
    @unlink($input_file);
    respond(false, 'Video is ' . round($duration / 60, 1) . ' minutes (max 10 minutes)');
}

// Build FFmpeg filter
$y_expr = "ih*" . ($y_pos / 100);
$h_expr = "ih*" . ($h_size / 100);

$video_filter = '';
$use_filter_complex = false;

switch ($fill_method) {
    case 'crop':
        $keep_h = 100 - $h_size;
        $video_filter = "crop=iw:ih*" . ($keep_h / 100) . ":0:0";
        break;
    case 'blur':
        $video_filter = "[0:v]split=2[main][copy];[copy]crop=iw:{$h_expr}:0:{$y_expr},gblur=sigma=30[blurred];[main][blurred]overlay=0:{$y_expr}";
        $use_filter_complex = true;
        break;
    case 'white':
        $video_filter = "drawbox=x=0:y={$y_expr}:w=iw:h={$h_expr}:color=white:t=fill";
        break;
    case 'black':
    default:
        $video_filter = "drawbox=x=0:y={$y_expr}:w=iw:h={$h_expr}:color=black:t=fill";
        break;
}

// Build FFmpeg command
$cmd_parts = [
    escapeshellcmd($ffmpeg_path),
    '-i ' . escapeshellarg($input_file),
    '-sn',
];

if ($use_filter_complex) {
    $cmd_parts[] = '-filter_complex ' . escapeshellarg($video_filter);
    $cmd_parts[] = '-map [main]';
    $cmd_parts[] = '-map 0:a?';
} else {
    $cmd_parts[] = '-vf ' . escapeshellarg($video_filter);
}

$cmd_parts[] = '-c:v libx264';
$cmd_parts[] = '-crf ' . intval($quality);
$cmd_parts[] = '-preset ultrafast';
$cmd_parts[] = '-c:a aac';
$cmd_parts[] = '-b:a 128k';
$cmd_parts[] = '-movflags +faststart';
$cmd_parts[] = escapeshellarg($output_file);
$cmd_parts[] = '2>&1';

$cmd = implode(' ', $cmd_parts);

// Execute FFmpeg
$output_lines = [];
$return_code = 0;
exec($cmd, $output_lines, $return_code);

// Check if output file was created
if (!file_exists($output_file) || filesize($output_file) === 0) {
    @unlink($input_file);
    
    $error_detail = implode("\n", array_slice($output_lines, -5));
    
    respond(false, 
        'FFmpeg failed (Code: ' . $return_code . '). Last error: ' . substr($error_detail, 0, 150)
    );
}

// Success
$output_size = filesize($output_file);
@unlink($input_file);

respond(true, 'Success', [
    'output_file' => 'download.php?file=' . urlencode($session_id),
    'output_size' => formatBytes($output_size),
    'session_id' => $session_id,
]);

function formatBytes($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1024 / 1024, 1) . ' MB';
}
?>
