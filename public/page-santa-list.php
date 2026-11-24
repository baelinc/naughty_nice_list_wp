<?php
/**
 * Santa's List — Standalone QR Landing Page Template
 *
 * This file is a WordPress page template. To use it:
 *   1. This file is already included in the plugin.
 *   2. Create a new WordPress Page (Pages → Add New).
 *   3. Title it "Santa's List" (or anything you like).
 *   4. In the Page Attributes panel on the right, choose
 *      "Santa's List — Standalone" from the Template dropdown.
 *   5. Publish the page. The URL is your QR code target.
 *
 * The page renders with NO theme header, footer, or sidebar —
 * it looks completely standalone, like its own app.
 *
 * Template Name: Santa's List — Standalone
 */

defined( 'ABSPATH' ) || exit;

// Bootstrap WordPress but skip the theme completely
$settings    = get_option( 'santa_list_settings', array() );
$gate_mode   = $settings['gate_mode']   ?? 'code';
$req_code    = in_array( $gate_mode, array( 'code', 'both' ), true );
$req_geo     = in_array( $gate_mode, array( 'geo',  'both' ), true );
$nonce       = wp_create_nonce( 'santa_list_submit' );
$rest_url    = rest_url( 'santa-list/v1' );
$ajax_url    = admin_url( 'admin-ajax.php' );
$plugin_url  = SANTA_LIST_URL;
$version     = SANTA_LIST_VERSION;

// Allow custom title/subtitle via page meta (optional)
$page_id     = get_the_ID();
$custom_title    = get_post_meta( $page_id, '_santa_list_title',    true ) ?: "Santa's Naughty & Nice List";
$custom_subtitle = get_post_meta( $page_id, '_santa_list_subtitle', true ) ?: 'Add your child to the list!';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="theme-color" content="#8b0000">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?php echo esc_html( $custom_title ); ?></title>

    <!-- Prevent theme styles from loading — only our own -->
    <?php wp_head(); // needed for nonce / scripts to work correctly ?>

    <style>
        /* ── Reset everything the theme may have added ── */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body {
            height: 100%;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        /* ── Full-screen festive background ── */
        body {
            min-height: 100vh;
            background: #8b0000;
            background-image:
                radial-gradient(ellipse at 20% 10%,  rgba(255,255,255,0.06) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 90%,  rgba(0,100,0,0.3)      0%, transparent 50%),
                radial-gradient(ellipse at 50% 50%,  #6d0000                0%, #8b0000 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding: 20px 16px 40px;
            overflow-x: hidden;
        }

        /* ── Snowflake animation ── */
        .snowflakes {
            position: fixed;
            top: 0; left: 0; right: 0;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
            height: 100vh;
        }
        .snowflake {
            position: absolute;
            top: -20px;
            color: rgba(255,255,255,0.5);
            font-size: 1em;
            animation: fall linear infinite;
            user-select: none;
        }
        @keyframes fall {
            to { transform: translateY(110vh) rotate(360deg); opacity: 0; }
        }

        /* ── Page header ── */
        .sl-page-header {
            text-align: center;
            margin-bottom: 24px;
            position: relative;
            z-index: 1;
            padding-top: 10px;
        }
        .sl-page-header-icon {
            font-size: 4em;
            display: block;
            margin-bottom: 8px;
            filter: drop-shadow(0 2px 8px rgba(0,0,0,0.4));
            animation: sway 3s ease-in-out infinite;
        }
        @keyframes sway {
            0%, 100% { transform: rotate(-3deg); }
            50%       { transform: rotate(3deg); }
        }
        .sl-page-title {
            font-size: 1.75em;
            font-weight: 800;
            color: #fff;
            text-shadow: 0 2px 10px rgba(0,0,0,0.4);
            line-height: 1.2;
            letter-spacing: -0.01em;
        }
        .sl-page-subtitle {
            font-size: 0.95em;
            color: rgba(255,255,255,0.75);
            margin-top: 6px;
            font-weight: 400;
        }

        /* ── Card ── */
        .sl-card {
            background: #fff;
            border-radius: 20px;
            width: 100%;
            max-width: 460px;
            box-shadow: 0 8px 40px rgba(0,0,0,0.35);
            overflow: hidden;
            position: relative;
            z-index: 1;
        }
        .sl-card::before {
            content: '';
            display: block;
            height: 5px;
            background: linear-gradient(90deg, #c0392b, #e74c3c 50%, #27ae60);
        }
        .sl-card-inner { padding: 24px 24px 20px; }

        /* ── Success screen ── */
        .sl-success-screen {
            display: none;
            text-align: center;
            padding: 40px 24px 36px;
        }
        .sl-success-screen.visible { display: block; }
        .sl-success-icon {
            font-size: 4em;
            display: block;
            margin-bottom: 12px;
            animation: pop 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        @keyframes pop {
            0%   { transform: scale(0); }
            100% { transform: scale(1); }
        }
        .sl-success-title {
            font-size: 1.3em;
            font-weight: 700;
            color: #1a1a1a;
            margin-bottom: 8px;
        }
        .sl-success-msg {
            font-size: 0.9375em;
            color: #555;
            line-height: 1.6;
            margin-bottom: 20px;
        }
        .sl-add-another {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 12px 24px;
            background: #c0392b;
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 0.9375em;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.15s;
        }
        .sl-add-another:hover { background: #a93226; }

        /* ── Form ── */
        .sl-form-screen { }
        .sl-form-screen.hidden { display: none; }

        /* ── Notice ── */
        .sl-notice {
            border-radius: 10px;
            padding: 11px 14px;
            font-size: 0.875em;
            line-height: 1.55;
            margin-bottom: 16px;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }
        .sl-notice--error   { background: #fdf2f0; border: 1px solid rgba(192,57,43,.3);  color: #922b21; }
        .sl-notice--info    { background: #eaf3fb; border: 1px solid rgba(41,128,185,.3); color: #1a5276; }
        .sl-notice--success { background: #eafaf1; border: 1px solid rgba(39,174,96,.35); color: #1a7a44; }

        /* ── Fields ── */
        .sl-field-row { display: flex; gap: 10px; }
        .sl-field { margin-bottom: 16px; flex: 1; }
        .sl-field--narrow { flex: 0 0 80px; }
        .sl-field label {
            display: block;
            font-size: 0.75em;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #666;
            margin-bottom: 5px;
        }
        .sl-field input[type="text"] {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid rgba(0,0,0,0.13);
            border-radius: 10px;
            font-size: 1em;
            color: #1a1a1a;
            background: #fff;
            transition: border-color 0.15s, box-shadow 0.15s;
            -webkit-appearance: none;
        }
        .sl-field input[type="text"]:focus {
            border-color: #c0392b;
            box-shadow: 0 0 0 3px rgba(192,57,43,0.12);
            outline: none;
        }
        .sl-field input.sl-initial {
            text-align: center;
            font-size: 1.25em;
            font-weight: 700;
        }

        /* ── List choice ── */
        .sl-list-label {
            font-size: 0.75em;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #666;
            margin-bottom: 8px;
        }
        .sl-radio-group { display: flex; gap: 10px; margin-bottom: 16px; }
        .sl-radio {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 13px 10px;
            border: 2px solid rgba(0,0,0,0.1);
            border-radius: 12px;
            cursor: pointer;
            font-size: 0.9375em;
            font-weight: 600;
            transition: border-color 0.15s, background 0.15s, color 0.15s, transform 0.1s;
            user-select: none;
        }
        .sl-radio input[type="radio"] { position: absolute; opacity: 0; width: 0; height: 0; }
        .sl-radio:active { transform: scale(0.97); }
        .sl-radio--nice.selected  { border-color: #27ae60; background: #eafaf1; color: #1a7a44; }
        .sl-radio--naughty.selected { border-color: #c0392b; background: #fdf2f0; color: #922b21; }
        .sl-radio--nice:not(.selected):hover  { border-color: #27ae60; background: #f4fdf7; }
        .sl-radio--naughty:not(.selected):hover { border-color: #c0392b; background: #fef9f8; }

        /* ── Divider ── */
        .sl-divider { border: none; border-top: 1px solid rgba(0,0,0,0.07); margin: 4px 0 16px; }

        /* ── Submit button ── */
        .sl-submit {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #c0392b, #a93226);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 1.0625em;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            letter-spacing: 0.01em;
            box-shadow: 0 4px 14px rgba(192,57,43,0.4);
            transition: opacity 0.15s, transform 0.1s;
        }
        .sl-submit:hover:not(:disabled) { opacity: 0.9; }
        .sl-submit:active:not(:disabled) { transform: scale(0.98); }
        .sl-submit:disabled { background: #ccc; box-shadow: none; cursor: not-allowed; }

        /* ── Footer ── */
        .sl-footer {
            margin-top: 20px;
            text-align: center;
            color: rgba(255,255,255,0.4);
            font-size: 0.75em;
            position: relative;
            z-index: 1;
        }

        /* ── Hide any theme remnants ── */
        #wpadminbar { display: none !important; }

        /* ── Responsive ── */
        @media (max-width: 400px) {
            .sl-page-title { font-size: 1.4em; }
            .sl-card-inner { padding: 18px 16px 16px; }
            .sl-radio { padding: 11px 8px; font-size: 0.875em; }
        }
    </style>
</head>
<body>

<!-- Snowflakes -->
<div class="snowflakes" aria-hidden="true" id="sl-snowflakes"></div>

<!-- Page header -->
<header class="sl-page-header">
    <span class="sl-page-header-icon">🎅</span>
    <h1 class="sl-page-title"><?php echo esc_html( $custom_title ); ?></h1>
    <p class="sl-page-subtitle"><?php echo esc_html( $custom_subtitle ); ?></p>
</header>

<!-- Main card -->
<div class="sl-card">

    <!-- Success screen (hidden until submission) -->
    <div class="sl-success-screen" id="sl-success-screen">
        <span class="sl-success-icon" id="sl-success-icon">🎄</span>
        <div class="sl-success-title" id="sl-success-title">Added to the list!</div>
        <div class="sl-success-msg"  id="sl-success-msg"></div>
        <button class="sl-add-another" id="sl-add-another">➕ Add Another Child</button>
    </div>

    <!-- Form screen -->
    <div class="sl-form-screen sl-card-inner" id="sl-form-screen">

        <div id="sl-notice" style="display:none;"></div>

        <form id="sl-form" novalidate autocomplete="off">

            <div class="sl-field-row">
                <div class="sl-field">
                    <label for="sl-first-name">Child's First Name <span aria-hidden="true">*</span></label>
                    <input type="text" id="sl-first-name" name="first_name"
                           placeholder="e.g. Emma" maxlength="50" />
                </div>
                <div class="sl-field sl-field--narrow">
                    <label for="sl-last-initial">Last Initial <span aria-hidden="true">*</span></label>
                    <input type="text" id="sl-last-initial" name="last_initial"
                           class="sl-initial" placeholder="S" maxlength="1" />
                </div>
            </div>

            <div class="sl-list-label">Which list? <span aria-hidden="true">*</span></div>
            <div class="sl-radio-group">
                <label class="sl-radio sl-radio--nice selected" id="sl-radio-nice">
                    <input type="radio" name="list_type" value="nice" checked />
                    <span>🎄 Nice List</span>
                </label>
                <label class="sl-radio sl-radio--naughty" id="sl-radio-naughty">
                    <input type="radio" name="list_type" value="naughty" />
                    <span>😈 Naughty List</span>
                </label>
            </div>

            <?php if ( $req_code ) : ?>
            <hr class="sl-divider" />
            <div class="sl-field">
                <label for="sl-entry-code">Event Code <span aria-hidden="true">*</span></label>
                <input type="text" id="sl-entry-code" name="entry_code"
                       placeholder="Enter the code from event staff"
                       maxlength="20" inputmode="text" />
            </div>
            <?php endif; ?>

            <input type="hidden" id="sl-latitude"  name="latitude"  value="" />
            <input type="hidden" id="sl-longitude" name="longitude" value="" />

            <button type="submit" class="sl-submit" id="sl-submit">
                <span id="sl-submit-icon">🎅</span>
                <span id="sl-submit-text">Add to Santa's List</span>
            </button>

        </form>
    </div><!-- /.sl-form-screen -->

</div><!-- /.sl-card -->

<footer class="sl-footer">
    Powered by Santa's List &nbsp;·&nbsp; <?php echo esc_html( get_bloginfo( 'name' ) ); ?>
</footer>

<script>
(function () {
    'use strict';

    var REST_URL  = <?php echo wp_json_encode( $rest_url ); ?>;
    var AJAX_URL  = <?php echo wp_json_encode( $ajax_url ); ?>;
    var NONCE     = <?php echo wp_json_encode( $nonce ); ?>;
    var REQ_GEO   = <?php echo $req_geo  ? 'true' : 'false'; ?>;
    var REQ_CODE  = <?php echo $req_code ? 'true' : 'false'; ?>;

    // ── Snowflakes ──────────────────────────────────────────────────────────
    var flakes = ['❄','❅','❆','✻','✼','*'];
    var container = document.getElementById('sl-snowflakes');
    for (var i = 0; i < 18; i++) {
        (function(i) {
            var el = document.createElement('span');
            el.className = 'snowflake';
            el.textContent = flakes[i % flakes.length];
            el.style.left     = (Math.random() * 100) + '%';
            el.style.fontSize = (0.6 + Math.random() * 1.0) + 'em';
            el.style.animationDuration  = (6 + Math.random() * 10) + 's';
            el.style.animationDelay     = (Math.random() * 10) + 's';
            el.style.opacity = (0.3 + Math.random() * 0.5);
            container.appendChild(el);
        })(i);
    }

    // ── Radio cards ─────────────────────────────────────────────────────────
    document.querySelectorAll('input[name="list_type"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            document.getElementById('sl-radio-nice').classList.toggle('selected', this.value === 'nice');
            document.getElementById('sl-radio-naughty').classList.toggle('selected', this.value === 'naughty');
        });
    });
    document.querySelectorAll('.sl-radio').forEach(function (label) {
        label.addEventListener('click', function () {
            var radio = this.querySelector('input[type="radio"]');
            radio.checked = true;
            radio.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });

    // ── Auto-format inputs ──────────────────────────────────────────────────
    document.getElementById('sl-last-initial').addEventListener('input', function () {
        this.value = this.value.toUpperCase().replace(/[^A-Z]/g, '').substring(0, 1);
    });
    document.getElementById('sl-first-name').addEventListener('blur', function () {
        var v = this.value.trim();
        if (v) this.value = v.charAt(0).toUpperCase() + v.slice(1).toLowerCase();
    });

    // ── Helpers ─────────────────────────────────────────────────────────────
    function showNotice(msg, type) {
        var el = document.getElementById('sl-notice');
        el.style.display = 'flex';
        el.className = 'sl-notice sl-notice--' + type;
        el.innerHTML = msg;
    }
    function hideNotice() {
        document.getElementById('sl-notice').style.display = 'none';
    }
    function setLoading(on) {
        var btn  = document.getElementById('sl-submit');
        var icon = document.getElementById('sl-submit-icon');
        var txt  = document.getElementById('sl-submit-text');
        btn.disabled = on;
        icon.textContent = on ? '⏳' : '🎅';
        txt.textContent  = on ? 'Adding to the list…' : "Add to Santa's List";
    }

    function getLocation() {
        return new Promise(function (resolve, reject) {
            if (!navigator.geolocation) {
                reject(new Error('Geolocation is not supported by your browser.'));
                return;
            }
            showNotice('📍 Getting your location…', 'info');
            navigator.geolocation.getCurrentPosition(
                function (pos) { resolve(pos.coords); },
                function ()    { reject(new Error('Location access was denied. Please allow location and try again.')); },
                { timeout: 10000, maximumAge: 60000 }
            );
        });
    }

    function doFetch(formData) {
        return new Promise(function (resolve) {
            // Try REST first
            fetch(REST_URL + '/submit', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(formData),
            })
            .then(function (r) { return r.json().then(function(j){ return { status: r.status, body: j }; }); })
            .then(function (r) {
                if (r.status === 403 || r.status === 401) {
                    // Fall back to wp_ajax
                    return ajaxFallback(formData);
                }
                resolve(r.body);
            })
            .catch(function () {
                ajaxFallback(formData).then(resolve);
            });

            function ajaxFallback(fd) {
                var params = new URLSearchParams({
                    action:       'santa_list_submit',
                    nonce:        fd.nonce,
                    first_name:   fd.first_name,
                    last_initial: fd.last_initial,
                    list_type:    fd.list_type,
                    entry_code:   fd.entry_code  || '',
                    latitude:     fd.latitude    || '',
                    longitude:    fd.longitude   || '',
                });
                return fetch(AJAX_URL, { method: 'POST', body: params })
                    .then(function (r) { return r.json(); })
                    .then(function (r) {
                        return r.success
                            ? r.data
                            : { success: false, message: (r.data && r.data.message) || 'Something went wrong.' };
                    })
                    .catch(function () {
                        return { success: false, message: 'Could not reach the server. Please try again.' };
                    });
            }
        });
    }

    // ── Form submission ──────────────────────────────────────────────────────
    document.getElementById('sl-form').addEventListener('submit', function (e) {
        e.preventDefault();
        hideNotice();

        var firstName   = document.getElementById('sl-first-name').value.trim();
        var lastInitial = document.getElementById('sl-last-initial').value.trim().toUpperCase();
        var listType    = document.querySelector('input[name="list_type"]:checked').value;
        var entryCode   = REQ_CODE ? (document.getElementById('sl-entry-code') || {value:''}).value.trim() : '';

        if (!firstName || !lastInitial) {
            showNotice('Please enter the child\'s first name and last initial.', 'error');
            return;
        }
        if (!/^[A-Za-z]+$/.test(firstName)) {
            showNotice('First name may only contain letters.', 'error');
            return;
        }
        if (!/^[A-Za-z]$/.test(lastInitial)) {
            showNotice('Last initial must be a single letter.', 'error');
            return;
        }
        if (REQ_CODE && !entryCode) {
            showNotice('Please enter the event code.', 'error');
            return;
        }

        setLoading(true);

        var formData = {
            first_name:   firstName,
            last_initial: lastInitial,
            list_type:    listType,
            entry_code:   entryCode,
            nonce:        NONCE,
        };

        function submit(coords) {
            if (coords) {
                formData.latitude  = coords.latitude;
                formData.longitude = coords.longitude;
            }
            doFetch(formData).then(function (res) {
                setLoading(false);
                if (res.success) {
                    showSuccess(res.message, listType);
                } else {
                    showNotice(res.message || 'Something went wrong. Please try again.', 'error');
                }
            });
        }

        if (REQ_GEO) {
            getLocation()
                .then(function (coords) { submit(coords); })
                .catch(function (err) {
                    setLoading(false);
                    showNotice(err.message, 'error');
                });
        } else {
            submit(null);
        }
    });

    // ── Success screen ───────────────────────────────────────────────────────
    function showSuccess(message, listType) {
        document.getElementById('sl-form-screen').classList.add('hidden');
        var screen = document.getElementById('sl-success-screen');
        screen.classList.add('visible');
        document.getElementById('sl-success-icon').textContent = listType === 'nice' ? '🎄' : '😈';
        document.getElementById('sl-success-title').textContent = listType === 'nice'
            ? 'On the Nice List! 🎉'
            : 'On the Naughty List! 😈';
        document.getElementById('sl-success-msg').textContent = message;
    }

    document.getElementById('sl-add-another').addEventListener('click', function () {
        document.getElementById('sl-form-screen').classList.remove('hidden');
        document.getElementById('sl-success-screen').classList.remove('visible');
        document.getElementById('sl-form').reset();
        document.getElementById('sl-radio-nice').classList.add('selected');
        document.getElementById('sl-radio-naughty').classList.remove('selected');
        hideNotice();
        document.getElementById('sl-first-name').focus();
    });

})();
</script>

<?php wp_footer(); ?>
</body>
</html>
