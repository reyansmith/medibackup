<?php
$stylePath = __DIR__ . '/../assets/css/style.css';
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$scriptDir = str_replace('\\', '/', dirname($scriptName));
$appFolders = ['admin', 'employee', 'auth'];
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
    <title>Mannath Medicals</title>
   
    <link rel="stylesheet" href="<?php echo htmlspecialchars($assetBaseUrl . '/assets/css/style.css', ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo filemtime($stylePath); ?>">

    <script src="https://kit.fontawesome.com/6c8e1d3298.js" crossorigin="anonymous"></script>
</head>
<body>

<div class="container">

