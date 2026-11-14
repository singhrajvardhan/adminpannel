// /raj/assets/js/location.js
let mapInstance = null;
let markerInstance = null;

function initLocation() {
    const getLocationBtn = document.getElementById('getLocationBtn');
    if (getLocationBtn) {
        getLocationBtn.addEventListener('click', getLocation);
    }
}

function getLocation() {
    const status = document.getElementById('locationStatus');
    const mapDiv = document.getElementById('locationMap');
    const result = document.getElementById('locationResult');
    
    status.innerHTML = '<p>📍 Getting your location...</p>';
    status.className = 'location-status';
    
    if (!navigator.geolocation) {
        status.innerHTML = '<p>❌ Geolocation is not supported by your browser.</p>';
        status.className = 'location-status error';
        return;
    }
    
    navigator.geolocation.getCurrentPosition(
        function(position) {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            const accuracy = position.coords.accuracy;
            const timestamp = new Date(position.timestamp).toISOString();
            
            // Show on map
            showLocationOnMap(lat, lng);
            mapDiv.style.display = 'block';
            result.style.display = 'block';
            status.innerHTML = '<p>✅ Location Captured Successfully!</p>';
            status.className = 'location-status success';
            
            // Save to server
            fetch('/raj/api/save-location.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    agent_id: agentId,
                    latitude: lat,
                    longitude: lng,
                    accuracy: accuracy,
                    timestamp: timestamp
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    console.log('Location saved');
                    stepData.location_captured = true;
                }
            })
            .catch(err => console.error('Location save error:', err));
            
        },
        function(error) {
            let message = '❌ Location permission denied. Please enable location access.';
            if (error.code === 1) {
                message = '❌ Location permission denied. Please allow location access in your browser.';
            } else if (error.code === 2) {
                message = '❌ Location unavailable. Please try again.';
            } else if (error.code === 3) {
                message = '❌ Location request timed out. Please try again.';
            }
            status.innerHTML = `<p>${message}</p>`;
            status.className = 'location-status error';
        },
        {
            enableHighAccuracy: true,
            timeout: 10000,
            maximumAge: 0
        }
    );
}

function showLocationOnMap(lat, lng) {
    const mapDiv = document.getElementById('locationMap');
    
    if (!mapInstance) {
        mapInstance = L.map(mapDiv).setView([lat, lng], 15);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
        }).addTo(mapInstance);
    } else {
        mapInstance.setView([lat, lng], 15);
    }
    
    if (markerInstance) {
        mapInstance.removeLayer(markerInstance);
    }
    
    markerInstance = L.marker([lat, lng]).addTo(mapInstance)
        .bindPopup('📍 Your Location');
    
    setTimeout(() => {
        mapInstance.invalidateSize();
    }, 300);
}