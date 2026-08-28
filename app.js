/* =========================================================
   SafeMap
   PHP + MySQL backend connected frontend
   ========================================================= */

const DEFAULT_CENTER = [23.8103, 90.4125];
const DEFAULT_ZOOM = 12;

const demoLocations = [];
const stationData = [];

let map;
let mapMarkers = [];
let selectedMapMarker = null;
let currentFilter = "all";
let userLocationMarker = null;

let locationPickerMap = null;
let locationPickerMarker = null;
let selectedMapLocation = null;


/* ---------------------------------------------------------
   DOM
   --------------------------------------------------------- */

const reportModal = document.getElementById("reportModal");
const reportForm = document.getElementById("reportForm");
const latitudeInput = document.getElementById("latitude");
const longitudeInput = document.getElementById("longitude");
const selectedLocation = document.getElementById("selectedLocation");
const imageInput = document.getElementById("reportImage");
const imagePreview = document.getElementById("imagePreview");
const previewImage = document.getElementById("previewImage");
const imageName = document.getElementById("imageName");

const mapSelectModal = document.getElementById("mapSelectModal");
const openMapSelectBtn = document.getElementById("openMapSelectBtn");
const closeMapSelectBtn = document.getElementById("closeMapSelectBtn");
const confirmMapLocationBtn = document.getElementById("confirmMapLocationBtn");
const pickerCoordinates = document.getElementById("pickerCoordinates");

const contactYes = document.getElementById("contactYes");
const contactNo = document.getElementById("contactNo");
const contactInputWrapper = document.getElementById("contactInputWrapper");
const contactInfo = document.getElementById("contactInfo");

const submitButton = reportForm
  ? reportForm.querySelector('button[type="submit"]')
  : null;


/* ---------------------------------------------------------
   Map
   --------------------------------------------------------- */

function initMap() {
  const mapElement = document.getElementById("map");

  if (!mapElement) {
    console.error("Map element not found.");
    return;
  }

  map = L.map("map", {
    zoomControl: true,
    attributionControl: true
  }).setView(DEFAULT_CENTER, DEFAULT_ZOOM);

  L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    maxZoom: 19,
    attribution: "&copy; OpenStreetMap contributors"
  }).addTo(map);

  renderMarkers();

  map.on("click", (event) => {
    setSelectedLocation(
      event.latlng.lat,
      event.latlng.lng
    );

    openReportModal();
  });
}


function createMarkerIcon(type) {
  const color =
    type === "sale"
      ? "#f97316"
      : type === "use"
        ? "#ef4444"
        : "#8b5cf6";

  const symbol =
    type === "sale"
      ? "↗"
      : type === "use"
        ? "●"
        : "●";

  return L.divIcon({
    className: "",
    html: `
      <div class="custom-marker" style="background:${color}">
        <span>${symbol}</span>
      </div>
    `,
    iconSize: [35, 35],
    iconAnchor: [17, 35],
    popupAnchor: [0, -34]
  });
}


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


function renderMarkers() {
  if (!map) {
    return;
  }

  mapMarkers.forEach(marker => {
    marker.remove();
  });

  mapMarkers = [];

  const filtered = demoLocations.filter(location => {
    return (
      currentFilter === "all" ||
      location.type === currentFilter
    );
  });

  filtered.forEach(location => {
    const marker = L.marker(
      [location.lat, location.lng],
      {
        icon: createMarkerIcon(location.type)
      }
    ).addTo(map);

    const stationText =
      location.station ||
      "থানা নির্ধারণ করা হয়নি";

    marker.bindPopup(`
      <div style="
        min-width:210px;
        font-family:Arial,sans-serif;
      ">

        <div style="
          display:inline-block;
          padding:3px 7px;
          border-radius:5px;
          background:${getTypeBackground(location.type)};
          color:${getTypeTextColor(location.type)};
          font-size:10px;
          font-weight:700;
          margin-bottom:7px;
        ">
          ${getTypeLabel(location.type)}
        </div>

        <strong style="
          display:block;
          font-size:13px;
          line-height:1.5;
        ">
          ${escapeHtml(location.title)}
        </strong>

        <span style="
          display:block;
          color:#777;
          font-size:10px;
          margin-top:5px;
        ">
          ${escapeHtml(stationText)}
        </span>

        <div style="
          margin-top:8px;
          padding-top:7px;
          border-top:1px solid #eee;
          color:#666;
          font-size:10px;
        ">
          মোট রিপোর্ট:
          <strong>${Number(location.reports || 0)}</strong>
        </div>

      </div>
    `);

    marker.on("click", () => {
      selectedMapMarker = location;
    });

    mapMarkers.push(marker);
  });
}


/* ---------------------------------------------------------
   Backend - Locations
   --------------------------------------------------------- */

async function loadMapLocations() {
  try {
    console.log("[SafeMap] Loading locations...");

    const response = await fetch("api/locations.php", {
      method: "GET",
      headers: {
        Accept: "application/json"
      },
      cache: "no-store"
    });

    if (!response.ok) {
      throw new Error(
        `Locations API returned HTTP ${response.status}`
      );
    }

    const result = await response.json();

    if (!result.ok) {
      throw new Error(
        result.message || "Locations load failed."
      );
    }

    demoLocations.length = 0;

    if (Array.isArray(result.locations)) {
      demoLocations.push(
        ...result.locations.map(location => ({
          ...location,
          lat: Number(location.lat),
          lng: Number(location.lng),
          reports: Number(location.reports || 0),
          use_count: Number(location.use_count || 0),
          sale_count: Number(location.sale_count || 0)
        }))
      );
    }

    console.log(
      `[SafeMap] ${demoLocations.length} locations loaded.`
    );

    renderMarkers();

    return result;

  } catch (error) {

    console.error(
      "[SafeMap] loadMapLocations error:",
      error
    );

    throw error;
  }
}


/* ---------------------------------------------------------
   Backend - Statistics
   --------------------------------------------------------- */

async function loadBackendData() {
  try {
    console.log("[SafeMap] Loading statistics...");

    const response = await fetch("api/statistics.php", {
      method: "GET",
      headers: {
        Accept: "application/json"
      },
      cache: "no-store"
    });

    if (!response.ok) {
      throw new Error(
        `Statistics API returned HTTP ${response.status}`
      );
    }

    const result = await response.json();

    if (!result.ok) {
      throw new Error(
        result.message || "Statistics load failed."
      );
    }

    /*
     * Replace stationData with fresh API data.
     */
    stationData.length = 0;

    if (Array.isArray(result.stations)) {
      stationData.push(
        ...result.stations.map(item => ({
          ...item,
          sale: Number(item.sale || 0),
          use: Number(item.use || 0),
          total: Number(item.total || 0)
        }))
      );
    }

    /*
     * Keep statistics globally available.
     * This can also be used later for dashboard cards.
     */
    window.safeMapStatistics =
      result.statistics || {
        total_reports: 0,
        use_reports: 0,
        sale_reports: 0,
        total_locations: 0,
        use_locations: 0,
        sale_locations: 0,
        both_locations: 0
      };

    console.log(
      "[SafeMap] Statistics:",
      window.safeMapStatistics
    );

    renderStationTable();

    updateStatisticsUI(
      window.safeMapStatistics
    );

    return result;

  } catch (error) {

    console.error(
      "[SafeMap] loadBackendData error:",
      error
    );

    throw error;
  }
}


/* ---------------------------------------------------------
   Optional statistics UI updater
   ---------------------------------------------------------
   This function safely updates common IDs if they exist.
   It does NOT require these elements to exist.
   --------------------------------------------------------- */

function updateStatisticsUI(stats) {

  const selectors = {
    totalReports: [
      "#totalReports",
      "#totalReportCount",
      "[data-stat='total-reports']"
    ],

    useReports: [
      "#useReports",
      "#useReportCount",
      "[data-stat='use-reports']"
    ],

    saleReports: [
      "#saleReports",
      "#saleReportCount",
      "[data-stat='sale-reports']"
    ],

    totalLocations: [
      "#totalLocations",
      "#locationCount",
      "[data-stat='total-locations']"
    ]
  };

  setFirstMatchingText(
    selectors.totalReports,
    stats.total_reports
  );

  setFirstMatchingText(
    selectors.useReports,
    stats.use_reports
  );

  setFirstMatchingText(
    selectors.saleReports,
    stats.sale_reports
  );

  setFirstMatchingText(
    selectors.totalLocations,
    stats.total_locations
  );
}


function setFirstMatchingText(selectors, value) {
  for (const selector of selectors) {
    const element = document.querySelector(selector);

    if (element) {
      element.textContent = Number(value || 0).toLocaleString(
        "bn-BD"
      );
      return;
    }
  }
}


/* ---------------------------------------------------------
   Location selection
   --------------------------------------------------------- */

function setSelectedLocation(lat, lng) {

  latitudeInput.value =
    Number(lat).toFixed(6);

  longitudeInput.value =
    Number(lng).toFixed(6);

  selectedLocation.innerHTML = `
    <span>📍</span>

    <span>
      নির্বাচিত লোকেশন:
      <strong>
        ${Number(lat).toFixed(6)},
        ${Number(lng).toFixed(6)}
      </strong>
    </span>
  `;

  if (selectedMapMarker) {
    selectedMapMarker.remove();
  }

  selectedMapMarker = L.marker(
    [lat, lng],
    {
      icon: L.divIcon({
        className: "",

        html: `
          <div style="
            width:20px;
            height:20px;
            border:4px solid white;
            border-radius:50%;
            background:#5b46e8;
            box-shadow:0 3px 12px rgba(0,0,0,.3);
          "></div>
        `,

        iconSize: [20, 20],
        iconAnchor: [10, 10]
      })
    }
  ).addTo(map);
}


/* ---------------------------------------------------------
   Current location
   --------------------------------------------------------- */

function getCurrentLocation() {

  if (!navigator.geolocation) {

    showToast(
      "লোকেশন পাওয়া যাচ্ছে না",
      "আপনার browser geolocation support করে না।"
    );

    return;
  }

  const button =
    document.getElementById("getLocationBtn");

  if (button) {
    button.disabled = true;
    button.innerHTML =
      "লোকেশন নেওয়া হচ্ছে...";
  }

  navigator.geolocation.getCurrentPosition(

    function(position) {

      const lat =
        position.coords.latitude;

      const lng =
        position.coords.longitude;

      latitudeInput.value = lat;
      longitudeInput.value = lng;

      selectedLocation.innerHTML = `
        <span class="text-primary">📍</span>

        <span>
          আপনার বর্তমান লোকেশন:
          <strong class="text-slate-700">
            ${lat.toFixed(6)},
            ${lng.toFixed(6)}
          </strong>
        </span>
      `;

      if (button) {
        button.disabled = false;

        button.innerHTML = `
          <span>◎</span>
          <span>আমার লোকেশন</span>
        `;
      }
    },

    function(error) {

      console.error(
        "[SafeMap] Geolocation error:",
        error
      );

      showToast(
        "লোকেশন পাওয়া যায়নি",
        "Browser location permission দিন অথবা ম্যাপ থেকে নির্বাচন করুন।"
      );

      if (button) {

        button.disabled = false;

        button.innerHTML = `
          <span>◎</span>
          <span>আমার লোকেশন</span>
        `;
      }
    },

    {
      enableHighAccuracy: true,
      timeout: 10000,
      maximumAge: 0
    }
  );
}


/* ---------------------------------------------------------
   Modal
   --------------------------------------------------------- */

function openReportModal() {

  if (!reportModal) {
    return;
  }

  reportModal.classList.remove("hidden");
  reportModal.classList.add("open");
  reportModal.setAttribute(
    "aria-hidden",
    "false"
  );

  document.body.style.overflow = "hidden";
}


function closeReportModal() {

  if (!reportModal) {
    return;
  }

  reportModal.classList.remove("open");
  reportModal.classList.add("hidden");

  reportModal.setAttribute(
    "aria-hidden",
    "true"
  );

  document.body.style.overflow = "";
}


/* ---------------------------------------------------------
   Modal buttons
   --------------------------------------------------------- */

function bindClick(id, handler) {

  const element =
    document.getElementById(id);

  if (element) {
    element.addEventListener(
      "click",
      handler
    );
  }
}


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

bindClick(
  "closeReportBtn",
  closeReportModal
);

bindClick(
  "cancelReportBtn",
  closeReportModal
);

bindClick(
  "getLocationBtn",
  getCurrentLocation
);

bindClick(
  "locateMeBtn",
  getCurrentLocation
);


/* ---------------------------------------------------------
   Contact
   --------------------------------------------------------- */

if (contactYes) {

  contactYes.addEventListener(
    "change",
    function() {

      if (this.checked) {

        contactInputWrapper?.classList.remove(
          "hidden"
        );

        contactInfo?.focus();
      }
    }
  );
}


if (contactNo) {

  contactNo.addEventListener(
    "change",
    function() {

      if (this.checked) {

        contactInputWrapper?.classList.add(
          "hidden"
        );

        if (contactInfo) {
          contactInfo.value = "";
        }
      }
    }
  );
}


/* ---------------------------------------------------------
   Location picker
   --------------------------------------------------------- */

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


/* ---------------------------------------------------------
   Modal outside click
   --------------------------------------------------------- */

if (reportModal) {

  reportModal.addEventListener(
    "click",
    event => {

      if (
        event.target === reportModal
      ) {
        closeReportModal();
      }
    }
  );
}


if (mapSelectModal) {

  mapSelectModal.addEventListener(
    "click",
    event => {

      if (
        event.target === mapSelectModal
      ) {
        closeLocationPicker();
      }
    }
  );
}


document.addEventListener(
  "keydown",
  event => {

    if (
      event.key === "Escape" &&
      reportModal?.classList.contains("open")
    ) {
      closeReportModal();
    }
  }
);


/* ---------------------------------------------------------
   Image
   --------------------------------------------------------- */

if (imageInput) {

  imageInput.addEventListener(
    "change",
    () => {

      const file =
        imageInput.files[0];

      if (!file) {

        clearImagePreview();

        return;
      }

      if (
        !file.type.startsWith("image/")
      ) {

        imageInput.value = "";

        showToast(
          "ভুল ফাইল",
          "শুধু image file নির্বাচন করুন।"
        );

        return;
      }

      if (
        file.size > 5 * 1024 * 1024
      ) {

        imageInput.value = "";

        showToast(
          "ফাইল অনেক বড়",
          "ছবির সর্বোচ্চ size 5MB।"
        );

        return;
      }

      const reader =
        new FileReader();

      reader.onload = event => {

        previewImage.src =
          event.target.result;

        imageName.textContent =
          file.name;

        imagePreview.classList.remove(
          "hidden"
        );
      };

      reader.readAsDataURL(file);
    }
  );
}


bindClick(
  "removeImageBtn",
  clearImagePreview
);


function clearImagePreview() {

  if (!imageInput) {
    return;
  }

  imageInput.value = "";

  if (previewImage) {
    previewImage.src = "";
  }

  if (imageName) {
    imageName.textContent = "";
  }

  if (imagePreview) {
    imagePreview.classList.add("hidden");
  }
}


/* ---------------------------------------------------------
   Filters
   --------------------------------------------------------- */

document.querySelectorAll(
  ".filter-btn"
).forEach(button => {

  button.addEventListener(
    "click",
    () => {

      document.querySelectorAll(
        ".filter-btn"
      ).forEach(btn => {
        btn.classList.remove("active");
      });

      button.classList.add("active");

      currentFilter =
        button.dataset.filter || "all";

      renderMarkers();
    }
  );
});


/* ---------------------------------------------------------
   Report submit
   --------------------------------------------------------- */

if (reportForm) {

  reportForm.addEventListener(
    "submit",
    async event => {

      event.preventDefault();

      const title =
        document
          .getElementById("reportTitle")
          ?.value
          .trim() || "";

      const lat =
        latitudeInput?.value || "";

      const lng =
        longitudeInput?.value || "";

      if (!title) {

        showToast(
          "শিরোনাম প্রয়োজন",
          "রিপোর্টের একটি title দিন।"
        );

        return;
      }

      if (!lat || !lng) {

        showToast(
          "লোকেশন প্রয়োজন",
          "ম্যাপে একটি লোকেশন নির্বাচন করুন।"
        );

        return;
      }

      const willingToContact =
        document.querySelector(
          'input[name="willingToContact"]:checked'
        )?.value || "no";

      const contactValue =
        contactInfo?.value.trim() || "";

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

      const formData =
        new FormData(reportForm);

      if (submitButton) {

        submitButton.disabled = true;

        submitButton.dataset.originalText =
          submitButton.textContent;

        submitButton.textContent =
          "রিপোর্ট পাঠানো হচ্ছে...";
      }

      try {

        console.log(
          "[SafeMap] Sending report..."
        );

        const response =
          await fetch(
            "api/report.php",
            {
              method: "POST",
              body: formData,
              headers: {
                Accept:
                  "application/json"
              }
            }
          );

        console.log(
          "[SafeMap] Report HTTP status:",
          response.status
        );

        const result =
          await response.json();

        console.log(
          "[SafeMap] Report response:",
          result
        );

        if (
          !response.ok ||
          !result?.ok
        ) {

          throw new Error(
            result?.message ||
            "রিপোর্ট save করা যায়নি।"
          );
        }

        closeReportModal();

        showToast(
          "রিপোর্ট গ্রহণ করা হয়েছে",

          result.merged_with_existing_location

            ? "এই লোকেশনের আগের রিপোর্টের সঙ্গে 100m-এর মধ্যে যুক্ত করা হয়েছে।"

            : "নতুন লোকেশন database-এ সংরক্ষণ করা হয়েছে।"
        );

        resetFormState();

        /*
         * Reload database data after successful save.
         *
         * If one reload fails, the report itself is still saved.
         */
        try {

          await loadBackendData();
          await loadMapLocations();

          console.log( "[SafeMap] Backend data refreshed after report save." );

        } catch (reloadError) {

          console.error(
            "[SafeMap] Data reload failed after report save:",
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
  );
}


/* ---------------------------------------------------------
   Report helpers
   --------------------------------------------------------- */

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
}


function enableSubmitButton() {

  if (!submitButton) {
    return;
  }

  submitButton.disabled = false;

  submitButton.textContent =
    submitButton.dataset.originalText ||
    "রিপোর্ট পাঠান";
}


/* ---------------------------------------------------------
   Police station table
   --------------------------------------------------------- */

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
    data.map(item => {

      const sale =
        Number(item.sale || 0);

      const use =
        Number(item.use || 0);

      const total =
        Number(
          item.total ??
          sale + use
        );

      return `
        <tr>

          <td>
            ${escapeHtml(
              item.station
            )}
          </td>

          <td>
            ${escapeHtml(
              item.district
            )}
          </td>

          <td>
            <span class="count-badge sale">
              ${sale}
            </span>
          </td>

          <td>
            <span class="count-badge use">
              ${use}
            </span>
          </td>

          <td>
            <span class="count-badge total">
              ${total}
            </span>
          </td>

        </tr>
      `;
    }).join("");
}


/* ---------------------------------------------------------
   Station search
   --------------------------------------------------------- */

const stationSearch =
  document.getElementById(
    "stationSearch"
  );

if (stationSearch) {

  stationSearch.addEventListener(
    "input",
    event => {

      const keyword =
        event.target.value
          .trim()
          .toLowerCase();

      const filtered =
        stationData.filter(item => {

          const station =
            String(
              item.station || ""
            ).toLowerCase();

          const district =
            String(
              item.district || ""
            ).toLowerCase();

          return (
            station.includes(keyword) ||
            district.includes(keyword)
          );
        });

      renderStationTable(
        filtered
      );
    }
  );
}


/* ---------------------------------------------------------
   Division filter
   --------------------------------------------------------- */

const divisionSelect =
  document.getElementById(
    "divisionSelect"
  );

if (divisionSelect) {

  divisionSelect.addEventListener(
    "change",
    event => {

      const division =
        event.target.value;

      if (
        division === "all" ||
        !division
      ) {

        renderStationTable(
          stationData
        );

        return;
      }

      const filtered =
        stationData.filter(
          item =>
            item.division_slug ===
            division
        );

      renderStationTable(
        filtered
      );
    }
  );
}


/* ---------------------------------------------------------
   Toast
   --------------------------------------------------------- */

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
      title,
      message
    );

    return;
  }

  toastTitle.textContent =
    title;

  toastMessage.textContent =
    message;

  toast.classList.add(
    "show"
  );

  clearTimeout(
    window.toastTimer
  );

  window.toastTimer =
    setTimeout(
      () => {
        toast.classList.remove(
          "show"
        );
      },
      3500
    );
}


/* ---------------------------------------------------------
   Escape HTML
   --------------------------------------------------------- */

function escapeHtml(value) {

  return String(
    value ?? ""
  )
    .replaceAll(
      "&",
      "&amp;"
    )
    .replaceAll(
      "<",
      "&lt;"
    )
    .replaceAll(
      ">",
      "&gt;"
    )
    .replaceAll(
      '"',
      "&quot;"
    )
    .replaceAll(
      "'",
      "&#039;"
    );
}


/* ---------------------------------------------------------
   Location Picker
   --------------------------------------------------------- */

function initLocationPickerMap() {

  if (locationPickerMap) {
    return;
  }

  const pickerElement =
    document.getElementById(
      "locationPickerMap"
    );

  if (!pickerElement) {
    return;
  }

  locationPickerMap =
    L.map(
      "locationPickerMap"
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
    event => {

      selectMapLocation(
        event.latlng.lat,
        event.latlng.lng
      );
    }
  );
}


function selectMapLocation(
  lat,
  lng
) {

  selectedMapLocation = {
    lat: Number(lat),
    lng: Number(lng)
  };

  if (locationPickerMarker) {

    locationPickerMap.removeLayer(
      locationPickerMarker
    );
  }

  locationPickerMarker =
    L.marker([
      selectedMapLocation.lat,
      selectedMapLocation.lng
    ]).addTo(
      locationPickerMap
    );

  locationPickerMap.setView(
    [
      selectedMapLocation.lat,
      selectedMapLocation.lng
    ],
    16
  );

  if (pickerCoordinates) {

    pickerCoordinates.textContent =
      `${selectedMapLocation.lat.toFixed(6)}, ${selectedMapLocation.lng.toFixed(6)}`;
  }

  if (confirmMapLocationBtn) {
    confirmMapLocationBtn.disabled =
      false;
  }
}


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
    () => {

      if (locationPickerMap) {

        locationPickerMap.invalidateSize();

        getUserLocationForPicker();
      }

    },
    150
  );
}


function getUserLocationForPicker() {

  if (!locationPickerMap) {
    return;
  }

  if (!navigator.geolocation) {

    locationPickerMap.setView(
      DEFAULT_CENTER,
      13
    );

    return;
  }

  navigator.geolocation.getCurrentPosition(

    position => {

      const lat =
        position.coords.latitude;

      const lng =
        position.coords.longitude;

      locationPickerMap.setView(
        [lat, lng],
        16
      );

      selectMapLocation(
        lat,
        lng
      );
    },

    () => {

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
      maximumAge: 0
    }
  );
}


function confirmMapLocation() {

  if (!selectedMapLocation) {
    return;
  }

  const lat =
    selectedMapLocation.lat;

  const lng =
    selectedMapLocation.lng;

  latitudeInput.value =
    lat.toFixed(6);

  longitudeInput.value =
    lng.toFixed(6);

  selectedLocation.innerHTML = `
    <span class="text-primary">📍</span>

    <span>
      নির্বাচিত লোকেশন:
      <strong class="text-slate-700">
        ${lat.toFixed(6)},
        ${lng.toFixed(6)}
      </strong>
    </span>
  `;

  closeLocationPicker();
}


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


/* ---------------------------------------------------------
   Start
   --------------------------------------------------------- */

document.addEventListener(
  "DOMContentLoaded",
  async () => {

    console.log(
      "[SafeMap] Initializing..."
    );

    try {

      initMap();

      /*
       * Load both APIs.
       *
       * Promise.all means the page doesn't need
       * to wait for one before starting the other.
       */
      await Promise.all([
        loadBackendData(),
        loadMapLocations()
      ]);

      renderStationTable();

      console.log(
        "[SafeMap] Initialization complete."
      );

    } catch (error) {

      console.error(
        "[SafeMap] Initialization failed:",
        error
      );

      /*
       * Map can still work even if backend
       * data failed.
       */
      renderMarkers();
      renderStationTable();

      showToast(
        "Data load করা যায়নি",
        "Database থেকে data আনতে সমস্যা হয়েছে। Console/Network দেখুন।"
      );
    }
  }
);