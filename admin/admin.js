/* Santa's List - Admin JS */
jQuery(function ($) {

    // ── Geofence map (settings page only) ──────────────────────────────────
    var mapEl = document.getElementById('sl_geofence_map');
    if (!mapEl || typeof L === 'undefined') {
        // Leaflet not loaded (not on settings page)
    } else {
        var cfg     = window.santaListAdmin || {};
        var initLat = parseFloat(cfg.savedLat)    || 30.0;
        var initLng = parseFloat(cfg.savedLng)    || -90.0;
        var initRad = parseInt(cfg.savedRadius, 10) || 500;
        var hasCoords = cfg.savedLat !== '' && cfg.savedLng !== '';

        // Map center: saved coords if available, else a neutral US default
        var mapCenter = hasCoords ? [initLat, initLng] : [30.0, -90.0];
        var mapZoom   = hasCoords ? 16 : 10;

        var map = L.map('sl_geofence_map').setView(mapCenter, mapZoom);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© <a href="https://www.openstreetmap.org/">OpenStreetMap</a> contributors',
            maxZoom: 19
        }).addTo(map);

        // Convert feet → metres
        function feetToMeters(ft) { return Math.round(ft * 0.3048); }

        // Update radius label
        function updateRadiusLabel(feet) {
            var m = feetToMeters(feet);
            $('#sl_radius_meters').text(m.toLocaleString());
        }
        updateRadiusLabel(initRad);

        var marker, circle;

        function placeMarker(lat, lng, radiusFt) {
            var radiusM = feetToMeters(radiusFt);
            if (marker) {
                marker.setLatLng([lat, lng]);
                circle.setLatLng([lat, lng]);
                circle.setRadius(radiusM);
            } else {
                marker = L.marker([lat, lng], { draggable: true }).addTo(map);
                circle = L.circle([lat, lng], {
                    radius: radiusM,
                    color: '#c0392b',
                    fillColor: '#e74c3c',
                    fillOpacity: 0.15,
                    weight: 2
                }).addTo(map);

                marker.on('dragend', function (e) {
                    var ll = e.target.getLatLng();
                    updateFields(ll.lat.toFixed(7), ll.lng.toFixed(7));
                    circle.setLatLng([ll.lat, ll.lng]);
                });
            }
            map.setView([lat, lng], Math.max(map.getZoom(), 15));
        }

        function updateFields(lat, lng) {
            $('#sl_geo_lat').val(lat);
            $('#sl_geo_lng').val(lng);
        }

        function readAndPlace() {
            var lat = parseFloat($('#sl_geo_lat').val());
            var lng = parseFloat($('#sl_geo_lng').val());
            var ft  = parseInt($('#sl_geo_radius').val(), 10) || 500;
            if (!isNaN(lat) && !isNaN(lng)) {
                placeMarker(lat, lng, ft);
            } else if (circle) {
                circle.setRadius(feetToMeters(ft));
            }
            updateRadiusLabel(ft);
        }

        // Initial placement if coords are saved
        if (hasCoords) placeMarker(initLat, initLng, initRad);

        // Map click → move marker
        map.on('click', function (e) {
            var lat = e.latlng.lat.toFixed(7);
            var lng = e.latlng.lng.toFixed(7);
            updateFields(lat, lng);
            readAndPlace();
        });

        // Radius slider → update circle live
        $('#sl_geo_radius').on('input change', function () {
            readAndPlace();
        });

        // Manual field edits
        $('#sl_geo_lat, #sl_geo_lng').on('input blur', function () {
            readAndPlace();
        });

        // Paste row: accept "lat, lng" or "lat lng" from Google Maps
        $('#sl_geo_paste').on('input paste', function () {
            var raw = $(this).val().trim();
            // Google format: "30.271570313710804, -93.37569136824335"
            var match = raw.match(/(-?\d+\.?\d*)[,\s]+(-?\d+\.?\d*)/);
            if (match) {
                var lat = parseFloat(match[1]).toFixed(7);
                var lng = parseFloat(match[2]).toFixed(7);
                $('#sl_geo_lat').val(lat);
                $('#sl_geo_lng').val(lng);
                $(this).val(''); // clear paste field
                readAndPlace();
            }
        });

        // "Use My Location" button
        $('#sl_geo_my_location').on('click', function () {
            if (!navigator.geolocation) {
                alert('Geolocation is not supported by this browser.');
                return;
            }
            var $btn = $(this);
            $btn.prop('disabled', true).text('⏳ Getting location…');
            navigator.geolocation.getCurrentPosition(
                function (pos) {
                    var lat = pos.coords.latitude.toFixed(7);
                    var lng = pos.coords.longitude.toFixed(7);
                    updateFields(lat, lng);
                    readAndPlace();
                    $btn.prop('disabled', false).html('📍 Use My Location');
                },
                function () {
                    alert('Could not get your location. Please paste coordinates manually.');
                    $btn.prop('disabled', false).html('📍 Use My Location');
                },
                { timeout: 10000 }
            );
        });
    } // end map block

    // ── API key copy button ─────────────────────────────────────────────────
    $(document).on('click', '.santa-copy-btn[data-copy]', function () {
        var text = $(this).data('copy');
        navigator.clipboard.writeText(text).then(function () {
            var $btn = $(this);
            $btn.addClass('copied').text('✓ Copied!');
            setTimeout(function () { $btn.removeClass('copied').text('Copy'); }, 2000);
        }.bind(this)).catch(function () {
            // Fallback
            var $tmp = $('<input>').val(text).appendTo('body').select();
            document.execCommand('copy');
            $tmp.remove();
        });
    });

});
