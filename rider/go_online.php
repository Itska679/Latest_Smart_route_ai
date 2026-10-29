<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

$pageTitle = 'Go Online';
require_once __DIR__ . '/includes/header.php';
?>

<div class="panel">
    <div class="panel-header">
        <div>
            <h3>📡 Live Location Tracking</h3>
            <p>Share your live location so the dispatcher can see you on the map</p>
        </div>
    </div>
    <div class="panel-body" style="text-align:center;">

        <div id="statusCard" style="padding:24px;border-radius:12px;background:#f8fafc;max-width:500px;margin:0 auto 20px;">
            <div id="statusIcon" style="font-size:52px;margin-bottom:10px;">⚪</div>
            <h3 id="statusText" style="font-size:18px;margin-bottom:6px;">Offline</h3>
            <p id="statusSub" style="color:#64748b;font-size:13.5px;">Tap "Go Online" to start sharing your location.</p>
        </div>

        <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-bottom:24px;">
            <button type="button" class="btn" id="onlineBtn" onclick="goOnline()">🟢 Go Online</button>
            <button type="button" class="btn-secondary" id="offlineBtn" onclick="goOffline()" style="display:none;">🔴 Go Offline</button>
        </div>

        <div id="coordsBox" style="display:none;background:#1e293b;color:#e2e8f0;font-family:monospace;padding:16px;border-radius:8px;max-width:500px;margin:0 auto;text-align:left;font-size:13px;">
            <div>📍 <span id="latDisplay">—</span>, <span id="lngDisplay">—</span></div>
            <div style="margin-top:4px;color:#94a3b8;">🎯 Accuracy: <span id="accDisplay">—</span> m</div>
            <div style="margin-top:4px;color:#94a3b8;">⏱️ Last sent: <span id="lastSent">—</span></div>
            <div style="margin-top:4px;color:#94a3b8;">📶 Pings sent: <span id="pingCount">0</span></div>
        </div>

        <div id="logBox" style="display:none;margin-top:20px;text-align:left;font-size:12px;color:#64748b;max-width:500px;margin-left:auto;margin-right:auto;background:#f1f5f9;padding:12px;border-radius:6px;max-height:180px;overflow-y:auto;font-family:monospace;" id="logBox"></div>

    </div>
</div>

<script>
let watchId = null;
let sendInterval = null;
let lastPosition = null;
let pingCount = 0;

function log(msg) {
    const box = document.getElementById('logBox');
    box.style.display = 'block';
    const time = new Date().toLocaleTimeString();
    box.innerHTML = `[${time}] ${msg}<br>` + box.innerHTML;
}

function setStatus(state) {
    const icon = document.getElementById('statusIcon');
    const text = document.getElementById('statusText');
    const sub  = document.getElementById('statusSub');
    const onlineBtn  = document.getElementById('onlineBtn');
    const offlineBtn = document.getElementById('offlineBtn');
    const coordsBox  = document.getElementById('coordsBox');

    if (state === 'online') {
        icon.textContent = '🟢';
        text.textContent = 'Online — Broadcasting';
        sub.textContent  = 'Your location is being shared with the dispatcher every 15 seconds.';
        onlineBtn.style.display = 'none';
        offlineBtn.style.display = 'inline-flex';
        coordsBox.style.display = 'block';
    } else if (state === 'pending') {
        icon.textContent = '🟡';
        text.textContent = 'Starting...';
        sub.textContent  = 'Waiting for GPS permission and first fix.';
    } else {
        icon.textContent = '⚪';
        text.textContent = 'Offline';
        sub.textContent  = 'Tap "Go Online" to start sharing your location.';
        onlineBtn.style.display = 'inline-flex';
        offlineBtn.style.display = 'none';
        coordsBox.style.display = 'none';
    }
}

function sendPosition() {
    if (!lastPosition) return;

    const payload = {
        lat: lastPosition.coords.latitude,
        lng: lastPosition.coords.longitude,
        accuracy: lastPosition.coords.accuracy,
        status: 'delivering'
    };

    fetch('/smart-route/api/update_location.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
        credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            pingCount++;
            document.getElementById('pingCount').textContent = pingCount;
            document.getElementById('lastSent').textContent = new Date().toLocaleTimeString();
        } else {
            log('❌ Server: ' + (data.error || 'unknown'));
        }
    })
    .catch(err => log('❌ Network: ' + err.message));
}

function goOnline() {
    if (!navigator.geolocation) {
        alert('Geolocation is not supported by this browser.');
        return;
    }

    setStatus('pending');

    watchId = navigator.geolocation.watchPosition(
        (pos) => {
            lastPosition = pos;

            document.getElementById('latDisplay').textContent = pos.coords.latitude.toFixed(6);
            document.getElementById('lngDisplay').textContent = pos.coords.longitude.toFixed(6);
            document.getElementById('accDisplay').textContent = Math.round(pos.coords.accuracy);

            if (pingCount === 0) {
                setStatus('online');
                log('📍 First GPS fix acquired');
            }
        },
        (err) => {
            log('❌ GPS error: ' + err.message);
            alert('GPS error: ' + err.message + '\n\nMake sure location permission is enabled.');
            goOffline();
        },
        {
            enableHighAccuracy: true,
            maximumAge: 5000,
            timeout: 15000
        }
    );

    // Send a ping every 15 seconds
    sendInterval = setInterval(sendPosition, 15000);

    // Send initial ping after 2 seconds
    setTimeout(sendPosition, 2000);

    log('🟢 Started broadcasting');
}

function goOffline() {
    if (watchId !== null) {
        navigator.geolocation.clearWatch(watchId);
        watchId = null;
    }
    if (sendInterval) {
        clearInterval(sendInterval);
        sendInterval = null;
    }

    // Tell server we're offline
    fetch('/smart-route/api/update_location.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ lat: lastPosition?.coords.latitude || 0, lng: lastPosition?.coords.longitude || 0, status: 'offline' }),
        credentials: 'same-origin'
    }).catch(() => {});

    lastPosition = null;
    pingCount = 0;
    setStatus('offline');
    log('🔴 Stopped broadcasting');
}

// Clean up on page unload
window.addEventListener('beforeunload', () => {
    if (watchId !== null) {
        navigator.geolocation.clearWatch(watchId);
    }
    if (sendInterval) {
        clearInterval(sendInterval);
    }
});
</script>

</main>
</body>
</html>