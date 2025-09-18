// Initialize map
let map = L.map('map').setView([0, 0], 13);
let markers = {};
let autoRefreshInterval;

// Add OpenStreetMap tiles
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '© OpenStreetMap contributors'
}).addTo(map);

// Function to create/update markers
function updateMarkers(locations) {
    locations.forEach(location => {
        const markerId = `rider-${location.rider_id}`;
        const latLng = [parseFloat(location.lat), parseFloat(location.lng)];

        // Create or update marker
        if (markers[markerId]) {
            markers[markerId].setLatLng(latLng);
        } else {
            const marker = L.marker(latLng);
            marker.bindPopup(`<b>${location.rider_name}</b><br>Last updated: ${location.updated_at}`);
            marker.addTo(map);
            markers[markerId] = marker;
        }
    });

    // Fit map bounds if there are markers
    if (locations.length > 0) {
        const bounds = Object.values(markers).map(marker => marker.getLatLng());
        map.fitBounds(bounds);
    }

    // Update last updated time
    document.getElementById('lastUpdated').textContent = `Last updated: ${new Date().toLocaleTimeString()}`;
}

// Function to fetch rider locations
async function fetchLocations() {
    try {
        const response = await fetch('api/get_rider_locations.php');
        const data = await response.json();
        updateMarkers(data);
    } catch (error) {
        console.error('Error fetching rider locations:', error);
    }
}

// Initialize markers with initial data
updateMarkers(initialLocations);

// Manual refresh button
document.getElementById('refreshMap').addEventListener('click', fetchLocations);

// Auto refresh toggle
document.getElementById('autoRefresh').addEventListener('change', function(e) {
    if (e.target.checked) {
        // Start auto refresh (every 30 seconds)
        autoRefreshInterval = setInterval(fetchLocations, 30000);
    } else {
        // Stop auto refresh
        clearInterval(autoRefreshInterval);
    }
});