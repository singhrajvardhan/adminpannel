<?php
// /raj/admin/test_login.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$username = 'admin';
$password = 'admin123';

$conn = getDbConnection();
$result = $conn->query("SELECT * FROM admins WHERE username='$username'");

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo "Found admin: " . $row['username'] . "<br>";
    echo "Stored hash: " . $row['password_hash'] . "<br>";
    
    if (password_verify($password, $row['password_hash'])) {
        echo "✅ Password is CORRECT!<br>";
    } else {
        echo "❌ Password is INCORRECT. Recreate the admin.<br>";
        
        // Recreate admin
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $conn->query("UPDATE admins SET password_hash='$newHash' WHERE username='admin'");
        echo "✅ Admin password reset. Try again.<br>";
    }
} else {
    echo "❌ Admin not found. Run install.php first.<br>";
}
?>