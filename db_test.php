<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Database Connection Test</h1>";

try {
    $pdo = new PDO(
        "mysql:host=localhost;port=3306;dbname=cocovalley_admin;charset=utf8mb4",
        "root",
        ""
    );
    echo "<p style='color:green;'>CONNECTED SUCCESSFULLY!</p>";

    echo "<h3>Active Database:</h3>";
    echo $pdo->query("SELECT DATABASE()")->fetchColumn();

    echo "<h3>Tables:</h3>";
    $tables = $pdo->query("SHOW TABLES")->fetchAll();
    echo "<pre>";
    print_r($tables);
    echo "</pre>";

} catch (PDOException $e) {
    echo "<p style='color:red; font-size:18px;'>ERROR: " . $e->getMessage() . "</p>";
}
?>
