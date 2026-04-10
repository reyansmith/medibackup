<?php
$servername = "localhost";
$username = "root";
$password = "";
$database = "medivault_db";

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($servername, $username, $password, $database);

if ($conn->connect_errno) {
    http_response_code(503);
    $problemMessage = "Unable to connect to the database. Please try again.";

    if ($conn->connect_errno === 1049) {
        $problemMessage = "Database not found. Please create the " . $database . " database.";
    } elseif ($conn->connect_errno === 2002) {
        $problemMessage = "Unable to connect to MySQL. Please start MySQL and try again.";
    } elseif ($conn->connect_errno === 1045) {
        $problemMessage = "Database login failed. Please check the database username and password.";
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medivault Database Error</title>
    <link rel="icon" type="image/png" href="../assets/favicon-rounded.png">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            font-family: "Segoe UI", Arial, sans-serif;
            background: #f8fafc;
            color: #0f172a;
        }

        .database-error {
            width: 100%;
            max-width: 520px;
            padding: 28px;
            border: 1px solid #fecdd3;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.12);
        }

        .database-error h1 {
            margin-bottom: 10px;
            color: #991b1b;
            font-size: 26px;
        }

        .database-error p {
            color: #475569;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <main class="database-error">
        <h1>Database connection failed</h1>
        <p><?php echo htmlspecialchars($problemMessage, ENT_QUOTES, 'UTF-8'); ?></p>
    </main>
</body>
</html>
    <?php
    exit();
}
/*
<?php
$servername = "localhost";
$username = "root";
$password = "";
$database = "medivault_db";
$conn = new mysqli($servername, $username, $password, $database);
// $servername = "sql207.infinityfree.com";
// $username   = "if0_41448273";

// $password   = "tW77ukt9126Jv";
// $database   = "if0_41448273_medivault_db";

//$conn = mysqli_connect($servername, $username, $password, $database);

if (!$conn) {
    die("Connection Failed: " . mysqli_error($conn));
}
?>
*/

?>
