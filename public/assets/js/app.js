/* =========================================================
   Madok (মাদক)
   Clean frontend controller
   PHP + MySQL backend
   Leaflet maps
   ========================================================= */


/* =========================================================
   CONFIG
   ========================================================= */

const DEFAULT_CENTER = [23.8103, 90.4125];
const DEFAULT_ZOOM = 12;

const LOCATION_API = "api/locations.php";
const STATISTICS_API = "api/statistics.php";
const REPORT_API = "api/report.php";
const REPORTS_API = "api/reports.php";
const SECURITY_API = "api/security.php";

let csrfToken = "";
let turnstileToken = "";
let turnstileWidgetId = null;

function resetTurnstile() {
    turnstileToken = "";
    if (turnstileWidgetId !== null && window.turnstile) {
        window.turnstile.reset(turnstileWidgetId);
    }
}

async function initSecurity() {
    const response = await fetch(SECURITY_API, {
        headers: { Accept: "application/json" },
        cache: "no-store"
    });
    const security = await response.json();
    if (!response.ok || !security.ok || !security.csrf_token) {
        throw new Error("Security initialization failed");
    }

    csrfToken = security.csrf_token;
    const csrfInput = document.getElementById("csrfToken");
    if (csrfInput) csrfInput.value = csrfToken;

    /* Localhost test mode: use session token; do not render Cloudflare widget. */
    if (security.turnstile?.local_test && security.local_turnstile_token) {
        turnstileToken = security.local_turnstile_token;
        return;
    }

    if (!security.turnstile?.enabled || !security.turnstile.site_key) return;

    const widget = document.getElementById("turnstileWidget");
    if (!widget) return;
    widget.classList.remove("hidden");

    await new Promise((resolve, reject) => {
        if (window.turnstile) return resolve();
        const script = document.createElement("script");
        script.src = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
        script.async = true;
        script.defer = true;
        script.onload = resolve;
        script.onerror = () => reject(new Error("Turnstile failed to load"));
        document.head.appendChild(script);
    });

    turnstileWidgetId = window.turnstile.render(widget, {
        sitekey: security.turnstile.site_key,
        action: "report",
        callback: (token) => { turnstileToken = token; },
        "expired-callback": () => { turnstileToken = ""; },
        "error-callback": () => { turnstileToken = ""; }
    });
}

const MAX_IMAGE_SIZE = 5 * 1024 * 1024;


/* =========================================================
   APPLICATION STATE
   ========================================================= */

const demoLocations = [];
const stationData = [];

let map = null;
let locationPickerMap = null;

let mapMarkers = [];

let selectedMapMarker = null;
let userLocationMarker = null;
let mainUserLocationMarker = null;
let locationPickerMarker = null;

let selectedMapLocation = null;

let currentFilter = "all";

let toastTimer = null;

let reportModal = null;
let reportForm = null;

let latitudeInput = null;
let longitudeInput = null;

let selectedLocation = null;

let imageInput = null;
let imagePreview = null;
let previewImage = null;
let imageName = null;

let mapSelectModal = null;
let openMapSelectBtn = null;
let closeMapSelectBtn = null;
let confirmMapLocationBtn = null;
let pickerCoordinates = null;
let locationPickerSearchInput = null;
let locationPickerSearchResults = null;
let locationPickerSearchTimer = null;

let contactYes = null;
let contactNo = null;
let contactInputWrapper = null;
let contactInfo = null;

let submitButton = null;
let reportDivisionSelect = null;
let stationDivision = "all";
let reportDistrictSelect = null;
let stationDistrict = "all";
let stationTableExpanded = false;


/* =========================================================
   DOM CACHE
   ========================================================= */

function cacheDom() {

    reportModal =
        document.getElementById(
            "reportModal"
        );

    reportForm =
        document.getElementById(
            "reportForm"
        );

    latitudeInput =
        document.getElementById(
            "latitude"
        );

    longitudeInput =
        document.getElementById(
            "longitude"
        );

    selectedLocation =
        document.getElementById(
            "selectedLocation"
        );

    imageInput =
        document.getElementById(
            "reportImage"
        );

    imagePreview =
        document.getElementById(
            "imagePreview"
        );

    previewImage =
        document.getElementById(
            "previewImage"
        );

    imageName =
        document.getElementById(
            "imageName"
        );

    mapSelectModal =
        document.getElementById(
            "mapSelectModal"
        );

    openMapSelectBtn =
        document.getElementById(
            "openMapSelectBtn"
        );

    closeMapSelectBtn =
        document.getElementById(
            "closeMapSelectBtn"
        );

    confirmMapLocationBtn =
        document.getElementById(
            "confirmMapLocationBtn"
        );

    pickerCoordinates =
        document.getElementById(
            "pickerCoordinates"
        );

    locationPickerSearchInput =
        document.getElementById(
            "locationPickerSearch"
        );

    locationPickerSearchResults =
        document.getElementById(
            "locationPickerSearchResults"
        );


    contactYes =
        document.getElementById(
            "contactYes"
        );

    contactNo =
        document.getElementById(
            "contactNo"
        );

    contactInputWrapper =
        document.getElementById(
            "contactInputWrapper"
        );

    contactInfo =
        document.getElementById(
            "contactInfo"
        );

    reportDivisionSelect =
        document.getElementById(
            "reportDivisionSelect"
        );

    reportDistrictSelect =
        document.getElementById(
            "reportDistrictSelect"
        );


    submitButton = reportForm
        ? reportForm.querySelector(
            'button[type="submit"]'
        )
        : null;
}


/* =========================================================
   SAFE TEXT
   ========================================================= */

function escapeHtml(value) {

    return String(value ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
}


/* =========================================================
   NUMBER HELPERS
   ========================================================= */

function numberValue(value) {

    const number = Number(value);

    return Number.isFinite(number)
        ? number
        : 0;
}


function formatNumber(value) {

    return numberValue(value)
    .toLocaleString("en-US");
}


/* =========================================================
   MAP TYPE HELPERS
   ========================================================= */

function getTypeLabel(type) {

    if (type === "sale") {
        return "মাদক বেচাকেনা";
    }

    if (type === "use") {
        return "মাদক সেবন";
    }

    return "উভয়";
}


function getTypeBackground(type) {

    if (type === "sale") {
        return "#fee2e2";
    }

    if (type === "use") {
        return "#fef3c7";
    }

    return "#dcfce7";
}


function getTypeTextColor(type) {

    if (type === "sale") {
        return "#b91c1c";
    }

    if (type === "use") {
        return "#a16207";
    }

    return "#15803d";
}


function getTypeColor(type) {

    if (type === "sale") {
        return "#ef4444";
    }

    if (type === "use") {
        return "#facc15";
    }

    return "#22c55e";
}

function buildLocationPopup(location) {
    const stationText = location.station || "থানা / উপজেলা নির্ধারণ করা হয়নি";
    const reportCount = numberValue(location.reports);
    const detailUrl = "reports/" + encodeURIComponent(location.id);

    return `
        <div style="min-width:210px;font-family:'Noto Sans Bengali',Arial,sans-serif;">
            <div style="display:inline-block;padding:3px 7px;border-radius:5px;background:${getTypeBackground(location.type)};color:${getTypeTextColor(location.type)};font-size:10px;font-weight:700;margin-bottom:7px;">
                ${escapeHtml(getTypeLabel(location.type))}
            </div>
            <strong style="display:block;font-size:13px;line-height:1.5;">
                ${escapeHtml(location.title)}
            </strong>
            <p style="margin:5px 0 0;color:#666;font-size:10px;line-height:1.5;">
                ${escapeHtml(location.description || "বিস্তারিত তথ্য দেওয়া হয়নি।")}
            </p>
            <span style="display:block;color:#777;font-size:10px;margin-top:5px;">
                ${escapeHtml(stationText)} . ${escapeHtml(location.district || "জেলা নির্ধারণ করা হয়নি")} . ${escapeHtml(location.division || "বিভাগ নির্ধারণ করা হয়নি")}
            </span>
            <div style="margin-top:8px;padding-top:7px;border-top:1px solid #eee;color:#666;font-size:10px;">
                মোট রিপোর্ট: <strong>${formatNumber(reportCount)}</strong>
                <a href="${detailUrl}" style="float:right;color:#5b46e8;font-weight:700;text-decoration:none;">সব রিপোর্ট দেখুন</a>
            </div>
        </div>
    `;
}


/* =========================================================
   MARKER ICON
   ========================================================= */

function createMarkerIcon(type) {

    const color =
        getTypeColor(type);

    const symbol =
        type === "sale"
            ? "↗"
            : "●";

    return L.divIcon({

        className: "",

        html: `
            <div
                class="custom-marker"
                style="background:${color}"
            >
                <span>${symbol}</span>
            </div>
        `,

        iconSize: [35, 35],

        iconAnchor: [17, 35],

        popupAnchor: [0, -34]
    });
}

function initMap() {
    const mapElement =
        document.getElementById("map");

    if (!mapElement) {

        console.warn(
            "[Madok] #map not found."
        );

        return;
    }


    if (map) {
        return;
    }


    if (
        typeof L === "undefined"
    ) {

        console.error(
            "[Madok] Leaflet is not loaded."
        );

        return;
    }


    map = L.map(
        mapElement,
        {
            zoomControl: true,
            attributionControl: true
        }
    ).setView(
        DEFAULT_CENTER,
        DEFAULT_ZOOM
    );


    L.tileLayer(
        "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
        {
            maxZoom: 19,

            attribution:
                "&copy; OpenStreetMap"
        }
    ).addTo(map);


    map.on(
        "click",
        function(event) {

            const mapLocationMethod = document.querySelector(
                'input[name="locationMethod"][value="map"]'
            );
            if (mapLocationMethod) {
                mapLocationMethod.checked = true;
            }

            setSelectedLocation(
                event.latlng.lat,
                event.latlng.lng
            );

            openReportModal();
        }
    );


    setTimeout(
        function() {

            if (map) {
                map.invalidateSize();
            }

        },
        150
    );


    renderMarkers();
}


/* =========================================================
   MAIN MAP MARKERS
   ========================================================= */

function renderMarkers() {

    if (!map) {
        return;
    }


    mapMarkers.forEach(
        function(marker) {

            try {
                map.removeLayer(marker);
            } catch (error) {
                // Ignore already removed marker.
            }
        }
    );


    mapMarkers = [];


    const filtered =
        demoLocations.filter(
            function(location) {

                if (
                    currentFilter === "all"
                ) {
                    return true;
                }


                if (
                    currentFilter === "sale"
                ) {

                    return (
                        location.type === "sale" ||
                        location.type === "both" ||
                        numberValue(
                            location.sale_count
                        ) > 0
                    );
                }


                if (
                    currentFilter === "use"
                ) {

                    return (
                        location.type === "use" ||
                        location.type === "both" ||
                        numberValue(
                            location.use_count
                        ) > 0
                    );
                }


                return true;
            }
        );


    filtered.forEach(
        function(location) {

            const lat =
                numberValue(location.lat);

            const lng =
                numberValue(location.lng);


            if (
                lat < -90 ||
                lat > 90 ||
                lng < -180 ||
                lng > 180
            ) {
                return;
            }


            const marker =
                L.marker(
                    [lat, lng],
                    {
                        icon:
                            createMarkerIcon(
                                location.type
                            )
                    }
                ).addTo(map);


            const stationText =
                            location.station || "Station unavailable";


            const reportCount =
                numberValue(
                    location.reports
                );

            const detailUrl =
                "reports/" +
                encodeURIComponent(location.id);


            marker.bindPopup(`
                <div
                    style="
                        min-width:210px;
                        font-family:
                            'Noto Sans Bengali',
                            Arial,
                            sans-serif;
                    "
                >

                    <div
                        style="
                            display:inline-block;
                            padding:3px 7px;
                            border-radius:5px;
                            background:
                                ${getTypeBackground(
                                    location.type
                                )};
                            color:
                                ${getTypeTextColor(
                                    location.type
                                )};
                            font-size:10px;
                            font-weight:700;
                            margin-bottom:7px;
                        "
                    >
                        ${escapeHtml(
                            getTypeLabel(
                                location.type
                            )
                        )}
                    </div>


                    <strong
                        style="
                            display:block;
                            font-size:13px;
                            line-height:1.5;
                        "
                    >
                        ${escapeHtml(
                            location.title
                        )}
                    </strong>

                    <p style="margin:5px 0 0;color:#666;font-size:10px;line-height:1.5;">
                        ${escapeHtml(location.description || "বিস্তারিত তথ্য দেওয়া হয়নি।")}
                    </p>


                    <span
                        style="
                            display:block;
                            color:#777;
                            font-size:10px;
                            margin-top:5px;
                        "
                    >
                            ${escapeHtml(stationText)} .
                            ${escapeHtml(location.district || "District unavailable")} .
                            ${escapeHtml(location.division || "Division unavailable")}
                    </span>


                    <div
                        style="
                            margin-top:8px;
                            padding-top:7px;
                            border-top:
                                1px solid #eee;
                            color:#666;
                            font-size:10px;
                        "
                    >
                        মোট রিপোর্ট:
                        <strong>
                            ${formatNumber(
                                reportCount
                            )}
                        </strong>
                        <a href="${detailUrl}" style="float:right;color:#5b46e8;font-weight:700;text-decoration:none;">
                            সব রিপোর্ট দেখুন
                        </a>
                    </div>

                </div>
            `);


            marker.on(
                "click",
                function() {

                    selectedMapMarker =
                        marker;
                }
            );


            mapMarkers.push(marker);
        }
    );
}


/* =========================================================
   LOAD LOCATIONS
   ========================================================= */

async function loadMapLocations() {

    console.log(
        "[Madok] Loading locations..."
    );


    const response =
        await fetch(
            `${LOCATION_API}?_=${Date.now()}`,
            {
                method: "GET",

                headers: {
                    Accept:
                        "application/json"
                },

                cache: "no-store"
            }
        );


    const text =
        await response.text();


    console.log(
        "[Madok] Locations HTTP:",
        response.status
    );


    if (!response.ok) {

        throw new Error(
            `Locations API HTTP ${response.status}`
        );
    }


    let result;


    try {

        result =
            JSON.parse(text);

    } catch (error) {

        console.error(
            "[Madok] Invalid locations JSON:",
            text
        );

        throw new Error(
            "Locations API valid JSON return করছে না।"
        );
    }


    if (
        !result ||
        result.ok !== true
    ) {

        throw new Error(
            result?.message ||
            "Locations load failed."
        );
    }


    demoLocations.length = 0;


    if (
        Array.isArray(
            result.locations
        )
    ) {

        result.locations.forEach(
            function(location) {

                const lat =
                    Number(location.lat);

                const lng =
                    Number(location.lng);


                if (
                    !Number.isFinite(lat) ||
                    !Number.isFinite(lng)
                ) {

                    console.warn(
                        "[Madok] Invalid location skipped:",
                        location
                    );

                    return;
                }


                demoLocations.push({

                    ...location,

                    lat,

                    lng,

                    reports:
                        numberValue(
                            location.reports
                        ),

                    description:
                        String(location.description || ""),

                    use_count:
                        numberValue(
                            location.use_count
                        ),

                    sale_count:
                        numberValue(
                            location.sale_count
                        )
                });
            }
        );
    }


    console.log(
        `[Madok] ${demoLocations.length} locations loaded.`
    );


    renderMarkers();


    return result;
}


/* =========================================================
   LOAD STATISTICS
   ========================================================= */

async function loadBackendData() {

    console.log(
        "[Madok] Loading statistics..."
    );


    const response =
        await fetch(
            `${STATISTICS_API}?_=${Date.now()}`,
            {
                method: "GET",

                headers: {
                    Accept:
                        "application/json"
                },

                cache: "no-store"
            }
        );


    const text =
        await response.text();

        // console.log(text, 'texxxxxxxxxxxxxxxxxxxxxxxt')


    console.log(
        "[Madok] Statistics HTTP:",
        response.status
    );


    if (!response.ok) {

        throw new Error(
            `Statistics API HTTP ${response.status}`
        );
    }


    let result;


    try {

        result =
            JSON.parse(text);

    } catch (error) {

        console.error(
            "[Madok] Invalid statistics JSON:",
            text
        );

        throw new Error(
            "Statistics API valid JSON return করছে না।"
        );
    }


    if (
        !result ||
        result.ok !== true
    ) {

        throw new Error(
            result?.message ||
            "Statistics load failed."
        );
    }


    stationData.length = 0;


    if (
        Array.isArray(
            result.stations
        )
    ) {

        result.stations.forEach(
            function(item) {

                stationData.push({

                    ...item,

                    sale:
                        numberValue(
                            item.sale
                        ),

                    use:
                        numberValue(
                            item.use
                        ),

                    total:
                        numberValue(
                            item.total
                        )
                });
            }
        );
    }


    const statistics =
        result.statistics || {};


    window.madokStatistics = {

        total_reports:
            numberValue(
                statistics.total_reports
            ),

        use_reports:
            numberValue(
                statistics.use_reports
            ),

        sale_reports:
            numberValue(
                statistics.sale_reports
            ),

        total_locations:
            numberValue(
                statistics.total_locations
            ),

        use_locations:
            numberValue(
                statistics.use_locations
            ),

        sale_locations:
            numberValue(
                statistics.sale_locations
            ),

        both_locations:
            numberValue(
                statistics.both_locations
            ),

        total_stations:
            numberValue(
                statistics.total_stations
            )
    };


    console.log(
        "[Madok] Statistics:",
        window.madokStatistics
    );


    updateStatisticsUI(
        window.madokStatistics,
        true
    );

    renderLocationHighlights(result, true);

    populateReportDistricts();
    renderFilteredStations();

    return result;
}


/* =========================================================
   UPDATE STATISTICS UI
   ========================================================= */

function updateStatisticsUI(stats, updateHero = true) {

    const totalLocations =
        document.getElementById(
            "totalLocations"
        );

    const saleLocations =
        document.getElementById(
            "saleLocations"
        );

    const useLocations =
        document.getElementById(
            "useLocations"
        );

    const totalReports =
        document.getElementById(
            "totalReports"
        );

    const heroReportedLocations =
        document.getElementById(
            "heroReportedLocations"
        );

    const heroPoliceStations =
        document.getElementById(
            "heroPoliceStations"
        );

    const heroTotalReports =
        document.getElementById(
            "heroTotalReports"
        );


    if (totalLocations) {

        totalLocations.textContent =
            formatNumber(
                stats.total_locations
            );
    }


    if (saleLocations) {

        saleLocations.textContent =
            formatNumber(
                stats.sale_locations
            );
    }


    if (useLocations) {

        useLocations.textContent =
            formatNumber(
                stats.use_locations
            );
    }


    if (totalReports) {

        totalReports.textContent =
            formatNumber(
                stats.total_reports
            );
    }


    if (updateHero && heroReportedLocations) {

        heroReportedLocations.textContent =
            formatNumber(
                stats.total_locations
            );
    }


    if (updateHero && heroPoliceStations) {

        heroPoliceStations.textContent =
            formatNumber(
                stats.total_stations
            );
    }

    if (updateHero && heroTotalReports) {
        heroTotalReports.textContent =
            formatNumber(stats.total_reports);
    }
}

function renderLocationHighlights(result, resetVisible = false) {
    const groups = [
        ["lastReportedLocations", result.last_reported_locations || []],
        ["mostReportedLocations", result.most_reported_locations || []]
    ];

    groups.forEach(([elementId, locations]) => {
        const container = document.getElementById(elementId);
        if (!container) {
            return;
        }

        if (resetVisible || !container.dataset.visibleCount) {
            container.dataset.visibleCount = "2";
        }

        const visibleCount = Number(container.dataset.visibleCount || 2);
        const visibleLocations = locations.slice(0, visibleCount);
        const canToggle = locations.length > 1;

        container.innerHTML = visibleLocations.length
            ? visibleLocations.map(location => `
                <a href="reports/${encodeURIComponent(location.id)}" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm block">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <span class="inline-block rounded-md px-2 py-1 text-[10px] font-bold" style="background:${getTypeBackground(location.type)};color:${getTypeTextColor(location.type)}">
                                ${escapeHtml(getTypeLabel(location.type))}
                            </span>
                            <h4 class="mt-2 truncate text-sm font-extrabold">${escapeHtml(location.title)}</h4>
                            <p class="mt-1 text-xs text-slate-500">${escapeHtml(location.station || "থানা / উপজেলা নির্ধারণ করা হয়নি")} . ${escapeHtml(location.district || "জেলা নির্ধারণ করা হয়নি")} . ${escapeHtml(location.division || "বিভাগ নির্ধারণ করা হয়নি")}</p>
                        </div>
                        <div class="flex flex-col items-end">
                            <strong class="shrink-0 text-sm text-red-500">${formatNumber(location.reports)} রিপোর্ট</strong>
                            <span class="mt-3 inline-block text-xs font-bold text-primary hover:underline">সব রিপোর্ট দেখুন</span>
                        </div>
                        
                    </div>
                </a>
            `).join("") + (canToggle ? `
                <button type="button" class="highlight-toggle mt-1 text-xs font-bold text-primary hover:underline" data-target="${elementId}">
                    ${visibleCount === 2 ? "সবগুলো দেখুন" : "শুধু ১টি দেখুন"}
                </button>
            ` : "")
            : '<p class="rounded-xl border border-dashed border-slate-300 p-4 text-sm text-slate-500">এখনও কোনো রিপোর্ট পাওয়া যায়নি।</p>';

        const toggleButton = container.querySelector(".highlight-toggle");
        if (toggleButton) {
            toggleButton.addEventListener("click", () => {
                container.dataset.visibleCount = visibleCount === 2 ? String(locations.length) : "2";
                renderLocationHighlights(result);
            });
        }
    });
}


/* =========================================================
   MAIN MAP CURRENT LOCATION
   ========================================================= */

function restoreLocateButton(button, originalHtml = null) {
    if (!button) return;
    button.disabled = false;
    button.classList.remove("cursor-wait", "opacity-70");
    if (originalHtml !== null) button.innerHTML = originalHtml;
}

function locateUser(targetId = "map") {

    if (
        !navigator.geolocation
    ) {

        showToast(
            "লোকেশন পাওয়া যাচ্ছে না",
            "আপনার browser geolocation support করে না।"
        );

        return;
    }


    const button = document.getElementById(targetId === "hero" ? "locateMeBtn" : "mapLocateBtn");
    const originalHtml = button?.innerHTML || null;


    if (button) {
        button.disabled = true;
        button.classList.add("cursor-wait", "opacity-70");
        if (targetId === "hero") {
            button.textContent = "আপনার লোকেশন লোড হচ্ছে";
        } else {
            button.innerHTML = '<span class="animate-spin rounded-full border-[3px] border-white/40 border-t-white p-1" aria-label="লোড হচ্ছে"></span>';
        }
    }


    navigator.geolocation.getCurrentPosition(

        function(position) {

            const lat =
                Number(
                    position.coords.latitude
                );

            const lng =
                Number(
                    position.coords.longitude
                );


            if (
                !Number.isFinite(lat) ||
                !Number.isFinite(lng)
            ) {

                showToast(
                    "লোকেশন পাওয়া যায়নি",
                    "সঠিক coordinates পাওয়া যায়নি।"
                );

                restoreLocateButton(button, originalHtml);
                return;
            }            


            /*
             * Remove old marker
             */

            if (
                userLocationMarker
            ) {

                try {

                    map.removeLayer(
                        userLocationMarker
                    );

                } catch (
                    error
                ) {

                    console.warn(
                        error
                    );
                }

                userLocationMarker =
                    null;
            }


            /*
             * Add current location
             */

            userLocationMarker =
                L.circleMarker(

                    [lat, lng],

                    {
                        radius: 9,

                        color: "#ffffff",

                        weight: 3,

                        fillColor: "#5b46e8",

                        fillOpacity: 1
                    }

                ).addTo(
                    map
                );


            userLocationMarker.bindPopup(
                "আপনার বর্তমান অবস্থান"
            );


            /*
             * CENTER HERO MAP
             */

            map.setView(

                [lat, lng],

                16,

                {
                    animate: true
                }
            );

            if (map) {
                if (mainUserLocationMarker) {
                    map.removeLayer(mainUserLocationMarker);
                }

                mainUserLocationMarker = L.circleMarker(
                    [lat, lng],
                    {
                        radius: 9,
                        color: "#ffffff",
                        weight: 3,
                        fillColor: "#5b46e8",
                        fillOpacity: 1
                    }
                ).addTo(map);

                map.setView([lat, lng], 16, {animate: true});
            }


            setTimeout(
                function() {

                    if (
                        userLocationMarker
                    ) {

                        userLocationMarker
                            .openPopup();
                    }

                },
                500
            );

            document.getElementById(targetId)?.scrollIntoView({
                behavior: "smooth",
                block: "center"
            });


            showToast(
                "লোকেশন পাওয়া গেছে",
                "আপনার অবস্থান Hero Map-এ দেখানো হয়েছে।"
            );

            restoreLocateButton(button, originalHtml);
        },


        function(error) {

            console.error(
                "[Madok] Geolocation error:",
                error
            );


            restoreLocateButton(button, originalHtml);


            let message =
                "আপনার বর্তমান লোকেশন পাওয়া যায়নি।";


            if (error.code === 1) {

                message =
                    "Browser location permission দিন।";

            } else if (error.code === 2) {

                message =
                    "বর্তমান অবস্থান পাওয়া যাচ্ছে না।";

            } else if (error.code === 3) {

                message =
                    "লোকেশন পেতে সময় শেষ হয়েছে।";
            }


            showToast(
                "লোকেশন পাওয়া যায়নি",
                message
            );
        },


        {
            enableHighAccuracy: true,

            timeout: 15000,

            maximumAge: 30000
        }
    );
}

function locateUserOnMainMap(event) {
    locateUser(event?.currentTarget?.id === "locateMeBtn" ? "hero" : "map");
}


/* =========================================================
   REPORT FORM LOCATION
   ========================================================= */

function getCurrentLocationForReport() {

    if (
        !navigator.geolocation
    ) {

        showToast(
            "লোকেশন পাওয়া যাচ্ছে না",
            "আপনার browser geolocation support করে না।"
        );

        return;
    }


    const button =
        document.getElementById(
            "getLocationBtn"
        );


    if (button) {
        const locationOption = button.querySelector(
            'input[name="locationMethod"]'
        );
        if (locationOption) {
            locationOption.checked = true;
        }
    }


    navigator.geolocation.getCurrentPosition(

        function(position) {

            const lat =
                position.coords.latitude;

            const lng =
                position.coords.longitude;


            setSelectedLocation(
                lat,
                lng
            );


            restoreReportLocationButton();


            showToast(
                "লোকেশন পাওয়া গেছে",
                "আপনার বর্তমান লোকেশন রিপোর্টের জন্য নির্বাচন করা হয়েছে।"
            );
        },


        function(error) {

            console.error(
                "[Madok] Report geolocation error:",
                error
            );


            restoreReportLocationButton();


            const message = error.code === 1
                ? "Browser location permission দিন।"
                : error.code === 3
                    ? "লোকেশন পেতে সময় শেষ হয়েছে।"
                    : "বর্তমান অবস্থান পাওয়া যাচ্ছে না। ম্যাপ থেকে লোকেশন নির্বাচন করুন।";

            showToast("লোকেশন পাওয়া যায়নি", message);
        },


        {
            enableHighAccuracy: true,

            timeout: 15000,

            maximumAge: 30000
        }
    );
}


function restoreReportLocationButton() {
}


/* =========================================================
   SET REPORT LOCATION
   ========================================================= */

function setSelectedLocation(
    lat,
    lng
) {

    lat = Number(lat);
    lng = Number(lng);


    if (
        !Number.isFinite(lat) ||
        !Number.isFinite(lng)
    ) {

        return;
    }

    const selectedLocationMethod = document.querySelector(
        'input[name="locationMethod"]:checked'
    );
    if (!selectedLocationMethod) {
        const mapLocationMethod = document.querySelector(
            'input[name="locationMethod"][value="map"]'
        );
        if (mapLocationMethod) {
            mapLocationMethod.checked = true;
        }
    }


    if (latitudeInput) {

        latitudeInput.value =
            lat.toFixed(6);
    }


    if (longitudeInput) {

        longitudeInput.value =
            lng.toFixed(6);
    }


    if (selectedLocation) {

        selectedLocation.innerHTML = `
            <span>📍</span>

            <span>
                নির্বাচিত লোকেশন:
                <strong>
                    ${lat.toFixed(6)},
                    ${lng.toFixed(6)}
                </strong>
            </span>
        `;
    }


    /*
     * Show temporary selected location
     * on the main map.
     */

    if (map) {

        if (selectedMapMarker) {

            try {
                map.removeLayer(
                    selectedMapMarker
                );
            } catch (error) {
                // Ignore.
            }
        }


        selectedMapMarker =
            L.marker(
                [lat, lng],
                {
                    icon:
                        L.divIcon({

                            className: "",

                            html: `
                                <div
                                    style="
                                        width:20px;
                                        height:20px;
                                        border:
                                            4px solid white;
                                        border-radius:50%;
                                        background:
                                            #5b46e8;
                                        box-shadow:
                                            0 3px 12px
                                            rgba(0,0,0,.3);
                                    "
                                ></div>
                            `,

                            iconSize: [20, 20],

                            iconAnchor: [10, 10]
                        })
                }
            ).addTo(map);
    }
}


/* =========================================================
   REPORT MODAL
   ========================================================= */

function openReportModal() {

    if (!reportModal) {
        return;
    }


    reportModal.classList.remove(
        "hidden"
    );

    reportModal.classList.add(
        "open"
    );

    reportModal.classList.add(
        "modal-open"
    );


    reportModal.setAttribute(
        "aria-hidden",
        "false"
    );


    document.body.style.overflow =
        "hidden";
}


function closeReportModal() {

    if (!reportModal) {
        return;
    }


    reportModal.classList.remove(
        "open"
    );

    reportModal.classList.remove(
        "modal-open"
    );

    reportModal.classList.add(
        "hidden"
    );


    reportModal.setAttribute(
        "aria-hidden",
        "true"
    );


    document.body.style.overflow =
        "";
}


/* =========================================================
   LOCATION PICKER MODAL
   ========================================================= */

function initLocationPickerMap() {

    if (locationPickerMap) {
        return;
    }


    const element =
        document.getElementById(
            "locationPickerMap"
        );


    if (!element) {

        console.warn(
            "[Madok] #locationPickerMap not found."
        );

        return;
    }


    locationPickerMap =
        L.map(
            element
        ).setView(
            DEFAULT_CENTER,
            13
        );


    L.tileLayer(
        "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
        {
            maxZoom: 19,

            attribution:
                "&copy; OpenStreetMap"
        }
    ).addTo(
        locationPickerMap
    );


    locationPickerMap.on(
        "click",
        function(event) {

            selectMapLocation(
                event.latlng.lat,
                event.latlng.lng
            );
        }
    );
}


/* =========================================================
   SELECT PICKER LOCATION
   ========================================================= */

function selectMapLocation(
    lat,
    lng
) {

    lat = Number(lat);
    lng = Number(lng);


    if (
        !Number.isFinite(lat) ||
        !Number.isFinite(lng)
    ) {

        return;
    }


    selectedMapLocation = {
        lat,
        lng
    };


    if (
        locationPickerMarker &&
        locationPickerMap
    ) {

        try {

            locationPickerMap.removeLayer(
                locationPickerMarker
            );

        } catch (error) {
            // Ignore.
        }
    }


    locationPickerMarker =
        L.marker(
            [lat, lng]
        ).addTo(
            locationPickerMap
        );


    locationPickerMap.setView(
        [lat, lng],
        16,
        {
            animate: true
        }
    );


    if (pickerCoordinates) {

        pickerCoordinates.textContent =
            `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
    }


    if (confirmMapLocationBtn) {

        confirmMapLocationBtn.disabled =
            false;
    }
}


/* =========================================================
   OPEN LOCATION PICKER
   ========================================================= */

function openLocationPicker() {

    const locationOption = document.querySelector(
        'input[name="locationMethod"][value="map"]'
    );
    if (locationOption) {
        locationOption.checked = true;
    }

    if (!mapSelectModal) {
        return;
    }


    mapSelectModal.classList.remove(
        "hidden"
    );


    mapSelectModal.classList.add(
        "flex"
    );


    mapSelectModal.setAttribute(
        "aria-hidden",
        "false"
    );


    initLocationPickerMap();


    setTimeout(
        function() {

            if (!locationPickerMap) {
                return;
            }


            locationPickerMap.invalidateSize();


            if (
                selectedMapLocation
            ) {

                locationPickerMap.setView(
                    [
                        selectedMapLocation.lat,
                        selectedMapLocation.lng
                    ],
                    16
                );

            } else {

                getUserLocationForPicker();
            }

        },
        200
    );
}


/* =========================================================
   PICKER CURRENT LOCATION
   ========================================================= */

function getUserLocationForPicker() {

    if (!locationPickerMap) {
        return;
    }


    if (
        !navigator.geolocation
    ) {

        locationPickerMap.setView(
            DEFAULT_CENTER,
            13
        );

        return;
    }


    navigator.geolocation.getCurrentPosition(

        function(position) {

            const lat =
                position.coords.latitude;

            const lng =
                position.coords.longitude;


            selectMapLocation(
                lat,
                lng
            );
        },


        function(error) {

            console.warn(
                "[Madok] Picker location unavailable:",
                error
            );


            locationPickerMap.setView(
                DEFAULT_CENTER,
                13
            );


            if (pickerCoordinates) {

                pickerCoordinates.textContent =
                    "বর্তমান লোকেশন পাওয়া যায়নি। ম্যাপে ক্লিক করে নির্বাচন করুন।";
            }
        },


        {
            enableHighAccuracy: true,

            timeout: 10000,

            maximumAge: 30000
        }
    );
}


/* =========================================================
   CONFIRM PICKER LOCATION
   ========================================================= */

function confirmMapLocation() {

    if (
        !selectedMapLocation
    ) {

        showToast(
            "লোকেশন নির্বাচন করুন",
            "ম্যাপে ক্লিক করে একটি লোকেশন নির্বাচন করুন।"
        );

        return;
    }


    setSelectedLocation(
        selectedMapLocation.lat,
        selectedMapLocation.lng
    );

    const locationOption = document.querySelector(
        'input[name="locationMethod"][value="map"]'
    );
    if (locationOption) {
        locationOption.checked = true;
    }


    closeLocationPicker();


    showToast(
        "লোকেশন নির্বাচন করা হয়েছে",
        "এই লোকেশনটি রিপোর্টের জন্য ব্যবহার করা হবে।"
    );
}


/* =========================================================
   CLOSE PICKER
   ========================================================= */

function closeLocationPicker() {

    if (!mapSelectModal) {
        return;
    }


    mapSelectModal.classList.add(
        "hidden"
    );


    mapSelectModal.classList.remove(
        "flex"
    );


    mapSelectModal.setAttribute(
        "aria-hidden",
        "true"
    );
}


/* =========================================================
   CONTACT OPTIONS
   ========================================================= */

function updateContactVisibility() {

    if (
        !contactYes ||
        !contactInputWrapper
    ) {

        return;
    }


    if (contactYes.checked) {

        contactInputWrapper.classList.remove(
            "hidden"
        );

        contactInfo?.focus();

    } else {

        contactInputWrapper.classList.add(
            "hidden"
        );

        if (contactInfo) {
            contactInfo.value = "";
        }
    }
}


/* =========================================================
   PICKER PLACE SEARCH
   ========================================================= */

function pickerSearchZoom(type) {
    if (type === "division") return 8;
    if (type === "district") return 10;
    if (type === "upazila") return 12;
    return 14;
}

function hidePickerSearchResults() {
    if (locationPickerSearchResults) {
        locationPickerSearchResults.classList.add("hidden");
        locationPickerSearchResults.innerHTML = "";
    }
}

function renderPickerSearchResults(results) {
    if (!locationPickerSearchResults) return;

    const labels = {
        division: "বিভাগ",
        district: "জেলা",
        upazila: "উপজেলা",
        police_station: "থানা"
    };

    if (!results.length) {
        locationPickerSearchResults.innerHTML =
            '<p class="px-3 py-3 text-xs text-slate-500">কোনো ফলাফল পাওয়া যায়নি</p>';
        locationPickerSearchResults.classList.remove("hidden");
        return;
    }

    locationPickerSearchResults.innerHTML = results.map(function(item, index) {
        const hasCoords =
            Number.isFinite(Number(item.lat)) &&
            Number.isFinite(Number(item.lng));
        return `
            <button
                type="button"
                data-picker-result="${index}"
                class="block w-full border-b border-slate-100 px-3 py-2 text-left last:border-0 hover:bg-slate-50 ${hasCoords ? "" : "opacity-50"}"
                ${hasCoords ? "" : "disabled"}
            >
                <span class="block text-[10px] font-bold text-[#951d1f]">${labels[item.type] || item.type}</span>
                <span class="text-sm font-semibold text-slate-700">${escapeHtml(item.label)}</span>
                ${item.secondary && item.secondary !== item.label
                    ? `<span class="ml-2 text-xs text-slate-400">${escapeHtml(item.secondary)}</span>`
                    : ""}
            </button>
        `;
    }).join("");

    locationPickerSearchResults.classList.remove("hidden");

    locationPickerSearchResults.querySelectorAll("[data-picker-result]").forEach(function(button) {
        button.addEventListener("click", function() {
            const item = results[Number(button.dataset.pickerResult)];
            if (!item) return;
            const lat = Number(item.lat);
            const lng = Number(item.lng);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;

            if (locationPickerMap) {
                locationPickerMap.setView([lat, lng], pickerSearchZoom(item.type), { animate: true });
            }
            selectMapLocation(lat, lng);
            if (locationPickerSearchInput) {
                locationPickerSearchInput.value = item.label;
            }
            hidePickerSearchResults();
        });
    });
}

async function searchPickerPlaces(query) {
    try {
        const response = await fetch(
            `api/search.php?q=${encodeURIComponent(query)}`,
            { headers: { Accept: "application/json" }, cache: "no-store" }
        );
        const result = await response.json();
        renderPickerSearchResults(
            response.ok && result.ok ? (result.results || []) : []
        );
    } catch (error) {
        renderPickerSearchResults([]);
    }
}

function initPickerSearch() {
    if (!locationPickerSearchInput || !locationPickerSearchResults) {
        return;
    }

    locationPickerSearchInput.addEventListener("input", function() {
        clearTimeout(locationPickerSearchTimer);
        const query = locationPickerSearchInput.value.trim();
        if (!query) {
            hidePickerSearchResults();
            return;
        }
        locationPickerSearchTimer = setTimeout(function() {
            searchPickerPlaces(query);
        }, 180);
    });

    locationPickerSearchInput.addEventListener("keydown", function(event) {
        if (event.key === "Escape") {
            hidePickerSearchResults();
        }
    });

    document.addEventListener("click", function(event) {
        if (!event.target.closest("#locationPickerSearch, #locationPickerSearchResults")) {
            hidePickerSearchResults();
        }
    });
}

function locateUserOnPickerMap() {
    const button = document.getElementById("locationPickerLocateBtn");
    if (!navigator.geolocation || !button || button.disabled) {
        return;
    }

    const originalHtml = button.innerHTML;
    button.disabled = true;
    button.classList.add("cursor-wait", "opacity-70");
    button.innerHTML =
        '<span class="animate-spin rounded-full border-[3px] border-white/40 border-t-white p-1" aria-label="লোড হচ্ছে"></span>';

    const restore = function() {
        button.disabled = false;
        button.classList.remove("cursor-wait", "opacity-70");
        button.innerHTML = originalHtml;
    };

    navigator.geolocation.getCurrentPosition(
        function(position) {
            selectMapLocation(
                position.coords.latitude,
                position.coords.longitude
            );
            restore();
        },
        function() {
            restore();
            showToast(
                "লোকেশন পাওয়া যায়নি",
                "আপনার বর্তমান লোকেশন পাওয়া যায়নি।"
            );
        },
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 30000 }
    );
}


/* =========================================================
   IMAGE COMPRESSION
   ========================================================= */

async function compressImageFile(file, maxDimension = 1600, quality = 0.75) {
    if (!file || !file.type.startsWith("image/")) {
        return file;
    }
    if (file.size <= 350 * 1024) {
        return file;
    }

    try {
        const bitmap = await createImageBitmap(file);
        const scale = Math.min(1, maxDimension / Math.max(bitmap.width, bitmap.height));
        const width = Math.max(1, Math.round(bitmap.width * scale));
        const height = Math.max(1, Math.round(bitmap.height * scale));
        const canvas = document.createElement("canvas");
        canvas.width = width;
        canvas.height = height;
        const context = canvas.getContext("2d", { alpha: true });
        if (!context) {
            bitmap.close?.();
            return file;
        }
        context.drawImage(bitmap, 0, 0, width, height);
        bitmap.close?.();

        const blob = await new Promise(function(resolve) {
            canvas.toBlob(resolve, "image/webp", quality);
        });

        if (!blob || blob.size <= 0 || blob.size >= file.size) {
            return file;
        }

        const baseName = String(file.name || "report-image").replace(/\.[^.]+$/, "");
        return new File([blob], `${baseName}.webp`, {
            type: "image/webp",
            lastModified: Date.now()
        });
    } catch (error) {
        console.warn("[Madok] Image compression skipped:", error);
        return file;
    }
}


/* =========================================================
   IMAGE PREVIEW
   ========================================================= */

function handleImageChange() {

    if (!imageInput) {
        return;
    }


    const file =
        imageInput.files?.[0];


    if (!file) {

        clearImagePreview();

        return;
    }


    if (
        !file.type.startsWith(
            "image/"
        ) ||
        !["image/jpeg", "image/png", "image/webp"].includes(file.type)
    ) {

        imageInput.value = "";

        clearImagePreview();


        showToast(
            "ভুল ফাইল",
            "শুধু JPG, PNG অথবা WebP image নির্বাচন করুন।"
        );

        return;
    }


    if (
        file.size >
        MAX_IMAGE_SIZE
    ) {

        imageInput.value = "";

        clearImagePreview();


        showToast(
            "ফাইল অনেক বড়",
            "ছবির সর্বোচ্চ size 5MB।"
        );

        return;
    }


    if (
        !previewImage ||
        !imagePreview
    ) {

        return;
    }


    const reader =
        new FileReader();


    reader.onload =
        function(event) {

            previewImage.src =
                event.target.result;


            if (imageName) {

                imageName.textContent =
                    file.name;
            }


            imagePreview.classList.remove(
                "hidden"
            );
        };


    reader.readAsDataURL(file);
}


function clearImagePreview() {

    if (imageInput) {
        imageInput.value = "";
    }


    if (previewImage) {
        previewImage.src = "";
    }


    if (imageName) {
        imageName.textContent = "";
    }


    if (imagePreview) {

        imagePreview.classList.add(
            "hidden"
        );
    }
}


/* =========================================================
   FILTERS
   ========================================================= */

function initFilters() {

    document
        .querySelectorAll(
            ".filter-btn"
        )
        .forEach(
            function(button) {

                button.addEventListener(
                    "click",
                    function() {

                        document
                            .querySelectorAll(
                                ".filter-btn"
                            )
                            .forEach(
                                function(btn) {

                                    btn.classList.remove(
                                        "active"
                                    );
                                }
                            );


                        this.classList.add(
                            "active"
                        );


                        currentFilter =
                            this.dataset.filter ||
                            "all";


                        renderMarkers();
                    }
                );
            }
        );
}


/* =========================================================
   POLICE STATION TABLE
   ========================================================= */

function renderStationTable(
    data = stationData
) {

    const tbody =
        document.getElementById(
            "stationTableBody"
        );

        // console.log(data)


    if (!tbody) {
        return;
    }


    if (!data.length) {

        tbody.innerHTML = `
            <tr>
                <td
                    colspan="6"
                    style="
                        text-align:center;
                        padding:35px;
                        color:#999;
                    "
                >
                    কোনো ফলাফল পাওয়া যায়নি।
                </td>
            </tr>
        `;

        return;
    }


    const visibleData = stationTableExpanded
        ? data
        : data.slice(0, 15);

    tbody.innerHTML =
        visibleData.map(
            function(item) {

                const sale =
                    numberValue(
                        item.sale
                    );

                const use =
                    numberValue(
                        item.use
                    );

                const total =
                    numberValue(
                        item.total
                    );


                return `
                    <tr>

                        <td>
                            ${item.division_slug
                                ? `<a href="location.html?type=division&slug=${encodeURIComponent(item.division_slug)}" class="font-semibold text-primary no-underline hover:underline">${escapeHtml(item.division || "")}</a>`
                                : escapeHtml(item.division || "")}
                        </td>

                        <td>
                            ${item.district_slug
                                ? `<a href="location.html?type=district&slug=${encodeURIComponent(item.district_slug)}" class="font-semibold text-primary no-underline hover:underline">${escapeHtml(item.district || "")}</a>`
                                : escapeHtml(item.district || "")}
                        </td>

                        <td>
                            ${item.district_slug && item.upazila_slug
                                ? `<a href="location.html?type=upazila&slug=${encodeURIComponent(item.district_slug + "/" + item.upazila_slug)}" class="font-semibold text-primary no-underline hover:underline">${escapeHtml(item.station || "")}</a>`
                                : escapeHtml(item.station || "")}
                        </td>

                        <td>
                            <span
                                class="count-badge sale"
                            >
                                ${formatNumber(
                                    sale
                                )}
                            </span>
                        </td>

                        <td>
                            <span
                                class="count-badge use"
                            >
                                ${formatNumber(
                                    use
                                )}
                            </span>
                        </td>

                        <td>
                            <span
                                class="count-badge total"
                            >
                                ${formatNumber(
                                    total
                                )}
                            </span>
                        </td>

                    </tr>
                `;
            }
        ).join("") +
        (data.length > 15
            ? `
                <tr>
                    <td colspan="6" class="see-more-cell py-4 text-center">
                        <button
                            type="button"
                            id="stationSeeMoreBtn"
                            class="rounded-lg px-4 py-2 text-xs font-bold text-primary hover:bg-[#f8f7ff]"
                        >
                            ${stationTableExpanded ? "See less" : "See more"}
                        </button>
                    </td>
                </tr>
            `
            : "");

    const seeMoreButton = document.getElementById("stationSeeMoreBtn");
    if (seeMoreButton) {
        seeMoreButton.addEventListener("click", function() {
            stationTableExpanded = !stationTableExpanded;
            renderStationTable(data);
        });
    }
}


function renderFilteredStations() {
    const filtered = stationData
        .filter(function(item) {
            return (stationDivision === "all" || item.division_slug === stationDivision) &&
                (stationDistrict === "all" || String(item.district_id) === stationDistrict);
        })
        .sort(function(first, second) {
            return numberValue(second.total) - numberValue(first.total);
        });

    stationTableExpanded = false;
    renderStationTable(filtered);
}


/* =========================================================
   DIVISION FILTER
   ========================================================= */

function initDivisionFilter() {
    if (!reportDivisionSelect) {
        return;
    }

    stationDivision = reportDivisionSelect.value || "all";
    stationDistrict = "all";

    if (reportDistrictSelect) {
        reportDistrictSelect.value = "all";
    }

    reportDivisionSelect.addEventListener("change", event => {
        stationDivision = event.target.value || "all";
        stationDistrict = "all";

        if (reportDistrictSelect) {
            reportDistrictSelect.value = "all";
        }

        populateReportDistricts();
        renderFilteredStations();
    });

    if (reportDistrictSelect) {
        reportDistrictSelect.addEventListener("change", event => {
            stationDistrict = event.target.value || "all";
            renderFilteredStations();
        });
    }
}

function populateReportDistricts() {
    if (!reportDistrictSelect) {
        return;
    }

    const districts = new Map();

    stationData
        .filter(item => stationDivision === "all" || item.division_slug === stationDivision)
        .forEach(item => {
            if (!districts.has(String(item.district_id))) {
                districts.set(String(item.district_id), item.district || "District");
            }
        });

    reportDistrictSelect.innerHTML = '<option value="all">সকল জেলা</option>' +
        Array.from(districts.entries())
            .sort((first, second) => first[1].localeCompare(second[1]))
            .map(([id, name]) => `<option value="${escapeHtml(id)}">${escapeHtml(name)}</option>`)
            .join("");
}

/* ---------------------------------------------------------
   Division statistics filter
   --------------------------------------------------------- */

const divisionSelect =
  document.getElementById(
    "divisionSelect"
  );


async function loadStatisticsByDivision(
  division = "all"
) {

  try {

    console.log(
      "[Madok] Loading statistics for:",
      division
    );

    const response =
      await fetch(
        `api/statistics.php?division=${encodeURIComponent(
          division
        )}&_=${Date.now()}`,
        {
          method: "GET",

          headers: {
            Accept:
              "application/json"
          },

          cache: "no-store"
        }
      );


    const text =
      await response.text();


    console.log(
      "[Madok] Statistics HTTP:",
      response.status
    );


    if (!response.ok) {

      throw new Error(
        `Statistics API HTTP ${response.status}`
      );
    }


    let result;

    try {

      result =
        JSON.parse(text);

        console.log(result, 'resulttttttttttttttttt')

    } catch (error) {

      console.error(
        "[Madok] Invalid statistics JSON:",
        text
      );

      throw new Error(
        "Statistics API valid JSON return করছে না।"
      );
    }


    if (!result?.ok) {

      throw new Error(
        result?.message ||
        "Statistics load failed."
      );
    }


    /*
     * Save statistics globally
     */

    window.madokStatistics = {

      total_reports:
        Number(
          result.statistics?.total_reports
        ) || 0,

      use_reports:
        Number(
          result.statistics?.use_reports
        ) || 0,

      sale_reports:
        Number(
          result.statistics?.sale_reports
        ) || 0,

      total_locations:
        Number(
          result.statistics?.total_locations
        ) || 0,

      use_locations:
        Number(
          result.statistics?.use_locations
        ) || 0,

      sale_locations:
        Number(
          result.statistics?.sale_locations
        ) || 0,

      both_locations:
        Number(
          result.statistics?.both_locations
        ) || 0,

      total_stations:
        Number(
          result.statistics?.total_stations
        ) || 0
    };


    /*
     * Update statistics cards
     */

    updateStatisticsUI(
        window.madokStatistics,
        false
    );

    // renderLocationHighlights(result, true);


    console.log(
      "[Madok] Division statistics loaded:",
      window.madokStatistics
    );

  } catch (error) {

    console.error(
      "[Madok] Division statistics error:",
      error
    );


    showToast(
      "পরিসংখ্যান লোড হয়নি",
      error.message ||
      "Statistics data load করা যায়নি।"
    );
  }
}


if (divisionSelect) {

  divisionSelect.addEventListener(
    "change",
    event => {

      const division =
        event.target.value ||
        "all";


      loadStatisticsByDivision(
        division
      );
    }
  );
}


/* =========================================================
   TOAST
   ========================================================= */

function showToast(
    title,
    message
) {

    const toast =
        document.getElementById(
            "toast"
        );

    const toastTitle =
        document.getElementById(
            "toastTitle"
        );

    const toastMessage =
        document.getElementById(
            "toastMessage"
        );


    if (
        !toast ||
        !toastTitle ||
        !toastMessage
    ) {

        console.log(
            "[Madok Toast]",
            title,
            message
        );

        return;
    }


    toastTitle.textContent =
        title ||
        "সফল হয়েছে";


    toastMessage.textContent =
        message ||
        "";


    /*
     * Current CSS uses .toast-show.
     */

    toast.classList.add(
        "toast-show"
    );


    toast.classList.remove(
        "show"
    );


    if (toastTimer) {

        clearTimeout(
            toastTimer
        );
    }


    toastTimer =
        setTimeout(
            function() {

                toast.classList.remove(
                    "toast-show"
                );

            },
            3500
        );
}


/* =========================================================
   RESET REPORT FORM
   ========================================================= */

function resetFormState() {

    if (reportForm) {
        reportForm.reset();
    }


    clearImagePreview();


    if (latitudeInput) {
        latitudeInput.value = "";
    }


    if (longitudeInput) {
        longitudeInput.value = "";
    }


    if (selectedLocation) {

        selectedLocation.innerHTML = `
            <span>📍</span>

            <span>
                ম্যাপে ক্লিক করুন অথবা আপনার বর্তমান লোকেশন ব্যবহার করুন।
            </span>
        `;
    }


    if (contactInputWrapper) {

        contactInputWrapper.classList.add(
            "hidden"
        );
    }


    selectedMapLocation = null;


    if (
        locationPickerMarker &&
        locationPickerMap
    ) {

        try {

            locationPickerMap.removeLayer(
                locationPickerMarker
            );

        } catch (error) {
            // Ignore.
        }


        locationPickerMarker = null;
    }


    if (pickerCoordinates) {

        pickerCoordinates.textContent =
            "ম্যাপে ক্লিক করে লোকেশন নির্বাচন করুন।";
    }


    if (confirmMapLocationBtn) {

        confirmMapLocationBtn.disabled =
            true;
    }


    /*
     * Remove report-selection marker.
     */

    if (
        selectedMapMarker &&
        map
    ) {

        try {

            map.removeLayer(
                selectedMapMarker
            );

        } catch (error) {
            // Ignore.
        }


        selectedMapMarker = null;
    }
}


/* =========================================================
   SUBMIT BUTTON
   ========================================================= */

function disableSubmitButton() {

    if (!submitButton) {
        return;
    }


    submitButton.disabled =
        true;


    if (
        !submitButton.dataset.originalText
    ) {

        submitButton.dataset.originalText =
            submitButton.textContent;
    }


    submitButton.textContent =
        "রিপোর্ট পাঠানো হচ্ছে...";
}


function enableSubmitButton() {

    if (!submitButton) {
        return;
    }


    submitButton.disabled =
        false;


    submitButton.textContent =
        submitButton.dataset.originalText ||
        "রিপোর্ট পাঠান";
}

function setValidationState(field, errorId, message) {
    const errorElement = document.getElementById(errorId);
    if (errorElement) {
        errorElement.textContent = message;
        errorElement.classList.remove("hidden");
    }

    if (field) {
        field.classList.remove("border-slate-200");
        field.classList.add("border-red-500");
    }
}

function clearValidationState(field, errorId) {
    const errorElement = document.getElementById(errorId);
    if (errorElement) {
        errorElement.textContent = "";
        errorElement.classList.add("hidden");
    }

    if (field) {
        field.classList.remove("border-red-500");
        field.classList.add("border-slate-200");
    }
}

function getReportTypeCards() {
    return Array.from(
        document.querySelectorAll('input[name="reportType"]')
    ).map(function(input) {
        return input.nextElementSibling;
    }).filter(Boolean);
}

function getLocationMethodCards() {
    return [
        document.querySelector("#getLocationBtn > span"),
        document.querySelector("#openMapSelectBtn > span")
    ].filter(Boolean);
}

function clearReportTypeValidation() {
    document.getElementById("reportTypeError")?.classList.add("hidden");
    document.getElementById("reportTypeError")?.replaceChildren();
    getReportTypeCards().forEach(function(card) {
        card.classList.remove("border-red-500");
        card.classList.add("border-slate-200");
    });
}

function clearLocationValidation() {
    document.getElementById("reportLocationError")?.classList.add("hidden");
    document.getElementById("reportLocationError")?.replaceChildren();
    getLocationMethodCards().forEach(function(card) {
        card.classList.remove("border-red-500");
        card.classList.add("border-slate-200");
    });
}

function getContactCards() {
    return [
        document.querySelector("#contactYes + span"),
        document.querySelector("#contactNo + span")
    ].filter(Boolean);
}

function clearContactValidation() {
    document.getElementById("contactError")?.classList.add("hidden");
    document.getElementById("contactError")?.replaceChildren();
    getContactCards().forEach(function(card) {
        card.classList.remove("border-red-500");
        card.classList.add("border-slate-200");
    });
}


/* =========================================================
   REPORT SUBMIT
   ========================================================= */

async function handleReportSubmit(
    event
) {

    event.preventDefault();


    if (!reportForm) {
        return;
    }


    const titleInput =
        document.getElementById(
            "reportTitle"
        );


    const title =
        titleInput?.value
            ?.trim() ||
        "";

    const reportType = document.querySelector(
        'input[name="reportType"]:checked'
    );
    const locationMethod = document.querySelector(
        'input[name="locationMethod"]:checked'
    );
    const willingToContactChoice = document.querySelector(
        'input[name="willingToContact"]:checked'
    );

    if (!reportType) {
        getReportTypeCards().forEach(function(card) {
            card.classList.remove("border-slate-200");
            card.classList.add("border-red-500");
        });
        setValidationState(null, "reportTypeError", "দয়া করে রিপোর্টের ধরন সিলেক্ট করুন।");
    } else {
        clearReportTypeValidation();
    }

    if (!title) {
        setValidationState(titleInput, "reportTitleError", "দয়া করে রিপোর্টের শিরোনাম লিখুন।");
    } else {
        clearValidationState(titleInput, "reportTitleError");
    }

    if (!locationMethod) {
        getLocationMethodCards().forEach(function(card) {
            card.classList.remove("border-slate-200");
            card.classList.add("border-red-500");
        });
        setValidationState(null, "reportLocationError", "দয়া করে রিপোর্টের লোকেশন সিলেক্ট করুন।");
    } else {
        clearLocationValidation();
    }

    if (!willingToContactChoice) {
        getContactCards().forEach(function(card) {
            card.classList.remove("border-slate-200");
            card.classList.add("border-red-500");
        });
        setValidationState(null, "contactError", "দয়া করে একটি অপশন সিলেক্ট করুন।");
    } else {
        clearContactValidation();
    }

    const firstInvalidField = !reportType
        ? document.querySelector('input[name="reportType"]')
        : !title
            ? titleInput
            : !locationMethod
                ? document.querySelector('input[name="locationMethod"]')
                : !willingToContactChoice
                    ? document.querySelector('input[name="willingToContact"]')
                    : null;

    if (firstInvalidField) {
        firstInvalidField.scrollIntoView({ behavior: "smooth", block: "center" });
        firstInvalidField.focus({ preventScroll: true });
        return;
    }


    const lat =
        latitudeInput?.value ||
        "";


    const lng =
        longitudeInput?.value ||
        "";


    /*
     * Basic validation.
     */

    if (!lat || !lng) {
        getLocationMethodCards().forEach(function(card) {
            card.classList.remove("border-slate-200");
            card.classList.add("border-red-500");
        });
        setValidationState(null, "reportLocationError", "Please select a report location.");
        document.querySelector('input[name="locationMethod"]')?.scrollIntoView({ behavior: "smooth", block: "center" });
        return;
    }


    const latNumber =
        Number(lat);

    const lngNumber =
        Number(lng);


    if (
        !Number.isFinite(latNumber) ||
        !Number.isFinite(lngNumber)
    ) {

        showToast(
            "ভুল লোকেশন",
            "সঠিক latitude ও longitude নির্বাচন করুন।"
        );

        return;
    }


    const willingToContact =
        document.querySelector(
            'input[name="willingToContact"]:checked'
        )?.value ||
        "no";


    const contactValue =
        contactInfo?.value
            ?.trim() ||
        "";


    if (
        willingToContact === "yes" &&
        !contactValue
    ) {

        showToast(
            "যোগাযোগের তথ্য প্রয়োজন",
            "আপনি যোগাযোগ করতে ইচ্ছুক বলেছেন। আপনার মোবাইল অথবা ইমেইল দিন।"
        );

        contactInfo?.focus();

        return;
    }


    /*
     * Browser-side image validation + compression.
     */

    if (imageInput?.files?.[0]) {

        const file =
            imageInput.files[0];


        if (
            !file.type.startsWith(
                "image/"
            )
        ) {

            showToast(
                "ভুল ফাইল",
                "শুধু image file নির্বাচন করুন।"
            );

            return;
        }


        if (
            file.size >
            MAX_IMAGE_SIZE
        ) {

            showToast(
                "ফাইল অনেক বড়",
                "ছবির সর্বোচ্চ size 5MB।"
            );

            return;
        }
    }


    const formData =
        new FormData(
            reportForm
        );

    if (csrfToken) formData.set("csrf_token", csrfToken);
    if (turnstileToken) {
        formData.set("cf-turnstile-response", turnstileToken);
    } else if (document.getElementById("turnstileWidget") && !document.getElementById("turnstileWidget").classList.contains("hidden")) {
        showToast(
            "Security verification",
            "Security verification এখনো সম্পন্ন হয়নি। একটু পরে আবার চেষ্টা করুন।"
        );
        return;
    }

    if (imageInput?.files?.[0]) {
        const compressed = await compressImageFile(imageInput.files[0]);
        formData.set("image", compressed, compressed.name);
    }


    /*
     * Ensure backend field names exist.
     *
     * Existing PHP report.php expects:
     * reportType
     * title
     * description
     * latitude
     * longitude
     * willingToContact
     * contactInfo
     * image
     */

    if (
        !formData.get("title")
    ) {

        formData.set(
            "title",
            title
        );
    }


    if (
        !formData.get("latitude")
    ) {

        formData.set(
            "latitude",
            latNumber.toFixed(6)
        );
    }


    if (
        !formData.get("longitude")
    ) {

        formData.set(
            "longitude",
            lngNumber.toFixed(6)
        );
    }


    disableSubmitButton();


    try {

        console.log(
            "[Madok] Sending report..."
        );


        const response =
            await fetch(
                REPORT_API,
                {
                    method: "POST",

                    body: formData,

                    headers: {
                        Accept:
                            "application/json"
                    },

                    cache: "no-store"
                }
            );


        const text =
            await response.text();


        console.log(
            "[Madok] Report HTTP:",
            response.status
        );


        console.log(
            "[Madok] Report response:",
            text
        );


        let result;


        try {

            result =
                JSON.parse(text);

        } catch (jsonError) {

            console.error(
                "[Madok] Invalid report JSON:",
                text
            );


            throw new Error(
                "Server valid JSON response দেয়নি। PHP error/log চেক করুন।"
            );
        }


        if (
            !response.ok ||
            !result?.ok
        ) {

            throw new Error(
                result?.message ||
                "রিপোর্ট save করা যায়নি।"
            );
        }


        /*
         * Save succeeded.
         */

        closeReportModal();


        showToast(
            "রিপোর্ট গ্রহণ করা হয়েছে",

            result.merged_with_existing_location
                ? "এই লোকেশনের আগের রিপোর্টের সঙ্গে 100m-এর মধ্যে যুক্ত করা হয়েছে।"
                : "নতুন লোকেশন database-এ সংরক্ষণ করা হয়েছে।"
        );


        resetFormState();

        window.location.href =
            "reports/" +
            encodeURIComponent(result.location_id);


        /*
         * Reload both APIs.
         *
         * Report save already succeeded.
         * If refresh fails, do NOT show
         * a false "save failed" message.
         */

        try {

            await Promise.all([
                loadMapLocations(),
                loadBackendData(),
                loadStatisticsByDivision(divisionSelect?.value || "all")
              ]);


            renderMarkers();


            console.log(
                "[Madok] Backend data refreshed."
            );

        } catch (reloadError) {

            console.error(
                "[Madok] Refresh after report failed:",
                reloadError
            );


            showToast(
                "রিপোর্ট save হয়েছে",
                "নতুন data দেখাতে page refresh করুন।"
            );
        }


    } catch (error) {

        console.error(
            "[Madok] Report submit error:",
            error
        );


        showToast(
            "রিপোর্ট পাঠানো যায়নি",
            error.message ||
            "Server-এর সঙ্গে যোগাযোগ করা যায়নি।"
        );


    } finally {

        resetTurnstile();

        enableSubmitButton();
    }
}


/* =========================================================
   MODAL BUTTON BINDING
   ========================================================= */

function bindClick(
    id,
    handler
) {

    const element =
        document.getElementById(id);


    if (!element) {
        return;
    }


    element.addEventListener(
        "click",
        handler
    );
}


/* =========================================================
   GENERAL EVENT LISTENERS
   ========================================================= */

function initEventListeners() {

    /*
     * Report buttons.
     */

    bindClick(
        "openReportBtn",
        openReportModal
    );

    bindClick(
        "heroReportBtn",
        openReportModal
    );

    bindClick(
        "ctaReportBtn",
        openReportModal
    );


    /*
     * Close report modal.
     */

    bindClick(
        "closeReportBtn",
        closeReportModal
    );

    bindClick(
        "cancelReportBtn",
        closeReportModal
    );


    /*
     * IMPORTANT:
     *
     * Hero button has ONE handler only.
     *
     * It does NOT use getCurrentLocation().
     * It does NOT use locateUserFromHero().
     */
    bindClick(
        "mapLocateBtn",
        locateUserOnMainMap
    );

    bindClick(
        "locateMeBtn",
        locateUserOnMainMap
    );


    /*
     * Report form location button.
     */

    bindClick(
        "getLocationBtn",
        getCurrentLocationForReport
    );


    /*
     * Location picker.
     */

    if (openMapSelectBtn) {

        openMapSelectBtn.addEventListener(
            "click",
            openLocationPicker
        );
    }


    if (closeMapSelectBtn) {

        closeMapSelectBtn.addEventListener(
            "click",
            closeLocationPicker
        );
    }


    if (confirmMapLocationBtn) {

        confirmMapLocationBtn.addEventListener(
            "click",
            confirmMapLocation
        );
    }

    bindClick(
        "locationPickerLocateBtn",
        locateUserOnPickerMap
    );

    initPickerSearch();


    /*
     * Contact options.
     */

    if (contactYes) {

        contactYes.addEventListener(
            "change",
            updateContactVisibility
        );
    }


    if (contactNo) {

        contactNo.addEventListener(
            "change",
            updateContactVisibility
        );
    }


    /*
     * Image.
     */

    if (imageInput) {

        imageInput.addEventListener(
            "change",
            handleImageChange
        );
    }


    bindClick(
        "removeImageBtn",
        clearImagePreview
    );


    /*
     * Report form.
     */

    if (reportForm) {

        reportForm.addEventListener(
            "submit",
            handleReportSubmit
        );

        reportForm.addEventListener("input", function(event) {
            if (event.target.id === "reportTitle") {
                clearValidationState(event.target, "reportTitleError");
            }
        });

        reportForm.addEventListener("change", function(event) {
            if (event.target.name === "reportType") {
                clearReportTypeValidation();
            }
            if (event.target.name === "locationMethod") {
                clearLocationValidation();
            }
            if (event.target.name === "willingToContact") {
                clearContactValidation();
            }
        });

        reportForm.addEventListener("click", function(event) {
            if (event.target.closest("#getLocationBtn, #openMapSelectBtn")) {
                clearLocationValidation();
            }
        });
    }


    /*
     * Outside click - report modal.
     */

    if (reportModal) {

        reportModal.addEventListener(
            "click",
            function(event) {

                if (
                    event.target ===
                    reportModal
                ) {

                    closeReportModal();
                }
            }
        );
    }


    /*
     * Outside click - location picker.
     */

    if (mapSelectModal) {

        mapSelectModal.addEventListener(
            "click",
            function(event) {

                if (
                    event.target ===
                    mapSelectModal
                ) {

                    closeLocationPicker();
                }
            }
        );
    }


    /*
     * Escape key.
     */

    document.addEventListener(
        "keydown",
        function(event) {

            if (
                event.key !== "Escape"
            ) {
                return;
            }


            if (
                reportModal &&
                (
                    reportModal.classList.contains(
                        "open"
                    ) ||
                    reportModal.classList.contains(
                        "modal-open"
                    )
                )
            ) {

                closeReportModal();
            }


            if (
                mapSelectModal &&
                !mapSelectModal.classList.contains(
                    "hidden"
                )
            ) {

                closeLocationPicker();
            }
        }
    );
}


/* =========================================================
   APPLICATION START
   ========================================================= */

async function initMadok() {

    console.log(
        "[Madok] Initializing..."
    );


    /*
     * DOM first.
     */

    cacheDom();

    try {
        await initSecurity();
    } catch (error) {
        console.error("[Madok] Security initialization failed:", error);
        showToast("নিরাপত্তা যাচাই প্রস্তুত হয়নি", "পেজটি reload করে আবার চেষ্টা করুন।");
    }


    /*
     * Initialize maps.
     */

    initMap();

    /*
     * Bind all events exactly once.
     */

    initEventListeners();

    initFilters();

    initDivisionFilter();


    /*
     * Load backend data.
     *
     * Promise.all makes both APIs load
     * independently.
     */

    const results =
        await Promise.allSettled([
            loadMapLocations(),
            loadBackendData(),
            loadStatisticsByDivision(divisionSelect?.value || "all")
        ]);


    /*
     * Locations result.
     */

    if (
        results[0].status ===
        "rejected"
    ) {

        console.error(
            "[Madok] Locations load failed:",
            results[0].reason
        );


        showToast(
            "লোকেশন data load করা যায়নি",
            results[0].reason?.message ||
            "Locations API check করুন।"
        );
    }


    /*
     * Statistics result.
     */

    if (
        results[1].status ===
        "rejected"
    ) {

        console.error(
            "[Madok] Statistics load failed:",
            results[1].reason
        );


        showToast(
            "Statistics load করা যায়নি",
            results[1].reason?.message ||
            "Statistics API check করুন।"
        );
    }


    /*
     * Final map render.
     */

    renderMarkers();

    populateReportDistricts();
    renderFilteredStations();


    /*
     * Fix Leaflet dimensions after page layout.
     */

    setTimeout(
        function() {

            if (map) {
                map.invalidateSize();
            }

        },
        300
    );


    console.log(
        "[Madok] Initialization complete."
    );
}


/* =========================================================
   ONLY ONE DOMContentLoaded
   ========================================================= */

document.addEventListener(
    "DOMContentLoaded",
    initMadok
);