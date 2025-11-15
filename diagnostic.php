<?php
// diagnostic.php - Place in C:/wamp64/www/clockmate/
// Visit: http://localhost/clockmate/diagnostic.php

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ClockMate Diagnostic</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; }
        .test { padding: 15px; margin: 10px 0; border-radius: 5px; }
        .pass { background: #d4edda; border-left: 4px solid #28a745; }
        .fail { background: #f8d7da; border-left: 4px solid #dc3545; }
        .info { background: #d1ecf1; border-left: 4px solid #17a2b8; }
        .test h3 { margin: 0 0 10px 0; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 5px; overflow-x: auto; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: bold; }
        .badge-success { background: #28a745; color: white; }
        .badge-danger { background: #dc3545; color: white; }
        .badge-info { background: #17a2b8; color: white; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 ClockMate Diagnostic Tool</h1>
        <p>This page will help identify configuration issues</p>

        <?php
        // Test 1: PHP Execution
        echo '<div class="test pass">';
        echo '<h3>✅ Test 1: PHP Execution <span class="badge badge-success">PASS</span></h3>';
        echo '<p>PHP is executing correctly! Version: <strong>' . phpversion() . '</strong></p>';
        echo '</div>';

        // Test 2: PDO Extension
        echo '<div class="test ' . (extension_loaded('pdo') ? 'pass' : 'fail') . '">';
        echo '<h3>' . (extension_loaded('pdo') ? '✅' : '❌') . ' Test 2: PDO Extension <span class="badge badge-' . (extension_loaded('pdo') ? 'success' : 'danger') . '">' . (extension_loaded('pdo') ? 'PASS' : 'FAIL') . '</span></h3>';
        if (extension_loaded('pdo')) {
            echo '<p>PDO extension is loaded ✓</p>';
        } else {
            echo '<p><strong>PDO extension is NOT loaded!</strong> Enable it in php.ini</p>';
        }
        echo '</div>';

        // Test 3: MySQL/PDO_MySQL Extension
        echo '<div class="test ' . (extension_loaded('pdo_mysql') ? 'pass' : 'fail') . '">';
        echo '<h3>' . (extension_loaded('pdo_mysql') ? '✅' : '❌') . ' Test 3: MySQL Driver <span class="badge badge-' . (extension_loaded('pdo_mysql') ? 'success' : 'danger') . '">' . (extension_loaded('pdo_mysql') ? 'PASS' : 'FAIL') . '</span></h3>';
        if (extension_loaded('pdo_mysql')) {
            echo '<p>MySQL PDO driver is loaded ✓</p>';
        } else {
            echo '<p><strong>MySQL PDO driver is NOT loaded!</strong> Enable extension=pdo_mysql in php.ini</p>';
        }
        echo '</div>';

        // Test 4: Database Connection
        $db_connected = false;
        $db_error = '';
        try {
            $pdo = new PDO('mysql:host=localhost;dbname=clockmate;charset=utf8mb4', 'root', '');
            $db_connected = true;
        } catch (PDOException $e) {
            $db_error = $e->getMessage();
        }

        echo '<div class="test ' . ($db_connected ? 'pass' : 'fail') . '">';
        echo '<h3>' . ($db_connected ? '✅' : '❌') . ' Test 4: Database Connection <span class="badge badge-' . ($db_connected ? 'success' : 'danger') . '">' . ($db_connected ? 'PASS' : 'FAIL') . '</span></h3>';
        if ($db_connected) {
            echo '<p>Successfully connected to database "clockmate" ✓</p>';
            
            // Check tables
            try {
                $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                echo '<p><strong>Tables found:</strong> ' . implode(', ', $tables) . '</p>';
                
                // Check employees
                $emp_count = $pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn();
                echo '<p><strong>Total employees:</strong> ' . $emp_count . '</p>';
            } catch (PDOException $e) {
                echo '<p class="badge badge-danger">Error querying tables: ' . $e->getMessage() . '</p>';
            }
        } else {
            echo '<p><strong>Cannot connect to database!</strong></p>';
            echo '<pre>' . htmlspecialchars($db_error) . '</pre>';
            echo '<p><strong>Solutions:</strong></p>';
            echo '<ul>';
            echo '<li>Make sure MySQL is running (WAMP icon → MySQL → Start)</li>';
            echo '<li>Create the database: Run setup.sql in phpMyAdmin</li>';
            echo '<li>Check credentials in api/index.php</li>';
            echo '</ul>';
        }
        echo '</div>';

        // Test 5: File Permissions
        $api_exists = file_exists(__DIR__ . '/index.php');
        echo '<div class="test ' . ($api_exists ? 'pass' : 'fail') . '">';
        echo '<h3>' . ($api_exists ? '✅' : '❌') . ' Test 5: API File <span class="badge badge-' . ($api_exists ? 'success' : 'danger') . '">' . ($api_exists ? 'PASS' : 'FAIL') . '</span></h3>';
        if ($api_exists) {
            echo '<p>API file exists: <code>' . __DIR__ . '/index.php</code> ✓</p>';
            echo '<p>File size: ' . filesize(__DIR__ . '/index.php') . ' bytes</p>';
        } else {
            echo '<p><strong>API file NOT found!</strong></p>';
            echo '<p>Expected location: <code>' . __DIR__ . '/index.php</code></p>';
            echo '<p>Make sure you created the / folder and placed index.php inside it</p>';
        }
        echo '</div>';

        // Test 6: mod_rewrite
        echo '<div class="test info">';
        echo '<h3>ℹ️ Test 6: Apache Modules <span class="badge badge-info">INFO</span></h3>';
        if (function_exists('apache_get_modules')) {
            $modules = apache_get_modules();
            echo '<p><strong>mod_rewrite:</strong> ' . (in_array('mod_rewrite', $modules) ? '✅ Enabled' : '❌ Disabled') . '</p>';
            echo '<p><strong>mod_headers:</strong> ' . (in_array('mod_headers', $modules) ? '✅ Enabled' : '❌ Disabled') . '</p>';
        } else {
            echo '<p>Cannot check Apache modules (apache_get_modules not available)</p>';
            echo '<p>Manually verify: WAMP icon → Apache → Apache Modules</p>';
        }
        echo '</div>';

        // Test 7: Important PHP Settings
        echo '<div class="test info">';
        echo '<h3>ℹ️ Test 7: PHP Configuration <span class="badge badge-info">INFO</span></h3>';
        echo '<ul>';
        echo '<li><strong>display_errors:</strong> ' . ini_get('display_errors') . '</li>';
        echo '<li><strong>error_reporting:</strong> ' . error_reporting() . '</li>';
        echo '<li><strong>max_execution_time:</strong> ' . ini_get('max_execution_time') . 's</li>';
        echo '<li><strong>memory_limit:</strong> ' . ini_get('memory_limit') . '</li>';
        echo '<li><strong>upload_max_filesize:</strong> ' . ini_get('upload_max_filesize') . '</li>';
        echo '</ul>';
        echo '</div>';

        // Test 8: URL Test
        echo '<div class="test info">';
        echo '<h3>ℹ️ Test 8: API URL Test <span class="badge badge-info">INFO</span></h3>';
        echo '<p>Try accessing your API directly:</p>';
        echo '<ul>';
        $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']);
        echo '<li><a href="' . $base_url . '/api/index.php" target="_blank">Direct API Access</a></li>';
        echo '<li><a href="' . $base_url . '/api/test" target="_blank">Test Endpoint (with routing)</a></li>';
        echo '<li><a href="' . $base_url . '/test.html" target="_blank">API Tester Page</a></li>';
        echo '</ul>';
        echo '</div>';

        // Summary
        echo '<div class="test ' . ($db_connected && $api_exists && extension_loaded('pdo_mysql') ? 'pass' : 'fail') . '">';
        echo '<h3>📊 Summary</h3>';
        if ($db_connected && $api_exists && extension_loaded('pdo_mysql')) {
            echo '<p><strong>✅ All critical tests passed!</strong> Your ClockMate backend should work.</p>';
            echo '<p>Next steps:</p>';
            echo '<ol>';
            echo '<li>Test your API endpoints using the test page</li>';
            echo '<li>Configure your frontend .env file</li>';
            echo '<li>Start developing!</li>';
            echo '</ol>';
        } else {
            echo '<p><strong>❌ Some tests failed!</strong> Fix the issues above before proceeding.</p>';
        }
        echo '</div>';
        ?>

        <hr>
        <p style="text-align: center; color: #666; font-size: 12px;">
            ClockMate Diagnostic v1.0 | Generated: <?php echo date('Y-m-d H:i:s'); ?>
        </p>
    </div>
</body>
</html>