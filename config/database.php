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