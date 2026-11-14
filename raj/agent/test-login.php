<?php
// /raj/agent/test-login.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/encryption.php';

// Test with a specific agent ID
$testAgentId = 'BGS1001';  // Change to your agent ID
$testDob = '15/08/1990';   // Change to the DOB you used

echo "<h2>Agent Login Debug</h2>";

// 1. Check if agent exists
$result = executeQuery("SELECT * FROM agents WHERE agent_id = ?", [$testAgentId]);
if ($result->num_rows == 0) {
    die("❌ Agent ID <strong>$testAgentId</strong> not found in database!<br>
         <a href='/raj/admin/create-agent.php'>Create an agent first</a>");
}

$agent = $result->fetch_assoc();
echo "✅ Agent found: " . $agent['agent_id'] . "<br>";
echo "Name: " . ($agent['full_name'] ?: 'N/A') . "<br>";
echo "Account Status: " . $agent['account_status'] . "<br>";
echo "Onboarding Completed: " . ($agent['onboarding_completed'] ? 'Yes' : 'No') . "<br><br>";

// 2. Check DOB
$encryptedDob = $agent['date_of_birth'];
echo "Encrypted DOB in DB: " . substr($encryptedDob, 0, 30) . "...<br>";

$decryptedDob = decryptData($encryptedDob);
echo "Decrypted DOB: " . $decryptedDob . "<br>";
echo "Test DOB: " . $testDob . "<br>";

if ($decryptedDob === $testDob) {
    echo "✅ DOB MATCHES!<br><br>";
    echo "<strong style='color: green;'>Login would be successful!</strong>";
} else {
    echo "❌ DOB DOES NOT MATCH!<br>";
    echo "Please check:<br>";
    echo "1. The DOB format in database: " . $decryptedDob . "<br>";
    echo "2. The DOB you entered: " . $testDob . "<br>";
    echo "3. Make sure both use DD/MM/YYYY format<br>";
}

// 3. Check agent status
echo "<br><strong>Account Status:</strong> " . $agent['account_status'] . "<br>";
if ($agent['account_status'] !== 'active') {
    echo "⚠️ Account is not active. Current status: " . $agent['account_status'] . "<br>";
}
?>