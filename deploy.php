<?php

use Illuminate\Contracts\Console\Kernel;

$projectPath = __DIR__;

/*
|--------------------------------------------------------------------------
| Validate Path
|--------------------------------------------------------------------------
*/

if (!is_dir($projectPath)) {
    http_response_code(500);
    exit("Path not found");
}

chdir($projectPath);

/*
|--------------------------------------------------------------------------
| Check Vendor
|--------------------------------------------------------------------------
*/

if (!file_exists($projectPath . '/vendor/autoload.php')) {
    http_response_code(500);

    echo "<h2>vendor/autoload.php not found</h2>";
    echo "<p>Current Path:</p>";
    echo "<pre>" . $projectPath . "</pre>";

    exit;
}

/*
|--------------------------------------------------------------------------
| Bootstrap Laravel
|--------------------------------------------------------------------------
*/

require_once $projectPath . '/vendor/autoload.php';

$app = require_once $projectPath . '/bootstrap/app.php';

$kernel = $app->make(Kernel::class);

// Helper closure to read .env file directly
$getEnvValue = function($key, $default = null) use ($projectPath) {
    static $envCache = null;
    
    if ($envCache === null) {
        $envCache = [];
        $envFile = $projectPath . '/.env';
        
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                // Skip comments
                if (strpos(trim($line), '#') === 0) continue;
                
                // Parse KEY=VALUE
                if (strpos($line, '=') !== false) {
                    list($envKey, $envValue) = explode('=', $line, 2);
                    $envKey = trim($envKey);
                    $envValue = trim($envValue);
                    
                    // Remove quotes
                    $envValue = trim($envValue, '"\'');
                    
                    $envCache[$envKey] = $envValue;
                }
            }
        }
    }
    
    return $envCache[$key] ?? $default;
};

$commands = [
    'Cache Clear' => 'cache:clear',
    'Config Clear' => 'config:clear',
    'Route Clear' => 'route:clear',
    'View Clear' => 'view:clear',
    'Optimize' => 'optimize',
    'Optimize Clear' => 'optimize:clear',
    'Storage Link' => 'storage:link',
    'Storage Unlink' => 'storage:unlink',
    'Storage Force Link' => 'storage:link --force',
    'Migrate' => 'migrate',
    'Migrate Fresh' => 'migrate:fresh',
    'Migrate Seed' => 'migrate --seed',
    'Queue Restart' => 'queue:restart',
    'Images Optimize' => 'images:optimize --quality=80',
    'DB Backup' => 'db:backup',
    'DB Auto Backup (Monthly)' => 'db:auto-backup --keep=6',
    'Full Backup (DB + Images)' => 'db:auto-backup --no-prune',
    'DB Restore' => 'db:restore',
    'Import wilayah.sql' => 'db:import-wilayah-sql',
    'Generate Excel Seed Template' => 'db:seed-from-excel --make-template',
    'Seed dari Excel' => 'db:seed-from-excel',
    'Seed dari Excel (Truncate)' => 'db:seed-from-excel --truncate',
    'Mark Absent Hari Ini' => 'attendance:mark-absent',
    'Mark Absent DEBUG (dry-run)' => 'attendance:mark-absent --debug',
    'Mark Absent FORCE (bypass cache+time)' => 'attendance:mark-absent --force',
    'Seed Permissions' => 'db:seed --class=PermissionSeeder',
    'Regions Import (SQL)' => 'regions:import --fresh --local',
    'Regions Import (Provinsi Only)' => 'regions:import --fresh --local --provinces-only',
];

// Preset command groups
$presets = [
    'Quick Deploy' => ['Cache Clear', 'Config Clear', 'Route Clear', 'View Clear', 'Optimize'],
    'Full Clear' => ['Cache Clear', 'Config Clear', 'Route Clear', 'View Clear', 'Optimize Clear'],
    'Fresh Install' => ['Migrate Fresh', 'Storage Force Link', 'Optimize'],
    'Production Deploy' => ['Optimize Clear', 'Cache Clear', 'Config Clear', 'Route Clear', 'View Clear', 'Optimize', 'Queue Restart'],
    'Safe Fresh (Backup → Fresh)' => ['DB Backup', 'Migrate Fresh', 'Storage Force Link', 'Optimize'],
    'Seeder Excel (Template + Import)' => ['Generate Excel Seed Template', 'Seed dari Excel'],
];

// Custom handlers untuk storage operations
$customHandlers = [
    'Storage Unlink' => function() use ($projectPath) {
        $publicStorage = $projectPath . '/public/storage';
        if (is_link($publicStorage)) {
            unlink($publicStorage);
            echo "✓ Symlink removed successfully\n";
        } elseif (is_dir($publicStorage)) {
            echo "⚠ /public/storage is a directory, not a symlink. Manual cleanup needed.\n";
        } else {
            echo "ℹ No symlink exists\n";
        }
    },
    'Storage Force Link' => function() use ($projectPath, $kernel) {
        $publicStorage = $projectPath . '/public/storage';
        // Hapus dulu kalau ada
        if (file_exists($publicStorage)) {
            if (is_link($publicStorage)) {
                unlink($publicStorage);
                echo "✓ Old symlink removed\n";
            } elseif (is_dir($publicStorage)) {
                // Backup directory jika bukan symlink
                $backup = $publicStorage . '_backup_' . date('YmdHis');
                rename($publicStorage, $backup);
                echo "⚠ Directory moved to: $backup\n";
            }
        }
        // Buat symlink baru
        $status = $kernel->call('storage:link --force');
        echo $kernel->output();
        return $status;
    },
    'DB Backup' => function() use ($projectPath, $getEnvValue) {
        $backupDir = $projectPath . '/storage/app/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        // Get database config from .env file
        $dbHost = $getEnvValue('DB_HOST', '127.0.0.1');
        $dbPort = $getEnvValue('DB_PORT', '3306');
        $dbName = $getEnvValue('DB_DATABASE', 'forge');
        $dbUser = $getEnvValue('DB_USERNAME', 'forge');
        $dbPass = $getEnvValue('DB_PASSWORD', '');
        
        $timestamp = date('Y-m-d_H-i-s');
        $backupFile = $backupDir . "/backup_{$dbName}_{$timestamp}.sql";
        
        // Try mysqldump
        $mysqldumpPath = '';
        $possiblePaths = [
            'mysqldump',
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            'C:\\laragon\\bin\\mysql\\mysql-8.0.30-winx64\\bin\\mysqldump.exe',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
        ];
        
        foreach ($possiblePaths as $path) {
            if (is_executable($path) || (PHP_OS_FAMILY === 'Windows' && file_exists($path))) {
                $mysqldumpPath = $path;
                break;
            }
        }
        
        if (empty($mysqldumpPath)) {
            // Fallback: try with shell
            exec('which mysqldump 2>&1', $output, $returnVar);
            if ($returnVar === 0 && !empty($output[0])) {
                $mysqldumpPath = trim($output[0]);
            }
        }
        
        if (empty($mysqldumpPath)) {
            echo "⚠ mysqldump not found. Trying PHP-based backup...\n";
            
            // PHP-based backup using PDO
            try {
                $pdo = new PDO("mysql:host=$dbHost;port=$dbPort;dbname=$dbName", $dbUser, $dbPass);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
                $sql = "-- Database Backup: $dbName\n";
                $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
                $sql .= "-- PHP-based backup (mysqldump not available)\n\n";
                $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
                
                foreach ($tables as $table) {
                    // Get create table
                    $createTable = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
                    $sql .= "DROP TABLE IF EXISTS `$table`;\n";
                    $sql .= $createTable['Create Table'] . ";\n\n";
                    
                    // Get data
                    $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($rows)) {
                        $columns = array_keys($rows[0]);
                        $columnList = '`' . implode('`, `', $columns) . '`';
                        
                        foreach ($rows as $row) {
                            $values = array_map(function($v) use ($pdo) {
                                if ($v === null) return 'NULL';
                                return $pdo->quote($v);
                            }, array_values($row));
                            $sql .= "INSERT INTO `$table` ($columnList) VALUES (" . implode(', ', $values) . ");\n";
                        }
                        $sql .= "\n";
                    }
                }
                
                $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
                file_put_contents($backupFile, $sql);
                
                $size = round(filesize($backupFile) / 1024, 2);
                echo "✓ Backup created: " . basename($backupFile) . " ({$size} KB)\n";
                echo "📁 Location: $backupFile\n";
                echo "📊 Tables backed up: " . count($tables) . "\n";
                return 0;
            } catch (Exception $e) {
                echo "✗ PHP backup failed: " . $e->getMessage() . "\n";
                return 1;
            }
        }
        
        // Use mysqldump
        $passArg = $dbPass ? "-p\"$dbPass\"" : '';
        $cmd = "\"$mysqldumpPath\" -h $dbHost -P $dbPort -u $dbUser $passArg $dbName > \"$backupFile\" 2>&1";
        
        exec($cmd, $output, $returnVar);
        
        if ($returnVar === 0 && file_exists($backupFile) && filesize($backupFile) > 0) {
            $size = round(filesize($backupFile) / 1024, 2);
            echo "✓ Backup created: " . basename($backupFile) . " ({$size} KB)\n";
            echo "📁 Location: $backupFile\n";
            return 0;
        } else {
            echo "✗ Backup failed\n";
            if (!empty($output)) {
                echo "Output: " . implode("\n", $output) . "\n";
            }
            return 1;
        }
    },
    'DB Restore' => function() use ($projectPath, $getEnvValue) {
        $backupDir = $projectPath . '/storage/app/backups';
        
        // List available backups
        if (!is_dir($backupDir)) {
            echo "✗ No backup directory found\n";
            return 1;
        }
        
        $backups = glob($backupDir . '/*.sql');
        if (empty($backups)) {
            echo "✗ No backup files found in $backupDir\n";
            return 1;
        }
        
        // Sort by date (newest first)
        usort($backups, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        // Check if specific backup requested via GET/POST
        $selectedBackup = $_REQUEST['restore_file'] ?? null;
        
        if (!$selectedBackup) {
            echo "📋 Available backups:\n\n";
            foreach ($backups as $i => $backup) {
                $filename = basename($backup);
                $size = round(filesize($backup) / 1024, 2);
                $date = date('Y-m-d H:i:s', filemtime($backup));
                echo "  [$i] $filename ({$size} KB) - $date\n";
            }
            echo "\n⚠ To restore, add ?restore_file=FILENAME to URL or select below\n";
            echo "\n<form method='post' style='margin-top:10px'>";
            echo "<select name='restore_file' style='padding:8px;border-radius:4px;border:1px solid #ccc'>";
            foreach ($backups as $backup) {
                $filename = basename($backup);
                echo "<option value='$filename'>$filename</option>";
            }
            echo "</select>";
            echo "<input type='hidden' name='commands[]' value='DB Restore'>";
            echo "<button type='submit' style='margin-left:10px;padding:8px 16px;background:#e74c3c;color:#fff;border:none;border-radius:4px;cursor:pointer'>🔄 Restore Selected</button>";
            echo "</form>\n";
            return 0;
        }
        
        $backupFile = $backupDir . '/' . basename($selectedBackup);
        if (!file_exists($backupFile)) {
            echo "✗ Backup file not found: $selectedBackup\n";
            return 1;
        }

        // If zip, extract database.sql first
        $sqlFileToRestore = $backupFile;
        $tempExtracted = null;
        if (str_ends_with($backupFile, '.zip') && class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($backupFile) === true) {
                $idx = $zip->locateName('database.sql');
                if ($idx !== false) {
                    $tempExtracted = $backupDir . '/temp_restore_' . time() . '.sql';
                    file_put_contents($tempExtracted, $zip->getFromIndex($idx));
                    $sqlFileToRestore = $tempExtracted;
                    echo "📦 Extracted database.sql from zip\n";
                } else {
                    echo "✗ No database.sql found inside zip\n";
                    $zip->close();
                    return 1;
                }
                $zip->close();
            } else {
                echo "✗ Cannot open zip file\n";
                return 1;
            }
        }
        
        // Get database config from .env file
        $dbHost = $getEnvValue('DB_HOST', '127.0.0.1');
        $dbPort = $getEnvValue('DB_PORT', '3306');
        $dbName = $getEnvValue('DB_DATABASE', 'forge');
        $dbUser = $getEnvValue('DB_USERNAME', 'forge');
        $dbPass = $getEnvValue('DB_PASSWORD', '');
        
        echo "🔄 Restoring from: " . basename($backupFile) . "\n";
        
        // Try mysql command
        $mysqlPath = '';
        $possiblePaths = [
            'mysql',
            'C:\\xampp\\mysql\\bin\\mysql.exe',
            'C:\\laragon\\bin\\mysql\\mysql-8.0.30-winx64\\bin\\mysql.exe',
            '/usr/bin/mysql',
            '/usr/local/bin/mysql',
        ];
        
        foreach ($possiblePaths as $path) {
            if (is_executable($path) || (PHP_OS_FAMILY === 'Windows' && file_exists($path))) {
                $mysqlPath = $path;
                break;
            }
        }
        
        if (empty($mysqlPath)) {
            // Fallback: PHP-based restore
            echo "⚠ mysql command not found. Using PHP-based restore...\n";
            
            try {
                $pdo = new PDO("mysql:host=$dbHost;port=$dbPort;dbname=$dbName", $dbUser, $dbPass);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                $sql = file_get_contents($sqlFileToRestore);
                
                // Split by statements (simple approach)
                $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
                
                // Execute multi-query
                $statements = array_filter(array_map('trim', explode(";\n", $sql)));
                $executed = 0;
                
                foreach ($statements as $stmt) {
                    if (!empty($stmt) && !str_starts_with($stmt, '--')) {
                        try {
                            $pdo->exec($stmt);
                            $executed++;
                        } catch (Exception $e) {
                            // Skip errors for comments/empty
                        }
                    }
                }
                
                $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
                
                echo "✓ Restore completed! ($executed statements executed)\n";
                if ($tempExtracted && file_exists($tempExtracted)) unlink($tempExtracted);
                return 0;
            } catch (Exception $e) {
                if ($tempExtracted && file_exists($tempExtracted)) unlink($tempExtracted);
                echo "✗ PHP restore failed: " . $e->getMessage() . "\n";
                return 1;
            }
        }
        
        // Use mysql command
        $passArg = $dbPass ? "-p\"$dbPass\"" : '';
        $cmd = "\"$mysqlPath\" -h $dbHost -P $dbPort -u $dbUser $passArg $dbName < \"$sqlFileToRestore\" 2>&1";
        
        exec($cmd, $output, $returnVar);
        if ($tempExtracted && file_exists($tempExtracted)) unlink($tempExtracted);
        
        if ($returnVar === 0) {
            echo "✓ Restore completed successfully!\n";
            return 0;
        } else {
            echo "✗ Restore failed\n";
            if (!empty($output)) {
                echo "Output: " . implode("\n", $output) . "\n";
            }
            return 1;
        }
    },
    'Import wilayah.sql' => function() use ($projectPath, $getEnvValue) {
        $sqlFile = $projectPath . '/database/seeders/wilayah.sql';
        if (!file_exists($sqlFile)) {
            echo "✗ File not found: $sqlFile\n";
            return 1;
        }

        // Get database config from .env file
        $dbHost = $getEnvValue('DB_HOST', '127.0.0.1');
        $dbPort = $getEnvValue('DB_PORT', '3306');
        $dbName = $getEnvValue('DB_DATABASE', 'forge');
        $dbUser = $getEnvValue('DB_USERNAME', 'forge');
        $dbPass = $getEnvValue('DB_PASSWORD', '');

        echo "🔄 Importing wilayah.sql...\n";

        // Try mysql command
        $mysqlPath = '';
        $possiblePaths = [
            'mysql',
            'C:\\xampp\\mysql\\bin\\mysql.exe',
            'C:\\laragon\\bin\\mysql\\mysql-8.0.30-winx64\\bin\\mysql.exe',
            '/usr/bin/mysql',
            '/usr/local/bin/mysql',
        ];

        foreach ($possiblePaths as $path) {
            if (is_executable($path) || (PHP_OS_FAMILY === 'Windows' && file_exists($path))) {
                $mysqlPath = $path;
                break;
            }
        }

        if (!empty($mysqlPath)) {
            $passArg = $dbPass ? "-p\"$dbPass\"" : '';
            $cmd = "\"$mysqlPath\" -h $dbHost -P $dbPort -u $dbUser $passArg $dbName < \"$sqlFile\" 2>&1";
            exec($cmd, $output, $returnVar);

            if ($returnVar === 0) {
                echo "✓ Import completed successfully!\n";
                return 0;
            }

            echo "✗ Import failed\n";
            if (!empty($output)) {
                echo "Output: " . implode("\n", $output) . "\n";
            }
            return 1;
        }

        // Fallback: PHP-based import
        try {
            $pdo = new PDO("mysql:host=$dbHost;port=$dbPort;dbname=$dbName", $dbUser, $dbPass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $sql = file_get_contents($sqlFile);
            $pdo->exec("SET FOREIGN_KEY_CHECKS=0");

            $statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));
            $executed = 0;

            foreach ($statements as $stmt) {
                if (!empty($stmt) && !str_starts_with($stmt, '--')) {
                    try {
                        $pdo->exec($stmt);
                        $executed++;
                    } catch (Exception $e) {
                        // Skip problematic statements
                    }
                }
            }

            $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
            echo "✓ Import completed! ($executed statements executed)\n";
            return 0;
        } catch (Exception $e) {
            echo "✗ PHP import failed: " . $e->getMessage() . "\n";
            return 1;
        }
    },
    'Migrate Fresh' => function() use ($projectPath, $getEnvValue, $kernel) {
        echo "🧹 Cleaning database (Manual Wipe)...\n";
        
        $dbHost = $getEnvValue('DB_HOST', '127.0.0.1');
        $dbPort = $getEnvValue('DB_PORT', '3306');
        $dbName = $getEnvValue('DB_DATABASE', 'forge');
        $dbUser = $getEnvValue('DB_USERNAME', 'forge');
        $dbPass = $getEnvValue('DB_PASSWORD', '');

        try {
            // 1. Manual Wipe using PDO
            $pdo = new PDO("mysql:host=$dbHost;port=$dbPort;dbname=$dbName", $dbUser, $dbPass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
            
            // Drop Views first
            $views = $pdo->query("SHOW FULL TABLES WHERE Table_Type = 'VIEW'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($views as $view) {
                $pdo->exec("DROP VIEW IF EXISTS `$view`");
                echo "✓ Dropped view: $view\n";
            }

            // Drop all Tables
            $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            if (empty($tables)) {
                echo "ℹ Database is already empty.\n";
            } else {
                foreach ($tables as $table) {
                    $pdo->exec("DROP TABLE IF EXISTS `$table`");
                    echo "✓ Dropped table: $table\n";
                }
            }
            
            $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
            $pdo = null; // Close PDO connection
            
            echo "✨ Database wiped successfully.\n";
            echo "Running migrations...\n";
            
            // 2. Run Migrate directly (Laravel will use .env credentials)
            $status = $kernel->call('migrate', ['--force' => true]);
            echo $kernel->output();
            
            return $status;
        } catch (PDOException $e) {
            echo "✗ Database connection failed: " . $e->getMessage() . "\n";
            return 1;
        } catch (Exception $e) {
            echo "✗ Error: " . $e->getMessage() . "\n";
            echo "⚠ Falling back to standard migrate:fresh...\n";
            $status = $kernel->call('migrate:fresh', ['--force' => true]);
            echo $kernel->output();
            return $status;
        }
    }
];

// ─────────────────────────────────────────────────────────────────────────────
// COLLECT RESULTS BEFORE RENDERING HTML
// ─────────────────────────────────────────────────────────────────────────────

// Collect any execution results so we can output them inside the rendered page
$executionResults = null;
$markAbsentResult = null;
$excelImportResult = null;

// Handle mark absent manual
if (isset($_POST['do_mark_absent'])) {
    $absentDate = $_POST['absent_date'] ?? date('Y-m-d');
    $dateObj    = DateTime::createFromFormat('Y-m-d', $absentDate);
    ob_start();
    if (!$dateObj || $dateObj->format('Y-m-d') !== $absentDate) {
        echo "✗ Format tanggal tidak valid: " . htmlspecialchars($absentDate) . "\n";
    } else {
        echo "▶ attendance:mark-absent --date=$absentDate\n\n";
        $s = $kernel->call('attendance:mark-absent', ['--date' => $absentDate]);
        echo $kernel->output();
        echo $s === 0 ? "\n✓ Selesai!" : "\n✗ Error (exit code: $s)";
    }
    $markAbsentResult = ob_get_clean();
}

// Handle Excel file upload
if (isset($_FILES['seed_excel']) && $_FILES['seed_excel']['error'] !== UPLOAD_ERR_NO_FILE) {
    ob_start();
    $uploadError = $_FILES['seed_excel']['error'];
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE => 'File melebihi upload_max_filesize', UPLOAD_ERR_FORM_SIZE => 'File melebihi MAX_FILE_SIZE',
        UPLOAD_ERR_PARTIAL => 'Upload tidak lengkap', UPLOAD_ERR_NO_TMP_DIR => 'Tidak ada folder temporary',
        UPLOAD_ERR_CANT_WRITE => 'Gagal menulis ke disk', UPLOAD_ERR_EXTENSION => 'Upload ditolak ekstensi PHP',
    ];
    if ($uploadError !== UPLOAD_ERR_OK) {
        echo "✗ " . ($uploadErrors[$uploadError] ?? "Error code $uploadError") . "\n";
    } else {
        $uf  = $_FILES['seed_excel'];
        $ext = strtolower(pathinfo($uf['name'], PATHINFO_EXTENSION));
        if ($ext !== 'xlsx' && $ext !== 'xls') {
            echo "✗ Hanya file .xlsx yang diperbolehkan\n";
        } else {
            $destDir = $projectPath . '/storage/app/templates';
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);
            $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($uf['name']));
            $destFile = $destDir . '/' . $safeName;
            $relPath  = 'storage/app/templates/' . $safeName;
            if (move_uploaded_file($uf['tmp_name'], $destFile)) {
                $size = round(filesize($destFile) / 1024, 2);
                echo "✓ Uploaded: $safeName ({$size} KB)\n\n";
                $args = ['--path' => $relPath];
                if (isset($_POST['excel_truncate'])) { $args['--truncate'] = true; echo "⚠ Truncate mode aktif\n\n"; }
                $s = $kernel->call('db:seed-from-excel', $args);
                echo $kernel->output();
                echo $s === 0 ? "\n✓ Import selesai!" : "\n✗ Error (exit code: $s)";
            } else {
                echo "✗ Gagal menyimpan file\n";
            }
        }
    }
    $excelImportResult = ob_get_clean();
}

// Handle command execution
$selectedCommands = isset($_POST['commands']) && is_array($_POST['commands']) ? $_POST['commands'] : [];
$customCommand    = isset($_POST['custom_command']) ? trim($_POST['custom_command']) : '';

if (!empty($selectedCommands) || !empty($customCommand)) {
    if (!empty($customCommand)) {
        $cleanCommand = htmlspecialchars($customCommand);
        $label        = "Custom: $cleanCommand";
        $commands[$label] = $customCommand;
        $selectedCommands[] = $label;
    }

    $totalCommands = count($selectedCommands);
    $successCount  = 0;
    $failCount     = 0;
    $resultLines   = [];

    foreach ($selectedCommands as $index => $label) {
        if (!isset($commands[$label])) {
            $resultLines[] = ['label' => $label, 'cmd' => '—', 'output' => "✗ Invalid command: $label", 'ok' => false, 'ms' => 0];
            $failCount++;
            continue;
        }
        $num = $index + 1;
        ob_start();
        try {
            $t0 = microtime(true);
            if (isset($customHandlers[$label])) {
                $status = $customHandlers[$label]();
            } else {
                $status = $kernel->call($commands[$label]);
                echo $kernel->output();
            }
            $ms = round((microtime(true) - $t0) * 1000, 2);
            $out = ob_get_clean();
            $ok  = ($status === 0);
            $resultLines[] = ['label' => $label, 'cmd' => $commands[$label], 'output' => $out, 'ok' => $ok, 'ms' => $ms, 'status' => $status];
            $ok ? $successCount++ : $successCount++;
        } catch (Exception $e) {
            $ms = round((microtime(true) - $t0) * 1000, 2);
            ob_get_clean();
            $resultLines[] = ['label' => $label, 'cmd' => $commands[$label], 'output' => "✗ EXCEPTION: " . $e->getMessage(), 'ok' => false, 'ms' => $ms];
            $failCount++;
        }
    }
    $executionResults = ['lines' => $resultLines, 'total' => $totalCommands, 'success' => $successCount, 'fail' => $failCount];
}

// ─────────────────────────────────────────────────────────────────────────────
// STORAGE STATUS
// ─────────────────────────────────────────────────────────────────────────────
$publicStorage   = $projectPath . '/public/storage';
$storageStatus   = is_link($publicStorage) ? ['ok', 'Symlink aktif → ' . readlink($publicStorage)]
                 : (is_dir($publicStorage) ? ['warn', '/public/storage adalah direktori (bukan symlink)']
                 : ['error', 'Storage link tidak ditemukan']);

// ─────────────────────────────────────────────────────────────────────────────
// BACKUP LIST
// ─────────────────────────────────────────────────────────────────────────────
$backupDir   = $projectPath . '/storage/app/backups';
$sqlBackups  = is_dir($backupDir) ? (glob($backupDir . '/*.sql') ?: []) : [];
$zipBackups  = is_dir($backupDir) ? (glob($backupDir . '/*.zip') ?: []) : [];
$backupFiles = array_merge($sqlBackups, $zipBackups);
if ($backupFiles) {
    usort($backupFiles, fn($a, $b) => filemtime($b) - filemtime($a));
}

// Render HTML
?><?php ob_start(); ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Deploy Console — <?= htmlspecialchars(basename($projectPath)) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=Sora:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:       #0f1117;
  --surface:  #181c27;
  --panel:    #1e2332;
  --border:   #2b3148;
  --accent:   #f59e0b;
  --accent2:  #fb923c;
  --green:    #22c55e;
  --red:      #ef4444;
  --blue:     #60a5fa;
  --purple:   #a78bfa;
  --text:     #e2e8f0;
  --muted:    #64748b;
  --subtle:   #334155;
  --mono:     'IBM Plex Mono', monospace;
  --sans:     'Sora', sans-serif;
  --radius:   10px;
  --radius-sm: 6px;
}

html { font-size: 14px; scroll-behavior: smooth; }

/* ── Light Theme ─────────────────────────────────────── */
html.light {
  --bg:       #f5f7fa;
  --surface:  #ffffff;
  --panel:    #f8fafc;
  --border:   #e2e8f0;
  --accent:   #d97706;
  --accent2:  #ea580c;
  --green:    #16a34a;
  --red:      #dc2626;
  --blue:     #3b82f6;
  --purple:   #7c3aed;
  --text:     #1e293b;
  --muted:    #64748b;
  --subtle:   #cbd5e1;
}
html.light .terminal { background: #f1f5f9; }
html.light .terminal-body { color: #475569; }
html.light .terminal-body .t-ok { color: #16a34a; }
html.light .terminal-body .t-err { color: #dc2626; }
html.light .terminal-body .t-warn { color: #d97706; }
html.light .terminal-body .t-info { color: #2563eb; }
html.light .terminal-body .t-cmd { color: #0f172a; }
html.light .terminal-body .t-sep { color: #cbd5e1; }
html.light .info-val .badge-env { background: rgba(59,130,246,.1); color: #2563eb; }
html.light .tag-danger { background: rgba(239,68,68,.1); color: #dc2626; }
html.light .tag-warn { background: rgba(245,158,11,.1); color: #d97706; }
html.light .tag-db { background: rgba(124,58,237,.1); color: #7c3aed; }
html.light .tag-excel { background: rgba(22,163,74,.1); color: #16a34a; }
html.light .tag-absent { background: rgba(234,88,12,.1); color: #ea580c; }
html.light .backup-latest { background: rgba(22,163,74,.1); color: #16a34a; }
html.light .pill-ok { background: rgba(22,163,74,.08); color: #16a34a; }
html.light .pill-warn { background: rgba(213,119,6,.08); color: #d97706; }
html.light .pill-error { background: rgba(220,38,38,.08); color: #dc2626; }
html.light .preset-badge { background: rgba(0,0,0,.06); }

body {
  background: var(--bg);
  color: var(--text);
  font-family: var(--sans);
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

/* ── Header ─────────────────────────────────────────── */
.site-header {
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  padding: 0 32px;
  height: 56px;
  display: flex;
  align-items: center;
  gap: 16px;
  position: sticky;
  top: 0;
  z-index: 100;
}
.site-header .logo {
  display: flex;
  align-items: center;
  gap: 10px;
  font-weight: 700;
  font-size: 15px;
  letter-spacing: -0.02em;
}
.logo-icon {
  width: 28px; height: 28px;
  background: linear-gradient(135deg, var(--accent), var(--accent2));
  border-radius: 7px;
  display: grid;
  place-items: center;
  font-size: 14px;
}
.header-path {
  font-family: var(--mono);
  font-size: 11px;
  color: var(--muted);
  background: var(--panel);
  padding: 3px 9px;
  border-radius: 4px;
  border: 1px solid var(--border);
}
.header-right {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 10px;
}
.time-badge {
  font-family: var(--mono);
  font-size: 11px;
  color: var(--accent);
  background: rgba(245,158,11,.1);
  padding: 4px 10px;
  border-radius: 20px;
  border: 1px solid rgba(245,158,11,.25);
}
.theme-toggle {
  background: var(--panel);
  border: 1px solid var(--border);
  border-radius: 50%;
  width: 32px; height: 32px;
  display: grid; place-items: center;
  cursor: pointer;
  font-size: 15px;
  transition: all .15s;
  line-height: 1;
}
.theme-toggle:hover { border-color: var(--accent); background: rgba(245,158,11,.08); }

/* ── Layout ─────────────────────────────────────────── */
.layout {
  display: grid;
  grid-template-columns: 220px 1fr;
  flex: 1;
  min-height: calc(100vh - 56px);
}

/* ── Sidebar ─────────────────────────────────────────── */
.sidebar {
  background: var(--surface);
  border-right: 1px solid var(--border);
  padding: 24px 0;
  position: sticky;
  top: 56px;
  height: calc(100vh - 56px);
  overflow-y: auto;
}
.nav-section {
  padding: 0 12px 20px;
}
.nav-label {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: var(--muted);
  padding: 0 8px;
  margin-bottom: 6px;
}
.nav-item {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 8px 10px;
  border-radius: var(--radius-sm);
  font-size: 13px;
  font-weight: 500;
  color: var(--muted);
  text-decoration: none;
  transition: background .15s, color .15s;
  cursor: pointer;
  border: none;
  background: none;
  width: 100%;
  text-align: left;
}
.nav-item:hover, .nav-item.active {
  background: rgba(245,158,11,.08);
  color: var(--accent);
}
.nav-item .nav-dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: currentColor; opacity: .5;
  flex-shrink: 0;
}
.nav-divider { height: 1px; background: var(--border); margin: 8px 12px; }

/* ── Main ───────────────────────────────────────────── */
.main {
  padding: 32px;
  display: flex;
  flex-direction: column;
  gap: 32px;
  min-width: 0;
}

/* ── Section ────────────────────────────────────────── */
.section {
  scroll-margin-top: 72px;
}
.section-head {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 18px;
}
.section-icon {
  width: 34px; height: 34px;
  border-radius: 9px;
  display: grid; place-items: center;
  font-size: 16px;
  flex-shrink: 0;
}
.icon-amber  { background: rgba(245,158,11,.12); }
.icon-blue   { background: rgba(96,165,250,.12); }
.icon-green  { background: rgba(34,197,94,.12); }
.icon-purple { background: rgba(167,139,250,.12); }
.icon-red    { background: rgba(239,68,68,.12); }
.icon-orange { background: rgba(251,146,60,.12); }

.section-title {
  font-size: 16px;
  font-weight: 700;
  letter-spacing: -.02em;
}
.section-sub {
  font-size: 12px;
  color: var(--muted);
  margin-top: 2px;
}

/* ── Cards ──────────────────────────────────────────── */
.card {
  background: var(--panel);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 20px;
}

/* ── Preset grid ────────────────────────────────────── */
.preset-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}
.preset-btn {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 9px 16px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--surface);
  color: var(--text);
  font-family: var(--sans);
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  transition: all .15s;
}
.preset-btn:hover {
  border-color: var(--accent);
  color: var(--accent);
  background: rgba(245,158,11,.06);
}
.preset-badge {
  font-size: 10px;
  background: rgba(255,255,255,.08);
  padding: 2px 7px;
  border-radius: 20px;
  font-weight: 600;
}

/* ── Command grid ────────────────────────────────────── */
.cmd-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
  gap: 8px;
}
.cmd-tile {
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--surface);
  cursor: pointer;
  transition: border-color .15s, background .15s;
}
.cmd-tile:hover { border-color: var(--accent); background: rgba(245,158,11,.04); }
.cmd-tile.selected { border-color: var(--accent); background: rgba(245,158,11,.08); }
.cmd-tile label {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 11px 13px;
  cursor: pointer;
  font-size: 12.5px;
  font-weight: 500;
  line-height: 1.35;
}
.cmd-tile input[type=checkbox] {
  margin-top: 1px;
  width: 15px; height: 15px;
  accent-color: var(--accent);
  flex-shrink: 0;
  cursor: pointer;
}
.cmd-tile.is-danger { border-color: rgba(239,68,68,.3); }
.cmd-tile.is-danger:hover { border-color: var(--red); background: rgba(239,68,68,.05); }
.cmd-tile.is-warn { border-color: rgba(245,158,11,.3); }
.cmd-tile.is-db { border-color: rgba(167,139,250,.3); }
.cmd-tile.is-excel { border-color: rgba(34,197,94,.3); }
.cmd-tile.is-absent { border-color: rgba(251,146,60,.3); }

.tag {
  font-size: 9.5px;
  font-weight: 700;
  letter-spacing: .05em;
  text-transform: uppercase;
  padding: 2px 5px;
  border-radius: 3px;
  display: inline-block;
  margin-top: 3px;
}
.tag-danger { background: rgba(239,68,68,.15); color: #fca5a5; }
.tag-warn   { background: rgba(245,158,11,.15); color: #fcd34d; }
.tag-db     { background: rgba(167,139,250,.15); color: #c4b5fd; }
.tag-excel  { background: rgba(34,197,94,.15); color: #86efac; }
.tag-absent { background: rgba(251,146,60,.15); color: #fdba74; }

/* ── Custom command ──────────────────────────────────── */
.custom-input-wrap {
  display: flex;
  gap: 10px;
  align-items: stretch;
  margin-top: 14px;
}
.custom-input-prefix {
  font-family: var(--mono);
  font-size: 12px;
  color: var(--muted);
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 0 14px;
  display: flex;
  align-items: center;
  flex-shrink: 0;
}
.custom-input {
  flex: 1;
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  color: var(--text);
  font-family: var(--mono);
  font-size: 13px;
  padding: 10px 14px;
  outline: none;
  transition: border-color .15s;
}
.custom-input:focus { border-color: var(--accent); }
.custom-input::placeholder { color: var(--muted); }

/* ── Action bar ─────────────────────────────────────── */
.action-bar {
  display: flex;
  gap: 10px;
  align-items: center;
  flex-wrap: wrap;
  margin-top: 18px;
  padding-top: 18px;
  border-top: 1px solid var(--border);
}
.btn {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 9px 18px;
  border-radius: var(--radius-sm);
  font-family: var(--sans);
  font-size: 13px;
  font-weight: 600;
  border: 1px solid transparent;
  cursor: pointer;
  transition: all .15s;
  text-decoration: none;
}
.btn-run {
  background: var(--accent);
  color: #0f1117;
  border-color: var(--accent);
}
.btn-run:hover { background: #fbbf24; }
.btn-ghost {
  background: transparent;
  color: var(--muted);
  border-color: var(--border);
}
.btn-ghost:hover { color: var(--text); border-color: var(--subtle); }
.btn-danger-outline {
  background: transparent;
  color: #fca5a5;
  border-color: rgba(239,68,68,.35);
}
.btn-danger-outline:hover { background: rgba(239,68,68,.08); }

/* ── Terminal output ─────────────────────────────────── */
.terminal {
  background: #0a0c12;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  overflow: hidden;
  margin-top: 24px;
}
.terminal-bar {
  background: var(--panel);
  padding: 10px 16px;
  display: flex;
  align-items: center;
  gap: 10px;
  border-bottom: 1px solid var(--border);
  font-size: 12px;
  font-weight: 600;
  color: var(--muted);
}
.terminal-dots { display: flex; gap: 6px; }
.dot { width: 11px; height: 11px; border-radius: 50%; }
.dot-red { background: #ef4444; }
.dot-yellow { background: #f59e0b; }
.dot-green { background: #22c55e; }
.terminal-title { flex: 1; text-align: center; font-family: var(--mono); font-size: 11px; }
.terminal-body {
  padding: 18px 20px;
  font-family: var(--mono);
  font-size: 12.5px;
  line-height: 1.7;
  max-height: 500px;
  overflow-y: auto;
  white-space: pre-wrap;
  word-break: break-all;
  color: #94a3b8;
}
.terminal-body .t-ok   { color: #4ade80; }
.terminal-body .t-err  { color: #f87171; }
.terminal-body .t-warn { color: #fbbf24; }
.terminal-body .t-info { color: #60a5fa; }
.terminal-body .t-cmd  { color: #e2e8f0; font-weight: 700; }
.terminal-body .t-sep  { color: #1e3a5f; }
.terminal-body .t-sum  { color: var(--text); font-weight: 700; }

/* ── Result tasks ────────────────────────────────────── */
.result-group { margin-bottom: 20px; }
.result-header {
  display: flex;
  align-items: center;
  gap: 10px;
  padding-bottom: 8px;
  border-bottom: 1px solid var(--border);
  margin-bottom: 10px;
}
.result-num {
  font-family: var(--mono);
  font-size: 11px;
  color: var(--muted);
  background: var(--bg);
  padding: 2px 8px;
  border-radius: 3px;
}
.result-ok   { color: var(--green); }
.result-fail { color: var(--red); }
.result-label { font-weight: 600; font-size: 13px; }
.result-ms {
  margin-left: auto;
  font-family: var(--mono);
  font-size: 11px;
  color: var(--muted);
}

/* ── Status pill ─────────────────────────────────────── */
.pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 12px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
}
.pill-ok     { background: rgba(34,197,94,.12); color: #4ade80; }
.pill-warn   { background: rgba(245,158,11,.12); color: #fbbf24; }
.pill-error  { background: rgba(239,68,68,.12); color: #f87171; }

/* ── Storage status ──────────────────────────────────── */
.storage-pill {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  border-radius: var(--radius-sm);
  font-family: var(--mono);
  font-size: 12px;
}
.spl-ok    { background: rgba(34,197,94,.08); border: 1px solid rgba(34,197,94,.25); color: #4ade80; }
.spl-warn  { background: rgba(245,158,11,.08); border: 1px solid rgba(245,158,11,.25); color: #fbbf24; }
.spl-error { background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.25); color: #f87171; }

/* ── Info table ──────────────────────────────────────── */
.info-grid {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 10px 20px;
  font-size: 12.5px;
}
.info-key { color: var(--muted); font-weight: 500; white-space: nowrap; }
.info-val { font-family: var(--mono); color: var(--text); }
.info-val .badge-env {
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 3px;
  background: rgba(96,165,250,.12);
  color: #93c5fd;
}

/* ── Backup list ─────────────────────────────────────── */
.backup-list { display: flex; flex-direction: column; gap: 6px; }
.backup-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 14px;
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  font-size: 12px;
}
.backup-item:first-child { border-color: rgba(34,197,94,.3); }
.backup-name { font-family: var(--mono); flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.backup-size { color: var(--muted); white-space: nowrap; }
.backup-date { color: var(--muted); white-space: nowrap; font-size: 11px; }
.backup-latest { font-size: 10px; background: rgba(34,197,94,.12); color: #4ade80; padding: 2px 8px; border-radius: 10px; font-weight: 700; }
.restore-select {
  width: 100%;
  padding: 9px 12px;
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  color: var(--text);
  font-family: var(--mono);
  font-size: 12px;
  margin-bottom: 10px;
  outline: none;
}

/* ── Mark absent form ─────────────────────────────────── */
.mark-form {
  background: rgba(251,146,60,.04);
  border: 1px solid rgba(251,146,60,.2);
  border-radius: var(--radius);
  padding: 20px;
}
.form-row { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-label { font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }
.date-input {
  padding: 9px 14px;
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  color: var(--text);
  font-family: var(--mono);
  font-size: 13px;
  outline: none;
  transition: border-color .15s;
}
.date-input:focus { border-color: var(--accent2); }

/* ── Excel upload ────────────────────────────────────── */
.upload-zone {
  background: rgba(34,197,94,.03);
  border: 1px dashed rgba(34,197,94,.3);
  border-radius: var(--radius);
  padding: 24px;
  text-align: center;
  transition: all .15s;
}
.upload-zone:hover { border-color: rgba(34,197,94,.6); background: rgba(34,197,94,.06); }
.upload-zone input[type=file] {
  width: 100%;
  cursor: pointer;
  font-family: var(--mono);
  font-size: 12px;
  color: var(--muted);
}
.upload-zone p { font-size: 12px; color: var(--muted); margin-top: 8px; }
.checkbox-row { display: flex; align-items: center; gap: 8px; margin-top: 12px; font-size: 12.5px; color: var(--muted); }
.checkbox-row input { accent-color: var(--green); width: 14px; height: 14px; }

/* ── Summary bar ─────────────────────────────────────── */
.summary-bar {
  display: flex;
  gap: 10px;
  padding: 12px 16px;
  background: var(--panel);
  border-radius: var(--radius-sm);
  align-items: center;
  margin-top: 12px;
}
.sum-num { font-size: 20px; font-weight: 700; }
.sum-label { font-size: 11px; color: var(--muted); margin-top: 1px; }
.sum-divider { width: 1px; height: 32px; background: var(--border); }

/* ── Scrollbar ───────────────────────────────────────── */
::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }

/* ── Responsive ──────────────────────────────────────── */
@media (max-width: 860px) {
  .layout { grid-template-columns: 1fr; }
  .sidebar { position: static; height: auto; border-right: none; border-bottom: 1px solid var(--border); padding: 12px 0; display: flex; flex-wrap: wrap; gap: 0; }
  .nav-section { padding-bottom: 10px; }
  .main { padding: 20px 16px; }
  .cmd-grid { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
}
</style>
</head>
<body>

<!-- ── Header ─────────────────────────────── -->
<header class="site-header">
  <div class="logo">
    <div class="logo-icon">⚡</div>
    Deploy Console
  </div>
  <div class="header-path"><?= htmlspecialchars($projectPath) ?></div>
  <div class="header-right">
    <span class="time-badge" id="clock"><?= date('H:i:s') ?> WIB</span>
    <button class="theme-toggle" onclick="toggleTheme()" title="Toggle light/dark theme"><span id="themeIcon">🌙</span></button>
  </div>
</header>

<div class="layout">

<!-- ── Sidebar ───────────────────────────── -->
<nav class="sidebar">
  <div class="nav-section">
    <p class="nav-label">Navigation</p>
    <a class="nav-item" href="#presets"><span class="nav-dot"></span>Quick Presets</a>
    <a class="nav-item" href="#commands"><span class="nav-dot"></span>All Commands</a>
    <?php if ($executionResults): ?>
    <a class="nav-item active" href="#results"><span class="nav-dot"></span>Hasil Eksekusi</a>
    <?php endif; ?>
  </div>
  <div class="nav-divider"></div>
  <div class="nav-section">
    <p class="nav-label">Tools</p>
    <a class="nav-item" href="#storage"><span class="nav-dot"></span>Storage Status</a>
    <a class="nav-item" href="#mark-absent"><span class="nav-dot"></span>Mark Absent</a>
    <a class="nav-item" href="#backup"><span class="nav-dot"></span>Database Backup</a>
    <a class="nav-item" href="#cron"><span class="nav-dot"></span>Cron Schedule</a>
    <a class="nav-item" href="#excel"><span class="nav-dot"></span>Import Excel</a>
  </div>
  <div class="nav-divider"></div>
  <div class="nav-section">
    <p class="nav-label">Info</p>
    <a class="nav-item" href="#sysinfo"><span class="nav-dot"></span>System Info</a>
  </div>
</nav>

<!-- ── Main ──────────────────────────────── -->
<main class="main">

  <?php if ($executionResults): ?>
  <!-- ── RESULTS ──────────────────────────── -->
  <section class="section" id="results">
    <div class="section-head">
      <div class="section-icon icon-amber">⚡</div>
      <div>
        <div class="section-title">Hasil Eksekusi</div>
        <div class="section-sub"><?= $executionResults['total'] ?> command dijalankan</div>
      </div>
    </div>

    <div class="summary-bar">
      <div>
        <div class="sum-num" style="color:var(--green)"><?= $executionResults['success'] ?></div>
        <div class="sum-label">Success</div>
      </div>
      <div class="sum-divider"></div>
      <div>
        <div class="sum-num" style="color:var(--red)"><?= $executionResults['fail'] ?></div>
        <div class="sum-label">Failed</div>
      </div>
      <div class="sum-divider"></div>
      <div>
        <div class="sum-num" style="color:var(--text)"><?= $executionResults['total'] ?></div>
        <div class="sum-label">Total</div>
      </div>
    </div>

    <div class="terminal" style="margin-top:20px">
      <div class="terminal-bar">
        <div class="terminal-dots"><span class="dot dot-red"></span><span class="dot dot-yellow"></span><span class="dot dot-green"></span></div>
        <div class="terminal-title">output — <?= date('Y-m-d H:i:s') ?></div>
      </div>
      <div class="terminal-body"><?php
        foreach ($executionResults['lines'] as $i => $r):
          $n = $i + 1;
          $tot = $executionResults['total'];
          echo '<span class="t-sep">────────────────────────────────────────</span>' . "\n";
          echo '<span class="t-info">[' . $n . '/' . $tot . ']</span> <span class="t-cmd">' . htmlspecialchars($r['label']) . '</span>' . "\n";
          echo '<span class="t-sep">cmd: </span><span style="color:#475569">' . htmlspecialchars($r['cmd']) . '</span>' . "\n\n";
          $colored = htmlspecialchars($r['output']);
          $colored = preg_replace('/✓[^\n]*/m', '<span class="t-ok">$0</span>', $colored);
          $colored = preg_replace('/✗[^\n]*/m', '<span class="t-err">$0</span>', $colored);
          $colored = preg_replace('/⚠[^\n]*/m', '<span class="t-warn">$0</span>', $colored);
          echo $colored . "\n";
          if ($r['ok'] ?? true) {
            echo '<span class="t-ok">✓ SUCCESS</span> <span style="color:#475569">(' . $r['ms'] . 'ms)</span>' . "\n";
          } else {
            echo '<span class="t-err">✗ FAILED</span> <span style="color:#475569">(' . $r['ms'] . 'ms)</span>' . "\n";
          }
        endforeach;
        echo '<span class="t-sep">────────────────────────────────────────</span>' . "\n";
        echo '<span class="t-sum">SUMMARY: ';
        echo '<span class="t-ok">✓ ' . $executionResults['success'] . '</span> success  ';
        echo '<span class="t-err">✗ ' . $executionResults['fail'] . '</span> failed  ';
        echo '<span class="t-info">⏱ ' . $executionResults['total'] . ' total</span></span>';
      ?></div>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($markAbsentResult !== null): ?>
  <section class="section">
    <div class="section-head">
      <div class="section-icon icon-orange">🕐</div>
      <div><div class="section-title">Hasil Mark Absent</div></div>
    </div>
    <div class="terminal">
      <div class="terminal-bar">
        <div class="terminal-dots"><span class="dot dot-red"></span><span class="dot dot-yellow"></span><span class="dot dot-green"></span></div>
        <div class="terminal-title">attendance:mark-absent</div>
      </div>
      <div class="terminal-body"><?= htmlspecialchars($markAbsentResult) ?></div>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($excelImportResult !== null): ?>
  <section class="section">
    <div class="section-head">
      <div class="section-icon icon-green">📂</div>
      <div><div class="section-title">Hasil Import Excel</div></div>
    </div>
    <div class="terminal">
      <div class="terminal-bar">
        <div class="terminal-dots"><span class="dot dot-red"></span><span class="dot dot-yellow"></span><span class="dot dot-green"></span></div>
        <div class="terminal-title">db:seed-from-excel</div>
      </div>
      <div class="terminal-body"><?= htmlspecialchars($excelImportResult) ?></div>
    </div>
  </section>
  <?php endif; ?>

  <!-- ── PRESETS ──────────────────────────── -->
  <section class="section" id="presets">
    <div class="section-head">
      <div class="section-icon icon-amber">🚀</div>
      <div>
        <div class="section-title">Quick Presets</div>
        <div class="section-sub">Jalankan sekumpulan command sekaligus</div>
      </div>
    </div>
    <div class="card">
      <div class="preset-grid">
        <?php foreach ($presets as $presetName => $presetCmds): ?>
        <form method="post" style="display:contents">
          <?php foreach ($presetCmds as $c): ?>
          <input type="hidden" name="commands[]" value="<?= htmlspecialchars($c) ?>">
          <?php endforeach; ?>
          <button type="submit" class="preset-btn">
            <?= htmlspecialchars($presetName) ?>
            <span class="preset-badge"><?= count($presetCmds) ?></span>
          </button>
        </form>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ── ALL COMMANDS ─────────────────────── -->
  <section class="section" id="commands">
    <div class="section-head">
      <div class="section-icon icon-blue">📦</div>
      <div>
        <div class="section-title">Commands</div>
        <div class="section-sub">Pilih satu atau lebih command untuk dijalankan</div>
      </div>
    </div>
    <form method="post" id="commandForm">
      <div class="card">
        <div class="cmd-grid" id="tileGrid">
          <?php foreach ($commands as $label => $cmd):
            $extra = '';
            $tag   = '';
            if (str_contains($label, 'Fresh') || str_contains($label, 'Unlink')) {
              $extra = ' is-danger'; $tag = '<span class="tag tag-danger">Destructive</span>';
            } elseif (str_contains($label, 'FORCE') || str_contains($label, 'Force')) {
              $extra = ' is-warn'; $tag = '<span class="tag tag-warn">Force</span>';
            } elseif (str_contains($label, 'DEBUG') || str_contains($label, 'dry-run')) {
              $extra = ' is-warn'; $tag = '<span class="tag tag-warn">Dry-run</span>';
            } elseif (str_contains($label, 'DB ') || str_contains($label, 'Backup') || str_contains($label, 'Restore')) {
              $extra = ' is-db'; $tag = '<span class="tag tag-db">Database</span>';
            } elseif (str_contains($label, 'Excel') || str_contains($label, 'Seed')) {
              $extra = ' is-excel'; $tag = '<span class="tag tag-excel">Seeder</span>';
            } elseif (str_contains($label, 'Absent')) {
              $extra = ' is-absent'; $tag = '<span class="tag tag-absent">Attendance</span>';
            }
          ?>
          <div class="cmd-tile<?= $extra ?>" onclick="toggleTile(this)">
            <label onclick="event.stopPropagation()">
              <input type="checkbox" name="commands[]" value="<?= htmlspecialchars($label) ?>" onchange="syncTile(this)">
              <div><div><?= htmlspecialchars($label) ?></div><?= $tag ?></div>
            </label>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="custom-input-wrap">
          <span class="custom-input-prefix">php artisan</span>
          <input type="text" name="custom_command" class="custom-input" placeholder="migrate:status   (tanpa php artisan)">
        </div>

        <div class="action-bar">
          <button type="button" class="btn btn-ghost" onclick="selectAll()">✓ Pilih Semua</button>
          <button type="button" class="btn btn-ghost" onclick="deselectAll()">✗ Kosongkan</button>
          <button type="submit" class="btn btn-run">▶ Jalankan</button>
        </div>
      </div>
    </form>
  </section>

  <!-- ── STORAGE STATUS ───────────────────── -->
  <section class="section" id="storage">
    <div class="section-head">
      <div class="section-icon icon-green">💾</div>
      <div>
        <div class="section-title">Storage Link</div>
        <div class="section-sub">Status symlink <code>public/storage</code></div>
      </div>
    </div>
    <div class="card" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
      <?php
        [$st, $msg] = $storageStatus;
        $cls = $st === 'ok' ? 'spl-ok' : ($st === 'warn' ? 'spl-warn' : 'spl-error');
        $ico = $st === 'ok' ? '✓' : ($st === 'warn' ? '⚠' : '✗');
        echo "<div class='storage-pill {$cls}'>{$ico} " . htmlspecialchars($msg) . "</div>";
      ?>
    </div>
  </section>

  <!-- ── MARK ABSENT ──────────────────────── -->
  <section class="section" id="mark-absent">
    <div class="section-head">
      <div class="section-icon icon-orange">🕐</div>
      <div>
        <div class="section-title">Mark Absent Manual</div>
        <div class="section-sub">Tandai pegawai tidak hadir pada tanggal tertentu</div>
      </div>
    </div>
    <form method="post" class="mark-form">
      <p style="font-size:12px;color:var(--muted);margin-bottom:16px">
        Tandai semua pegawai aktif yang belum absen pada tanggal yang dipilih sebagai <strong style="color:var(--accent2)">tidak_hadir</strong>.
        Berlaku hanya untuk hari kerja — hari libur &amp; weekend akan di-skip otomatis.
      </p>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Tanggal</label>
          <input type="date" name="absent_date" value="<?= date('Y-m-d') ?>" class="date-input">
        </div>
        <button type="submit" name="do_mark_absent" value="1" class="btn" style="background:var(--accent2);color:#0f1117;border-color:var(--accent2);font-weight:700">
          ▶ Jalankan
        </button>
      </div>
    </form>
  </section>

  <!-- ── DATABASE BACKUP ──────────────────── -->
  <section class="section" id="backup">
    <div class="section-head">
      <div class="section-icon icon-purple">🗄</div>
      <div>
        <div class="section-title">Database Backup</div>
        <div class="section-sub">Backup manual &amp; riwayat file backup tersimpan</div>
      </div>
    </div>

    <div class="card" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">
      <form method="post" style="display:contents">
        <input type="hidden" name="commands[]" value="DB Backup">
        <button type="submit" class="btn btn-ghost">📥 Backup DB Only</button>
      </form>
      <form method="post" style="display:contents">
        <input type="hidden" name="commands[]" value="Full Backup (DB + Images)">
        <button type="submit" class="btn btn-ghost" style="border-color:rgba(167,139,250,.35);color:var(--purple)">📦 Full Backup (DB + Images)</button>
      </form>
      <form method="post" style="display:contents">
        <input type="hidden" name="commands[]" value="DB Auto Backup (Monthly)">
        <button type="submit" class="btn btn-ghost">🔄 Monthly Backup (6 keep)</button>
      </form>
    </div>

    <?php if (!empty($backupFiles)): ?>
    <div class="card">
      <p style="font-size:11px;color:var(--muted);margin-bottom:14px;font-weight:700;text-transform:uppercase;letter-spacing:.08em">Backup Files (<?= count($backupFiles) ?>)</p>
      <div class="backup-list">
        <?php foreach ($backupFiles as $i => $f):
          $name = basename($f);
          $size = round(filesize($f) / 1024, 2);
          $date = date('d M Y  H:i', filemtime($f));
          $latest = $i === 0;
        ?>
        <div class="backup-item">
          <span style="color:var(--purple)"><?= str_ends_with($name, '.zip') ? '📦' : '📄' ?></span>
          <span class="backup-name"><?= htmlspecialchars($name) ?></span>
          <?php if ($latest): ?><span class="backup-latest">LATEST</span><?php endif; ?>
          <span class="backup-size"><?= $size > 1024 ? round($size/1024,2).' MB' : $size.' KB' ?></span>
          <span class="backup-date"><?= $date ?></span>
        </div>
        <?php endforeach; ?>
      </div>

      <form method="post" style="margin-top:16px">
        <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:8px">Restore dari Backup</p>
        <select name="restore_file" class="restore-select">
          <?php foreach ($backupFiles as $f): ?>
          <option value="<?= htmlspecialchars(basename($f)) ?>"><?= htmlspecialchars(basename($f)) ?> (<?= round(filesize($f)/1024,2) ?> KB)</option>
          <?php endforeach; ?>
        </select>
        <input type="hidden" name="commands[]" value="DB Restore">
        <button type="submit" class="btn btn-danger-outline">🔄 Restore File Terpilih</button>
      </form>
    </div>
    <?php else: ?>
    <div class="card" style="text-align:center;padding:32px;color:var(--muted)">
      <div style="font-size:24px;margin-bottom:8px">🗄</div>
      <div style="font-size:13px">Belum ada file backup</div>
      <div style="font-size:12px;margin-top:4px">Klik "Backup Sekarang" untuk membuat backup pertama</div>
    </div>
    <?php endif; ?>
  </section>

  <!-- ── EXCEL IMPORT ──────────────────────── -->
  <section class="section" id="excel">
    <div class="section-head">
      <div class="section-icon icon-green">📂</div>
      <div>
        <div class="section-title">Upload &amp; Import Excel</div>
        <div class="section-sub">Seed database dari file .xlsx</div>
      </div>
    </div>
    <div class="card">
      <form method="post" enctype="multipart/form-data">
        <p style="font-size:12px;color:var(--muted);margin-bottom:16px">
          Upload file <code style="color:var(--green)">.xlsx</code> untuk diimport — perusahaan, lokasi kantor, shift, users &amp; pegawai.
          Header-aware, mendukung format <em>"ID | Nama"</em>.
        </p>
        <div class="upload-zone" id="dropzone">
          <div style="font-size:28px;margin-bottom:8px">📁</div>
          <input type="file" name="seed_excel" accept=".xlsx,.xls" required id="fileInput" onchange="updateFilename(this)">
          <p id="fileLabel">Pilih file .xlsx atau drag &amp; drop ke sini</p>
        </div>
        <div class="checkbox-row">
          <input type="checkbox" name="excel_truncate" id="truncateCheck">
          <label for="truncateCheck">Truncate — hapus data lama sebelum import</label>
        </div>
        <div style="margin-top:14px">
          <button type="submit" name="do_excel_upload" value="1" class="btn" style="background:var(--green);color:#0a0c12;border-color:var(--green);font-weight:700">
            ⬆ Upload &amp; Import
          </button>
        </div>
      </form>
    </div>
  </section>

  <!-- ── SYSTEM INFO ───────────────────────── -->
  <section class="section" id="cron">
    <div class="section-head">
      <div class="section-icon icon-amber">⏰</div>
      <div>
        <div class="section-title">Cron Schedule</div>
        <div class="section-sub">Konfigurasi cron job untuk hosting</div>
      </div>
    </div>
    <div class="card" style="margin-bottom:16px">
      <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:12px">cPanel Cron Command</p>
      <div style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px 16px;font-family:var(--mono);font-size:12px;color:var(--accent);position:relative;cursor:pointer" onclick="copyText(this)" title="Click to copy">
        * * * * * php <?= htmlspecialchars($projectPath) ?>/artisan schedule:run >> /dev/null 2>&1
        <span style="position:absolute;right:12px;top:50%;transform:translateY(-50%);font-size:11px;color:var(--muted)" class="copy-hint">📋 Click to copy</span>
      </div>
      <p style="font-size:11px;color:var(--muted);margin-top:10px">Tambahkan command di atas ke <strong>cPanel → Cron Jobs</strong> (interval: Every Minute)</p>
    </div>

    <div class="card" style="margin-bottom:16px">
      <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:10px">Registered Scheduled Tasks</p>
      <div style="display:flex;flex-direction:column;gap:6px">
        <div class="backup-item">
          <span style="color:var(--accent2);font-weight:700;font-family:var(--mono);min-width:110px;flex-shrink:0">17:05 daily</span>
          <span style="font-family:var(--mono);color:var(--text);flex:1">attendance:mark-absent</span>
          <span style="font-size:10px;color:var(--muted)">Asia/Jakarta</span>
        </div>
        <div class="backup-item">
          <span style="color:var(--accent2);font-weight:700;font-family:var(--mono);min-width:110px;flex-shrink:0">00:30 tgl 1</span>
          <span style="font-family:var(--mono);color:var(--text);flex:1">db:auto-backup --keep=6</span>
          <span style="font-size:10px;color:var(--muted)">Asia/Jakarta</span>
        </div>
        <div class="backup-item">
          <span style="color:var(--accent2);font-weight:700;font-family:var(--mono);min-width:110px;flex-shrink:0">02:00 daily</span>
          <span style="font-family:var(--mono);color:var(--text);flex:1">recycle:cleanup --days=30</span>
          <span style="font-size:10px;color:var(--muted)">Asia/Jakarta</span>
        </div>
      </div>
    </div>

    <div class="card" style="display:flex;gap:10px;flex-wrap:wrap">
      <form method="post" style="display:contents">
        <input type="hidden" name="custom_command" value="schedule:list">
        <button type="submit" class="btn btn-ghost">📋 View Schedule List</button>
      </form>
      <form method="post" style="display:contents">
        <input type="hidden" name="custom_command" value="schedule:run">
        <button type="submit" class="btn btn-ghost" style="border-color:rgba(245,158,11,.35);color:var(--accent)">▶ Test Schedule Run</button>
      </form>
    </div>
  </section>

  <!-- ── SYSTEM INFO ───────────────────────── -->
    <div class="section-head">
      <div class="section-icon icon-blue">ℹ</div>
      <div>
        <div class="section-title">System Info</div>
        <div class="section-sub">Environment &amp; runtime</div>
      </div>
    </div>
    <div class="card">
      <div class="info-grid">
        <span class="info-key">PHP Version</span>
        <span class="info-val"><?= PHP_VERSION ?></span>
        <span class="info-key">Server Time</span>
        <span class="info-val"><?= date('Y-m-d H:i:s T') ?></span>
        <span class="info-key">WIB Time</span>
        <span class="info-val"><?= (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s') ?> WIB</span>
        <span class="info-key">Project Path</span>
        <span class="info-val" style="font-size:11px;word-break:break-all"><?= htmlspecialchars($projectPath) ?></span>
        <span class="info-key">APP_ENV</span>
        <span class="info-val"><span class="badge-env"><?= htmlspecialchars(env('APP_ENV', 'unknown')) ?></span></span>
        <span class="info-key">APP_URL</span>
        <span class="info-val" style="font-size:12px"><?= htmlspecialchars(env('APP_URL', '—')) ?></span>
        <span class="info-key">DB Driver</span>
        <span class="info-val"><?= htmlspecialchars(env('DB_CONNECTION', 'mysql')) ?></span>
      </div>
    </div>
  </section>

</main>
</div><!-- /.layout -->

<script>
// Theme toggle
function toggleTheme() {
  const isLight = document.documentElement.classList.toggle('light');
  localStorage.setItem('deploy-theme', isLight ? 'light' : 'dark');
  document.getElementById('themeIcon').textContent = isLight ? '☀️' : '🌙';
}
(function() {
  if (localStorage.getItem('deploy-theme') === 'light') {
    document.documentElement.classList.add('light');
    const ico = document.getElementById('themeIcon');
    if (ico) ico.textContent = '☀️';
  }
})();

// Copy cron command
function copyText(el) {
  const text = el.textContent.replace(/📋.*$/m, '').trim();
  navigator.clipboard.writeText(text).then(() => {
    const hint = el.querySelector('.copy-hint');
    if (hint) {
      hint.textContent = '✓ Copied!';
      hint.style.color = 'var(--green)';
      setTimeout(() => { hint.textContent = '📋 Click to copy'; hint.style.color = 'var(--muted)'; }, 2000);
    }
  });
}

// Live clock
setInterval(() => {
  const el = document.getElementById('clock');
  if (el) {
    const now = new Date();
    el.textContent = now.toLocaleTimeString('id-ID', {hour:'2-digit',minute:'2-digit',second:'2-digit'}) + ' WIB';
  }
}, 1000);

// Tile toggle
function toggleTile(tile) {
  const cb = tile.querySelector('input[type=checkbox]');
  cb.checked = !cb.checked;
  tile.classList.toggle('selected', cb.checked);
}
function syncTile(cb) {
  cb.closest('.cmd-tile').classList.toggle('selected', cb.checked);
}

function selectAll() {
  document.querySelectorAll('#tileGrid input[type=checkbox]').forEach(cb => {
    cb.checked = true;
    cb.closest('.cmd-tile').classList.add('selected');
  });
}
function deselectAll() {
  document.querySelectorAll('#tileGrid input[type=checkbox]').forEach(cb => {
    cb.checked = false;
    cb.closest('.cmd-tile').classList.remove('selected');
  });
}

// File input label
function updateFilename(input) {
  const label = document.getElementById('fileLabel');
  if (input.files && input.files[0]) {
    const size = (input.files[0].size / 1024).toFixed(1);
    label.textContent = '✓ ' + input.files[0].name + ' (' + size + ' KB)';
    label.style.color = '#4ade80';
  }
}

// Scroll to results if any
<?php if ($executionResults || $markAbsentResult !== null || $excelImportResult !== null): ?>
window.addEventListener('DOMContentLoaded', () => {
  const el = document.getElementById('results') || document.querySelector('.section');
  if (el) setTimeout(() => el.scrollIntoView({behavior:'smooth'}), 150);
});
<?php endif; ?>
</script>
</body>
</html>
<?php echo ob_get_clean(); ?>

