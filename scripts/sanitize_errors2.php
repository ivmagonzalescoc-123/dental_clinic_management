<?php
$files = [
    'doctor/doctor_modules/leave_requests.php',
    'secretary/secretary_modules/doctor_schedule.php',
    'admin/admin_modules/doctor_leaves.php',
    'admin/admin_modules/doctor_deployment.php',
    'admin/admin_modules/manage_users.php',
];

$basePath = 'C:/xampp/htdocs/capstone_dental/';

foreach ($files as $file) {
    $fullPath = $basePath . $file;
    if (!file_exists($fullPath)) {
        echo "Not found: $file\n";
        continue;
    }
    
    $content = file_get_contents($fullPath);
    $original = $content;
    
    $patterns = [
        '/Security token mismatch\. Please refresh and try again\./' => 'Please refresh the page and try again.',
        '/Security token mismatch/' => 'Please refresh the page and try again.',
    ];
    
    foreach ($patterns as $search => $replace) {
        $content = preg_replace($search, $replace, $content);
    }
    
    if ($content !== $original) {
        file_put_contents($fullPath, $content);
        echo "Updated: $file\n";
    } else {
        echo "No change: $file\n";
    }
}

echo "Done!\n";