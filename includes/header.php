<?php
// Set path for CSS file
$stylePath = __DIR__ . '/../assets/css/style.css';
$faviconPath = __DIR__ . '/../assets/favicon-rounded.png';
// Get script name and directory
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$scriptDir = str_replace('\\', '/', dirname($scriptName));
$appFolders = ['admin', 'employee', 'auth'];
// Determine base path for assets
$basePath = in_array(basename($scriptDir), $appFolders, true) ? dirname($scriptDir) : $scriptDir;
$basePath = str_replace('\\', '/', $basePath);
$basePath = $basePath === '/' || $basePath === '.' ? '' : rtrim($basePath, '/');
$assetBaseUrl = $basePath === '' ? '' : $basePath;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medivault</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($assetBaseUrl . '/assets/favicon-rounded.png', ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo filemtime($faviconPath); ?>">
    <!-- Main stylesheet -->
    <link rel="stylesheet" href="<?php echo htmlspecialchars($assetBaseUrl . '/assets/css/style.css', ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo filemtime($stylePath); ?>">
    <!-- Font Awesome icons -->
    <script src="https://kit.fontawesome.com/6c8e1d3298.js" crossorigin="anonymous"></script>
</head>
<body>

<div class="container"> 
