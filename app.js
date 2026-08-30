/* =========================================================
   SafeMap
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

const MAX_IMAGE_SIZE = 5 * 1024 * 1024;


/* =========================================================
   APPLICATION STATE
   ========================================================= */

const demoLocations = [];
const stationData = [];

let map = null;
let heroMap = null;
let locationPickerMap = null;

let mapMarkers = [];
let heroMapMarkers = [];

let selectedMapMarker = null;
let userLocationMarker = null;
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

let contactYes = null;
let contactNo = null;
let contactInputWrapper = null;
let contactInfo = null;

let submitButton = null;


/* =========================================================
   DOM CACHE
   ========================================================= */

function cacheDom() {

    reportModal =
        document.getElementById("reportModal");

    reportForm =
        document.getElementById("reportForm");

    latitudeInput =
        document.getElementById("latitude");

    longitudeInput =
        document.getElementById("longitude");

    selectedLocation =
        document.getElementById("selectedLocation");

    imageInput =
        document.getElementById("reportImage");

    imagePreview =
        document.getElementById("imagePreview");

    previewImage =
        document.getElementById("previewImage");

    imageName =
        document.getElementById("imageName");


    mapSelectModal =
        document.getElementById("mapSelectModal");

    openMapSelectBtn =
        document.getElementById("openMapSelectBtn");

    closeMapSelectBtn =
        document.getElementById("closeMapSelectBtn");

    confirmMapLocationBtn =
        document.getElementById(
            "confirmMapLocationBtn"
        );

    pickerCoordinates =
        document.getElementById(
            "pickerCoordinates"
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
        .toLocaleString("bn-BD");
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

    return "সেবন / বেচাকেনা";
}


function getTypeBackground(type) {

    if (type === "sale") {
        return "#fff0e6";
    }

    if (type === "use") {
        return "#ffebeb";
    }

    return "#f1eafe";
}


function getTypeTextColor(type) {

    if (type === "sale") {
        return "#c2410c";
    }

    if (type === "use") {
        return "#c62828";
    }

    return "#6d28d9";
}


function getTypeColor(type) {

    if (type === "sale") {
        return "#f97316";
    }

    if (type === "use") {
        return "#ef4444";
    }

    return "#8b5cf6";
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


/* =========================================================
   MAIN MAP
   ========================================================= */

function initMap() {

    const mapElement =
        document.getElementById("map");

    if (!mapElement) {

        console.warn(
            "[SafeMap] #map not found."
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
            "[SafeMap] Leaflet is not loaded."
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
                "&copy; OpenStreetMap contributors"
        }
    ).addTo(map);


    map.on(
        "click",
        function(event) {

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
   HERO MAP
   ========================================================= */

function initHeroMap() {

    const heroMapElement =
        document.getElementById("heroMap");

    if (!heroMapElement) {

        console.warn(
            "[SafeMap] #heroMap not found."
        );

        return;
    }


    if (heroMap) {
        return;
    }


    if (
        typeof L === "undefined"
    ) {

        console.error(
            "[SafeMap] Leaflet is not loaded."
        );

        return;
    }


    heroMap = L.map(
        heroMapElement,
        {
            zoomControl: false,
            attributionControl: true,

            scrollWheelZoom: false,
            doubleClickZoom: false,
            boxZoom: false,
            keyboard: false,

            dragging: true,
            touchZoom: true
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
                "&copy; OpenStreetMap contributors"
        }
    ).addTo(heroMap);


    setTimeout(
        function() {

            if (heroMap) {
                heroMap.invalidateSize();
            }

            renderHeroMarkers();

        },
        200
    );
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
                location.station ||
                "থানা নির্ধারণ করা হয়নি";


            const reportCount =
                numberValue(
                    location.reports
                );


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


                    <span
                        style="
                            display:block;
                            color:#777;
                            font-size:10px;
                            margin-top:5px;
                        "
                    >
                        ${escapeHtml(
                            stationText
                        )}
                    </span>


                    ${
                        location.district
                            ? `
                                <span
                                    style="
                                        display:block;
                                        color:#999;
                                        font-size:10px;
                                        margin-top:2px;
                                    "
                                >
                                    ${escapeHtml(
                                        location.district
                                    )}
                                </span>
                              `
                            : ""
                    }


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
   HERO MAP MARKERS
   ========================================================= */

function renderHeroMarkers() {

    if (!heroMap) {
        return;
    }


    heroMapMarkers.forEach(
        function(marker) {

            try {
                heroMap.removeLayer(marker);
            } catch (error) {
                // Ignore removed marker.
            }
        }
    );


    heroMapMarkers = [];


    if (!demoLocations.length) {

        heroMap.setView(
            DEFAULT_CENTER,
            DEFAULT_ZOOM
        );

        return;
    }


    const bounds = [];


    demoLocations.forEach(
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
                ).addTo(heroMap);


            marker.bindPopup(`
                <div
                    style="
                        min-width:180px;
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
                            margin-bottom:6px;
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
                            font-size:12px;
                        "
                    >
                        ${escapeHtml(
                            location.title
                        )}
                    </strong>


                    <span
                        style="
                            display:block;
                            margin-top:4px;
                            color:#777;
                            font-size:10px;
                        "
                    >
                        ${escapeHtml(
                            location.station ||
                            "থানা নির্ধারণ করা হয়নি"
                        )}
                    </span>

                </div>
            `);


            heroMapMarkers.push(marker);

            bounds.push([
                lat,
                lng
            ]);
        }
    );


    if (!bounds.length) {

        heroMap.setView(
            DEFAULT_CENTER,
            DEFAULT_ZOOM
        );

        return;
    }


    if (bounds.length === 1) {

        heroMap.setView(
            bounds[0],
            13
        );

        return;
    }


    heroMap.fitBounds(
        bounds,
        {
            padding: [25, 25],

            maxZoom: 13
        }
    );
}


/* =========================================================
   LOAD LOCATIONS
   ========================================================= */

async function loadMapLocations() {

    console.log(
        "[SafeMap] Loading locations..."
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
        "[SafeMap] Locations HTTP:",
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
            "[SafeMap] Invalid locations JSON:",
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
                        "[SafeMap] Invalid location skipped:",
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
        `[SafeMap] ${demoLocations.length} locations loaded.`
    );


    renderMarkers();

    renderHeroMarkers();


    return result;
}


/* =========================================================
   LOAD STATISTICS
   ========================================================= */

async function loadBackendData() {

    console.log(
        "[SafeMap] Loading statistics..."
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


    console.log(
        "[SafeMap] Statistics HTTP:",
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
            "[SafeMap] Invalid statistics JSON:",
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


    window.safeMapStatistics = {

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
        "[SafeMap] Statistics:",
        window.safeMapStatistics
    );


    updateStatisticsUI(
        window.safeMapStatistics
    );


    renderStationTable();


    return result;
}


/* =========================================================
   UPDATE STATISTICS UI
   ========================================================= */

function updateStatisticsUI(stats) {

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


    if (heroReportedLocations) {

        heroReportedLocations.textContent =
            formatNumber(
                stats.total_locations
            );
    }


    if (heroPoliceStations) {

        heroPoliceStations.textContent =
            formatNumber(
                stats.total_stations
            );
    }
}


/* =========================================================
   MAIN MAP CURRENT LOCATION
   ========================================================= */

function locateUserOnMainMap() {

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
            "locateMeBtn"
        );


    if (button) {

        button.disabled = true;

        button.dataset.originalText =
            button.innerHTML;

        button.innerHTML =
            "◎ লোকেশন নেওয়া হচ্ছে...";
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

                restoreLocateButton();

                showToast(
                    "লোকেশন পাওয়া যায়নি",
                    "সঠিক coordinates পাওয়া যায়নি।"
                );

                return;
            }


            /*
             * HERO MAP
             */

            if (!heroMap) {

                initHeroMap();
            }


            if (!heroMap) {

                restoreLocateButton();

                showToast(
                    "Map প্রস্তুত নয়",
                    "Hero Map initialize করা যায়নি।"
                );

                return;
            }


            /*
             * Remove old marker
             */

            if (
                userLocationMarker
            ) {

                try {

                    heroMap.removeLayer(
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
                    heroMap
                );


            userLocationMarker.bindPopup(
                "আপনার বর্তমান অবস্থান"
            );


            /*
             * CENTER HERO MAP
             */

            heroMap.setView(

                [lat, lng],

                16,

                {
                    animate: true
                }
            );


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


            setTimeout(
                function() {

                    if (heroMap) {

                        heroMap.invalidateSize();
                    }

                },
                200
            );


            restoreLocateButton();


            showToast(
                "লোকেশন পাওয়া গেছে",
                "আপনার অবস্থান Hero Map-এ দেখানো হয়েছে।"
            );
        },


        function(error) {

            console.error(
                "[SafeMap] Geolocation error:",
                error
            );


            restoreLocateButton();


            let message =
                "আপনার বর্তমান লোকেশন পাওয়া যায়নি।";


            if (
                error.code ===
                error.PERMISSION_DENIED
            ) {

                message =
                    "Browser location permission দিন।";

            } else if (
                error.code ===
                error.POSITION_UNAVAILABLE
            ) {

                message =
                    "বর্তমান অবস্থান পাওয়া যাচ্ছে না।";

            } else if (
                error.code ===
                error.TIMEOUT
            ) {

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

function restoreLocateButton() {

    const button =
        document.getElementById(
            "locateMeBtn"
        );


    if (!button) {
        return;
    }


    button.disabled = false;


    button.innerHTML =
        button.dataset.originalText ||
        "◎ আমার অবস্থান";
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

        button.disabled = true;

        button.dataset.originalText =
            button.innerHTML;

        button.innerHTML =
            "◎ লোকেশন নেওয়া হচ্ছে...";
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
                "[SafeMap] Report geolocation error:",
                error
            );


            restoreReportLocationButton();


            showToast(
                "লোকেশন পাওয়া যায়নি",
                "Browser location permission দিন অথবা ম্যাপ থেকে লোকেশন নির্বাচন করুন।"
            );
        },


        {
            enableHighAccuracy: true,

            timeout: 15000,

            maximumAge: 30000
        }
    );
}


function restoreReportLocationButton() {

    const button =
        document.getElementById(
            "getLocationBtn"
        );


    if (!button) {
        return;
    }


    button.disabled = false;


    button.innerHTML =
        button.dataset.originalText ||
        `
            <span>◎</span>
            <span>আমার লোকেশন</span>
        `;
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
            "[SafeMap] #locationPickerMap not found."
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
                "&copy; OpenStreetMap contributors"
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
                "[SafeMap] Picker location unavailable:",
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
        )
    ) {

        imageInput.value = "";

        clearImagePreview();


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


    if (!tbody) {
        return;
    }


    if (!data.length) {

        tbody.innerHTML = `
            <tr>
                <td
                    colspan="5"
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


    tbody.innerHTML =
        data.map(
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
                            ${escapeHtml(
                                item.station ||
                                ""
                            )}
                        </td>

                        <td>
                            ${escapeHtml(
                                item.district ||
                                ""
                            )}
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
        ).join("");
}


/* =========================================================
   STATION SEARCH
   ========================================================= */

function initStationSearch() {

    const input =
        document.getElementById(
            "stationSearch"
        );


    if (!input) {
        return;
    }


    input.addEventListener(
        "input",
        function(event) {

            const keyword =
                String(
                    event.target.value ||
                    ""
                )
                    .trim()
                    .toLowerCase();


            const filtered =
                stationData.filter(
                    function(item) {

                        const station =
                            String(
                                item.station ||
                                ""
                            ).toLowerCase();


                        const district =
                            String(
                                item.district ||
                                ""
                            ).toLowerCase();


                        const division =
                            String(
                                item.division ||
                                ""
                            ).toLowerCase();


                        return (
                            station.includes(
                                keyword
                            ) ||

                            district.includes(
                                keyword
                            ) ||

                            division.includes(
                                keyword
                            )
                        );
                    }
                );


            renderStationTable(
                filtered
            );
        }
    );
}


/* =========================================================
   DIVISION FILTER
   ========================================================= */

function initDivisionFilter() {}

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
      "[SafeMap] Loading statistics for:",
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
      "[SafeMap] Statistics HTTP:",
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
        "[SafeMap] Invalid statistics JSON:",
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

    window.safeMapStatistics = {

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
     * Replace station data
     */

    stationData.length = 0;


    if (
      Array.isArray(
        result.stations
      )
    ) {

      stationData.push(

        ...result.stations.map(
          item => ({

            ...item,

            sale:
              Number(
                item.sale
              ) || 0,

            use:
              Number(
                item.use
              ) || 0,

            total:
              Number(
                item.total
              ) || 0
          })
        )
      );
    }


    /*
     * Update statistics cards
     */

    updateStatisticsUI(
      window.safeMapStatistics
    );


    /*
     * Render all stations
     * returned by backend
     */

    renderStationTable(
      stationData
    );


    console.log(
      "[SafeMap] Division statistics loaded:",
      window.safeMapStatistics
    );

  } catch (error) {

    console.error(
      "[SafeMap] Division statistics error:",
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


      /*
       * Search box clear
       * কারণ নতুন division select হলে
       * পুরোনো search result রাখা উচিত নয়।
       */

      if (stationSearch) {

        stationSearch.value = "";
      }


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
            "[SafeMap Toast]",
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


    const lat =
        latitudeInput?.value ||
        "";


    const lng =
        longitudeInput?.value ||
        "";


    /*
     * Basic validation.
     */

    if (!title) {

        showToast(
            "শিরোনাম প্রয়োজন",
            "রিপোর্টের একটি title দিন।"
        );

        titleInput?.focus();

        return;
    }


    if (!lat || !lng) {

        showToast(
            "লোকেশন প্রয়োজন",
            "ম্যাপে একটি লোকেশন নির্বাচন করুন।"
        );

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
     * Browser-side image validation.
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
            "[SafeMap] Sending report..."
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
            "[SafeMap] Report HTTP:",
            response.status
        );


        console.log(
            "[SafeMap] Report response:",
            text
        );


        let result;


        try {

            result =
                JSON.parse(text);

        } catch (jsonError) {

            console.error(
                "[SafeMap] Invalid report JSON:",
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

            renderHeroMarkers();


            console.log(
                "[SafeMap] Backend data refreshed."
            );

        } catch (reloadError) {

            console.error(
                "[SafeMap] Refresh after report failed:",
                reloadError
            );


            showToast(
                "রিপোর্ট save হয়েছে",
                "নতুন data দেখাতে page refresh করুন।"
            );
        }


    } catch (error) {

        console.error(
            "[SafeMap] Report submit error:",
            error
        );


        showToast(
            "রিপোর্ট পাঠানো যায়নি",
            error.message ||
            "Server-এর সঙ্গে যোগাযোগ করা যায়নি।"
        );


    } finally {

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

async function initSafeMap() {

    console.log(
        "[SafeMap] Initializing..."
    );


    /*
     * DOM first.
     */

    cacheDom();


    /*
     * Initialize maps.
     */

    initMap();

    initHeroMap();


    /*
     * Bind all events exactly once.
     */

    initEventListeners();

    initFilters();

    initStationSearch();

    // initDivisionFilter();


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
            "[SafeMap] Locations load failed:",
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
            "[SafeMap] Statistics load failed:",
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

    renderHeroMarkers();

    renderStationTable();


    /*
     * Fix Leaflet dimensions after page layout.
     */

    setTimeout(
        function() {

            if (map) {
                map.invalidateSize();
            }

            if (heroMap) {
                heroMap.invalidateSize();
            }

        },
        300
    );


    console.log(
        "[SafeMap] Initialization complete."
    );
}


/* =========================================================
   ONLY ONE DOMContentLoaded
   ========================================================= */

document.addEventListener(
    "DOMContentLoaded",
    initSafeMap
);