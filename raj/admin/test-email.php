<?php
// /raj/admin/test-email.php
require_once __DIR__ . '/../includes/config.php';

echo "<h2>📧 Email Test</h2>";
echo "<style>body{font-family:monospace;padding:20px;background:#1a1a2e;color:#e0e0e0;} .pass{color:#4caf50;} .fail{color:#f44336;} .info{color:#2196f3;}</style>";

$to = "2027rajsingh@gmail.com";
$subject = "Test Email - BetterGrowth Solutions";
$message = "
<html>
<body style='font-family: Arial, sans-serif;'>
    <h2>📧 Test Email</h2>
    <p>This is a test email from your BetterGrowth Solutions system.</p>
    <p>If you receive this, email sending is working!</p>
    <p>Time: " . date('Y-m-d H:i:s') . "</p>
</body>
</html>";

$headers = "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/html; charset=UTF-8\r\n";
$headers .= "From: BetterGrowth Solutions <info@bettergrowthsolutions.com>\r\n";
$headers .= "Reply-To: info@bettergrowthsolutions.com\r\n";

$sent = mail($to, $subject, $message, $headers);

if ($sent) {
    echo "<p class='pass'>✅ Test email sent to: $to</p>";
    echo "<p>Check your inbox and spam folder.</p>";
} else {
    echo "<p class='fail'>❌ Email failed to send</p>";
    echo "<p>The PHP mail() function is not working on this server.</p>";
}

echo "<hr>";
echo "<p class='info'>SMTP Settings from config:</p>";
echo "<p>SMTP_FROM: " . (defined('SMTP_FROM') ? SMTP_FROM : 'NOT SET') . "</p>";
echo "<p>SMTP_HOST: " . (defined('SMTP_HOST') ? SMTP_HOST : 'NOT SET') . "</p>";
echo "<hr>";
echo "<p><a href='dashboard.php' style='color:#c9962f;'>Back to Dashboard</a></p>";
?>