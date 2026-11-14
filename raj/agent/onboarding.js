// Send OTP
function sendOtp() {
    const email = document.getElementById('email').value;
    fetch('/api/send-otp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'email=' + encodeURIComponent(email)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('OTP sent to your email.');
        } else {
            alert('Error: ' + data.message);
        }
    });
}

// Verify OTP
function verifyOtp() {
    const otp = document.getElementById('otp').value;
    fetch('/api/verify-otp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'otp=' + encodeURIComponent(otp)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            document.getElementById('otp-status').innerHTML = '✓ Email Verified';
            // Enable next step
        } else {
            alert('Error: ' + data.message);
        }
    });
}