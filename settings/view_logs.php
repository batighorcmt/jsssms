<?php
// Simple log viewer for debugging
session_start();
@include_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: " . BASE_URL . "auth/login.php");
    exit();
}

$logFile = __DIR__ . '/../logs/php_errors.log';
$lines = 100; // Show last 100 lines

?>
<!DOCTYPE html>
<html>
<head>
    <title>Error Logs - JSSSMS</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #1e1e1e; color: #d4d4d4; }
        .log-entry { padding: 8px; margin: 4px 0; background: #2d2d2d; border-left: 3px solid #f48771; }
        .log-entry.warning { border-left-color: #dcdcaa; }
        .log-entry.info { border-left-color: #4ec9b0; }
        h1 { color: #569cd6; }
        .controls { margin: 20px 0; }
        .controls a { color: #4fc3f7; text-decoration: none; padding: 8px 16px; background: #2d2d2d; border-radius: 4px; }
        .controls a:hover { background: #3d3d3d; }
    </style>
</head>
<body>
    <h1>PHP Error Logs</h1>
    <div class="controls">
        <a href="?refresh=1">Refresh</a>
        <a href="?clear=1" onclick="return confirm('Clear all logs?')">Clear Logs</a>
        <a href="<?= BASE_URL ?>settings/manage_exams.php">Back to Exams</a>
    </div>
    
    <?php
    if (isset($_GET['clear'])) {
        file_put_contents($logFile, '');
        echo '<p style="color: #4ec9b0;">Logs cleared successfully!</p>';
    }
    
    if (file_exists($logFile)) {
        $content = file($logFile);
        $recentLines = array_slice($content, -$lines);
        
        echo '<div style="background: #2d2d2d; padding: 16px; border-radius: 4px;">';
        echo '<p>Showing last ' . count($recentLines) . ' lines:</p>';
        
        if (empty($recentLines)) {
            echo '<p style="color: #4ec9b0;">No errors logged yet.</p>';
        } else {
            foreach ($recentLines as $line) {
                $class = 'log-entry';
                if (stripos($line, 'warning') !== false) $class .= ' warning';
                if (stripos($line, 'notice') !== false) $class .= ' info';
                
                echo '<div class="' . $class . '">' . htmlspecialchars($line) . '</div>';
            }
        }
        echo '</div>';
    } else {
        echo '<p style="color: #dcdcaa;">Log file does not exist yet: ' . htmlspecialchars($logFile) . '</p>';
    }
    ?>
</body>
</html>
