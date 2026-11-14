<?php
// /raj/test-photo.php
$agentId = 'BGS1003';
$photoPath = 'candidate/d73bcce5e62f5ee3cb97148404982d3a.png';

echo "<h2>Testing Photo Display</h2>";

// Direct file check
$fullPath = __DIR__ . '/storage/agents/' . $agentId . '/' . $photoPath;
echo "<p>File path: <code>$fullPath</code></p>";

if (file_exists($fullPath)) {
    echo "<p style='color:green;'>✅ File exists!</p>";
    echo "<p>File size: " . filesize($fullPath) . " bytes</p>";
    
    // Show the image using direct path
    echo "<h3>Direct file read:</h3>";
    echo "<img src='data:image/png;base64," . base64_encode(file_get_contents($fullPath)) . "' style='max-width:300px; border:2px solid #ddd; border-radius:8px;'>";
    
    echo "<h3>Via API endpoint:</h3>";
    $apiUrl = "/raj/api/upload-document.php?action=serve&agent=$agentId&file=" . urlencode($photoPath);
    echo "<img src='$apiUrl' style='max-width:300px; border:2px solid #ddd; border-radius:8px;' 
          onerror=\"this.style.display='none'; this.parentElement.innerHTML += '<p style=\\'color:red;\\'>❌ API failed</p>';\">";
    echo "<p>URL: <code>$apiUrl</code></p>";
} else {
    echo "<p style='color:red;'>❌ File does NOT exist!</p>";
}
?>