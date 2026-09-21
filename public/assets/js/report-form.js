(() => {
  const path = window.location.pathname;
  const appBase = path.includes('/reports/')
    ? path.split('/reports/')[0]
    : path.replace(/\/[^/]*$/, '') || '';

  let csrfToken = '';
  let turnstileToken = '';
  let turnstileWidgetId = null;
  let securityReady = null;
  let locationPickerMap = null;
  let locationPickerMarker = null;
  let selectedMapLocation = null;
  let searchTimer = null;
  let compressedImageFile = null;

  const DEFAULT_CENTER = [23.8103, 90.4125];
  const MAX_IMAGE_SIZE = 5 * 1024 * 1024;
  const labels = {
    division: 'বিভাগ',
    district: 'জেলা',
    upazila: 'উপজেলা',
    police_station: 'থানা',
  };

  const html = `
    <div id="reportModal" aria-hidden="true" class="fixed inset-0 z-[2000] hidden items-center justify-center bg-slate-950/65 p-5 backdrop-blur-sm">
      <div class="max-h-[calc(100vh-40px)] w-full max-w-[620px] overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl md:p-7">
        <div class="mb-5 flex items-start justify-between gap-5">
          <div>
            <span class="text-[11px] font-extrabold tracking-[1.5px] text-[#5b46e8]">+ NEW REPORT</span>
            <h2 class="mt-1 text-2xl font-extrabold">মাদকের লোকেশন রিপোর্ট করুন</h2>
          </div>
          <button id="closeReportBtn" type="button" class="grid h-9 w-9 place-items-center rounded-lg border-0 bg-slate-50 text-xl text-slate-500">×</button>
        </div>
        <form id="reportForm" novalidate>
          <div class="mb-5">
            <label class="mb-2 block text-xs md:text-sm font-bold text-slate-600">রিপোর্টের ধরন <span class="text-red-500">*</span></label>
            <div class="grid gap-2.5 sm:grid-cols-2">
              <label class="relative">
                <input type="radio" name="reportType" value="use" required class="peer sr-only" />
                <span class="flex min-h-[73px] cursor-pointer items-center gap-2 rounded-xl border border-slate-200 p-2.5 peer-checked:border-[#5b46e8] peer-checked:bg-[#f8f7ff] hover:border-[#5b46e8] hover:bg-[#f8f7ff]">
                  <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-red-50 text-red-500">◉</span>
                  <span><strong class="block text-[12px] md:text-sm">মাদক সেবন</strong><small class="text-[11px] text-slate-400">এখানে মাদক সেবনের রিপোর্ট</small></span>
                </span>
              </label>
              <label class="relative">
                <input type="radio" name="reportType" value="sale" class="peer sr-only" />
                <span class="flex min-h-[73px] cursor-pointer items-center gap-2 rounded-xl border border-slate-200 p-2.5 peer-checked:border-[#5b46e8] peer-checked:bg-[#f8f7ff] hover:border-[#5b46e8] hover:bg-[#f8f7ff]">
                  <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-orange-50 text-orange-500">↗</span>
                  <span><strong class="block text-[12px] md:text-sm">মাদক বেচাকেনা</strong><small class="text-[11px] text-slate-400">এখানে মাদক বেচাকেনার রিপোর্ট</small></span>
                </span>
              </label>
            </div>
          </div>
          <div class="mb-5">
            <label for="reportTitle" class="mb-2 block text-xs md:text-sm font-bold text-slate-600">শিরোনাম <span class="text-red-500">*</span></label>
            <input id="reportTitle" name="title" type="text" maxlength="100" placeholder="যেমন: কলেজ মাঠের উত্তর কর্ণারে মাদক সেবন চলছে" required class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-xs outline-none focus:border-[#5b46e8] focus:ring-4 focus:ring-[#5b46e8]/10" />
          </div>
          <div class="mb-5">
            <label for="reportDescription" class="mb-2 block text-xs md:text-sm font-bold text-slate-600">সংক্ষিপ্ত বিবরণ</label>
            <textarea id="reportDescription" name="description" rows="3" maxlength="500" placeholder="ঘটনাটি সম্পর্কে সংক্ষেপে লিখুন..." class="w-full resize-y rounded-xl border border-slate-200 px-3 py-2.5 text-xs outline-none focus:border-[#5b46e8] focus:ring-4 focus:ring-[#5b46e8]/10"></textarea>
          </div>
          <div class="mb-5" id="selectReportLocation">
            <label class="mb-2 block text-xs md:text-sm font-bold text-slate-600">রিপোর্টের লোকেশন <span class="text-red-500">*</span></label>
            <div class="grid grid-cols-2 gap-2">
              <label id="getLocationBtn" class="relative">
                <input type="radio" name="locationMethod" value="current" required class="peer sr-only" />
                <span class="flex min-h-[46px] cursor-pointer items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-xs font-bold text-slate-600 transition peer-checked:border-[#5b46e8] peer-checked:bg-[#f8f7ff] peer-checked:text-[#5b46e8] hover:border-[#5b46e8] hover:bg-[#f8f7ff] hover:text-[#5b46e8]"><span>◎</span><span>আমার লোকেশন</span></span>
              </label>
              <label id="openMapSelectBtn" class="relative">
                <input type="radio" name="locationMethod" value="map" class="peer sr-only" />
                <span class="flex min-h-[46px] cursor-pointer items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-xs font-bold text-slate-600 transition peer-checked:border-[#5b46e8] peer-checked:bg-[#f8f7ff] peer-checked:text-[#5b46e8] hover:border-[#5b46e8] hover:bg-[#f8f7ff] hover:text-[#5b46e8]"><span>📍</span><span>ম্যাপ থেকে নির্বাচন করুন</span></span>
              </label>
            </div>
            <div id="selectedLocation" class="mt-2 flex min-h-10 items-center gap-2 rounded-lg bg-slate-50 px-3 text-[12px] text-slate-500"><span>📍</span><span>আপনার বর্তমান লোকেশন ব্যবহার করুন অথবা ম্যাপ থেকে একটি স্থান নির্বাচন করুন।</span></div>
            <input type="hidden" id="latitude" name="latitude" required />
            <input type="hidden" id="longitude" name="longitude" required />
          </div>
          <div class="mb-5">
            <label class="mb-2 block text-xs md:text-sm font-bold text-slate-600">আপনি কি তাদের ধরিয়ে দিতে ইচ্ছুক?</label>
            <div class="grid grid-cols-2 gap-2">
              <label class="relative">
                <input type="radio" name="willingToContact" value="yes" id="contactYes" required class="peer sr-only" />
                <span class="flex min-h-[48px] cursor-pointer items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white text-xs font-bold text-slate-600 transition peer-checked:border-[#5b46e8] peer-checked:bg-[#f8f7ff] peer-checked:text-[#5b46e8] hover:border-[#5b46e8] hover:bg-[#f8f7ff]"><span>✓</span>হ্যাঁ, আমি ইচ্ছুক</span>
              </label>
              <label class="relative">
                <input type="radio" name="willingToContact" value="no" id="contactNo" class="peer sr-only" />
                <span class="flex min-h-[48px] cursor-pointer items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white text-xs font-bold text-slate-600 transition peer-checked:border-[#5b46e8] peer-checked:bg-[#f8f7ff] hover:border-[#5b46e8] hover:bg-[#f8f7ff]"><span>×</span>না</span>
              </label>
            </div>
            <div class="mt-3 flex gap-2 rounded-xl border border-blue-100 bg-blue-50 p-3">
              <div class="mt-0.5 text-blue-600">ⓘ</div>
              <p class="m-0 text-[12px] leading-5 text-blue-700">আপনি চাইলে আমাদের সাথে আপনার যোগাযোগের তথ্য শেয়ার করতে পারেন। আপনার দেওয়া তথ্য অন্য কোনো ব্যবহারকারীর কাছে প্রকাশ করা হবে না।</p>
            </div>
            <div id="contactInputWrapper" class="mt-3 hidden">
              <label for="contactInfo" class="mb-2 block text-xs md:text-sm font-bold text-slate-600">মোবাইল নম্বর অথবা ইমেইল</label>
              <input type="text" id="contactInfo" name="contactInfo" placeholder="01XXXXXXXXX অথবা example@email.com" class="w-full rounded-xl border border-slate-200 px-3 py-3 text-xs outline-none transition focus:border-[#5b46e8] focus:ring-4 focus:ring-[#5b46e8]/10" />
            </div>
          </div>
          <div class="mb-3">
            <label class="mb-2 block text-xs md:text-sm font-bold text-slate-600">ছবি <span class="font-normal text-slate-400">(ঐচ্ছিক, সর্বোচ্চ ১টি)</span></label>
            <label for="reportImage" class="flex min-h-32 cursor-pointer flex-col items-center justify-center rounded-xl border-[1.5px] border-dashed border-slate-300 bg-slate-50 text-center hover:border-[#5b46e8]">
              <input id="reportImage" name="image" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" />
              <span class="mb-1 grid h-9 w-9 place-items-center rounded-lg bg-[#efedff] text-lg font-bold text-[#5b46e8]">↑</span>
              <strong class="text-[12px] text-slate-600">ছবি নির্বাচন করুন</strong>
              <small class="text-[11px] text-slate-400">JPG, PNG বা WebP · সর্বোচ্চ 5MB · স্বয়ংক্রিয় compress</small>
            </label>
            <div id="imagePreview" class="mt-2 hidden items-center gap-2 rounded-xl border border-slate-200 p-2">
              <img id="previewImage" src="" alt="Selected preview" class="h-14 w-14 rounded-lg object-cover" />
              <div>
                <strong id="imageName" class="block max-w-[390px] truncate text-[10px]"></strong>
                <button type="button" id="removeImageBtn" class="text-[12px] text-red-500">ছবি সরান</button>
              </div>
            </div>
          </div>
          <div class="flex gap-2 rounded-lg bg-slate-50 p-3 text-slate-500">
            <span class="text-[#5b46e8]">ⓘ</span>
            <p class="m-0 text-[12px] leading-4">শুধুমাত্র জনসাধারণের জন্য প্রাসঙ্গিক লোকেশন রিপোর্ট করুন। কোনো ব্যক্তির ব্যক্তিগত তথ্য প্রকাশ করবেন না।</p>
          </div>
          <input type="hidden" name="csrf_token" id="csrfToken" />
          <div id="turnstileWidget" class="cf-turnstile mb-4 mt-4 hidden"></div>
          <p id="reportFormError" class="mt-3 text-sm text-red-600"></p>
          <div class="mt-5 flex justify-end gap-2">
            <button type="button" id="cancelReportBtn" class="flex-1 rounded-xl border border-slate-200 bg-white px-4 py-3 text-xs font-bold text-slate-600 sm:flex-none">বাতিল</button>
            <button type="submit" id="submitReportBtn" class="flex-1 rounded-xl bg-[#5b46e8] px-4 py-3 text-xs font-bold text-white shadow-lg shadow-[#5b46e8]/20 sm:flex-none">রিপোর্ট জমা দিন</button>
          </div>
        </form>
      </div>
    </div>

    <div id="mapSelectModal" aria-hidden="true" class="fixed inset-0 z-[2500] hidden items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm">
      <div class="flex max-h-[calc(100vh-32px)] w-full max-w-[850px] flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-200 px-4 py-4">
          <div>
            <span class="text-[10px] font-extrabold tracking-[1.5px] text-[#5b46e8]">SELECT LOCATION</span>
            <h3 class="mt-1 text-lg font-extrabold">ম্যাপ থেকে লোকেশন নির্বাচন করুন</h3>
            <p class="mt-1 text-[10px] text-slate-400">ম্যাপে ক্লিক করে রিপোর্ট করার জায়গাটি নির্বাচন করুন।</p>
          </div>
          <button type="button" id="closeMapSelectBtn" class="grid h-9 w-9 place-items-center rounded-lg bg-slate-50 text-xl text-slate-500 hover:bg-slate-100">×</button>
        </div>
        <div class="border-b border-slate-200 px-4 py-3">
          <div class="relative">
            <input id="locationPickerSearch" type="search" autocomplete="off" placeholder="বিভাগ, জেলা, উপজেলা বা থানা খুঁজুন" class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs outline-none focus:border-[#5b46e8]" />
            <div id="locationPickerSearchResults" class="absolute left-0 right-0 top-11 z-[2500] hidden max-h-56 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-xl"></div>
          </div>
        </div>
        <div class="relative">
          <div id="locationPickerMap" class="h-[400px] w-full md:h-[500px]"></div>
          <button id="locationPickerLocateBtn" type="button" title="আমার বর্তমান অবস্থান" aria-label="আমার বর্তমান অবস্থান" class="absolute bottom-4 right-3 z-[500] grid h-10 w-10 place-items-center rounded-xl border border-slate-200 bg-black/95 text-white shadow-xl disabled:cursor-wait">
            <span class="rounded-full border-[3px] border-white bg-[#5b46e8] p-1"></span>
          </button>
          <div class="absolute left-3 top-3 z-[500] rounded-xl bg-white/95 px-3 py-2 shadow-lg">
            <div class="flex items-center gap-2">
              <span class="grid h-7 w-7 place-items-center rounded-lg bg-[#efedff] text-[#5b46e8]">📍</span>
              <div>
                <strong class="block text-[10px]">লোকেশন নির্বাচন করুন</strong>
                <span class="block text-[8px] text-slate-400">ম্যাপে ক্লিক করুন</span>
              </div>
            </div>
          </div>
        </div>
        <div class="border-t border-slate-200 bg-slate-50 px-4 py-3">
          <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
            <div>
              <span class="block text-[9px] text-slate-400">নির্বাচিত লোকেশন</span>
              <strong id="pickerCoordinates" class="block text-[11px] text-slate-600">এখনো কোনো লোকেশন নির্বাচন করা হয়নি</strong>
            </div>
            <button type="button" id="confirmMapLocationBtn" class="rounded-xl bg-[#5b46e8] px-5 py-3 text-xs font-bold text-white shadow-lg shadow-[#5b46e8]/20 disabled:cursor-not-allowed disabled:opacity-50" disabled>এই লোকেশন ব্যবহার করুন</button>
          </div>
        </div>
      </div>
    </div>
  `;

  const escapeHtml = (value) =>
    String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');

  function setModalOpen(modal, open) {
    if (!modal) return;
    modal.classList.toggle('hidden', !open);
    modal.classList.toggle('flex', open);
    modal.classList.toggle('modal-open', open);
    modal.setAttribute('aria-hidden', open ? 'false' : 'true');
    if (modal.id === 'reportModal') {
      document.body.style.overflow = open ? 'hidden' : '';
    }
  }

  function setSelectedLocation(lat, lng, label) {
    const latitude = Number(lat);
    const longitude = Number(lng);
    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return;
    document.getElementById('latitude').value = latitude.toFixed(6);
    document.getElementById('longitude').value = longitude.toFixed(6);
    const box = document.getElementById('selectedLocation');
    if (box) {
      box.innerHTML = `<span>📍</span><span>${escapeHtml(label || `${latitude.toFixed(6)}, ${longitude.toFixed(6)}`)}</span>`;
    }
  }

  function openReportModal(lat, lng) {
    const modal = document.getElementById('reportModal');
    document.getElementById('reportFormError').textContent = '';
    if (Number.isFinite(Number(lat)) && Number.isFinite(Number(lng))) {
      const mapOption = document.querySelector('input[name="locationMethod"][value="map"]');
      if (mapOption) mapOption.checked = true;
      setSelectedLocation(lat, lng);
    }
    setModalOpen(modal, true);
  }

  function closeReportModal() {
    setModalOpen(document.getElementById('reportModal'), false);
  }

  function updateContactVisibility() {
    const wrapper = document.getElementById('contactInputWrapper');
    const yes = document.getElementById('contactYes');
    if (!wrapper) return;
    wrapper.classList.toggle('hidden', !yes?.checked);
  }

  async function compressImageFile(file, maxDimension = 1600, quality = 0.75) {
    if (!file || !file.type.startsWith('image/')) return file;
    if (file.size <= 350 * 1024) return file;
    try {
      const bitmap = await createImageBitmap(file);
      const scale = Math.min(1, maxDimension / Math.max(bitmap.width, bitmap.height));
      const width = Math.max(1, Math.round(bitmap.width * scale));
      const height = Math.max(1, Math.round(bitmap.height * scale));
      const canvas = document.createElement('canvas');
      canvas.width = width;
      canvas.height = height;
      const ctx = canvas.getContext('2d', { alpha: true });
      if (!ctx) {
        bitmap.close?.();
        return file;
      }
      ctx.drawImage(bitmap, 0, 0, width, height);
      bitmap.close?.();
      const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/webp', quality));
      if (!blob || blob.size <= 0 || blob.size >= file.size) return file;
      const baseName = String(file.name || 'report-image').replace(/\.[^.]+$/, '');
      return new File([blob], `${baseName}.webp`, { type: 'image/webp', lastModified: Date.now() });
    } catch {
      return file;
    }
  }

  function clearImagePreview() {
    const input = document.getElementById('reportImage');
    const preview = document.getElementById('imagePreview');
    const image = document.getElementById('previewImage');
    const name = document.getElementById('imageName');
    if (input) input.value = '';
    if (image) image.src = '';
    if (name) name.textContent = '';
    if (preview) preview.classList.add('hidden');
    compressedImageFile = null;
  }

  async function handleImageChange() {
    const input = document.getElementById('reportImage');
    const file = input?.files?.[0];
    const error = document.getElementById('reportFormError');
    if (!file) {
      clearImagePreview();
      return;
    }
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > MAX_IMAGE_SIZE) {
      clearImagePreview();
      error.textContent = 'JPG, PNG অথবা WebP ছবি 5MB-এর মধ্যে দিন।';
      return;
    }
    error.textContent = '';
    compressedImageFile = await compressImageFile(file);
    const preview = document.getElementById('imagePreview');
    const image = document.getElementById('previewImage');
    const name = document.getElementById('imageName');
    const reader = new FileReader();
    reader.onload = (event) => {
      if (image) image.src = event.target.result;
      if (name) {
        const sizeKb = Math.round((compressedImageFile?.size || file.size) / 1024);
        name.textContent = `${compressedImageFile?.name || file.name} (${sizeKb}KB)`;
      }
      preview?.classList.remove('hidden');
      preview?.classList.add('flex');
    };
    reader.readAsDataURL(compressedImageFile || file);
  }

  function selectMapLocation(lat, lng) {
    const latitude = Number(lat);
    const longitude = Number(lng);
    if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || !locationPickerMap) return;
    selectedMapLocation = { lat: latitude, lng: longitude };
    if (locationPickerMarker) {
      try {
        locationPickerMap.removeLayer(locationPickerMarker);
      } catch {}
    }
    locationPickerMarker = L.marker([latitude, longitude]).addTo(locationPickerMap);
    locationPickerMap.setView([latitude, longitude], Math.max(locationPickerMap.getZoom(), 14), { animate: true });
    const coords = document.getElementById('pickerCoordinates');
    if (coords) coords.textContent = `${latitude.toFixed(6)}, ${longitude.toFixed(6)}`;
    const confirmBtn = document.getElementById('confirmMapLocationBtn');
    if (confirmBtn) confirmBtn.disabled = false;
  }

  function initLocationPickerMap() {
    if (locationPickerMap || typeof L === 'undefined') return;
    const element = document.getElementById('locationPickerMap');
    if (!element) return;
    locationPickerMap = L.map(element).setView(DEFAULT_CENTER, 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap',
    }).addTo(locationPickerMap);
    locationPickerMap.on('click', (event) => selectMapLocation(event.latlng.lat, event.latlng.lng));
  }

  function getUserLocationForPicker() {
    if (!navigator.geolocation || !locationPickerMap) {
      locationPickerMap?.setView(DEFAULT_CENTER, 13);
      return;
    }
    navigator.geolocation.getCurrentPosition(
      (position) => selectMapLocation(position.coords.latitude, position.coords.longitude),
      () => locationPickerMap.setView(DEFAULT_CENTER, 13),
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 30000 },
    );
  }

  function openLocationPicker() {
    const mapOption = document.querySelector('input[name="locationMethod"][value="map"]');
    if (mapOption) mapOption.checked = true;
    setModalOpen(document.getElementById('mapSelectModal'), true);
    initLocationPickerMap();
    setTimeout(() => {
      locationPickerMap?.invalidateSize();
      if (selectedMapLocation) {
        locationPickerMap.setView([selectedMapLocation.lat, selectedMapLocation.lng], 16);
      } else {
        getUserLocationForPicker();
      }
    }, 200);
  }

  function closeLocationPicker() {
    setModalOpen(document.getElementById('mapSelectModal'), false);
    document.getElementById('locationPickerSearchResults')?.classList.add('hidden');
  }

  function confirmMapLocation() {
    if (!selectedMapLocation) return;
    setSelectedLocation(selectedMapLocation.lat, selectedMapLocation.lng);
    closeLocationPicker();
  }

  function locateOnPickerMap() {
    const button = document.getElementById('locationPickerLocateBtn');
    if (!navigator.geolocation || !button || button.disabled) return;
    const original = button.innerHTML;
    button.disabled = true;
    button.classList.add('opacity-70');
    button.innerHTML = '<span class="animate-spin rounded-full border-[3px] border-white/40 border-t-white p-1"></span>';
    const restore = () => {
      button.disabled = false;
      button.classList.remove('opacity-70');
      button.innerHTML = original;
    };
    navigator.geolocation.getCurrentPosition(
      (position) => {
        selectMapLocation(position.coords.latitude, position.coords.longitude);
        restore();
      },
      () => {
        restore();
        document.getElementById('reportFormError').textContent = 'আপনার বর্তমান লোকেশন পাওয়া যায়নি।';
      },
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 30000 },
    );
  }

  function getCurrentLocationForReport() {
    const currentOption = document.querySelector('input[name="locationMethod"][value="current"]');
    if (currentOption) currentOption.checked = true;
    if (!navigator.geolocation) {
      document.getElementById('reportFormError').textContent = 'Browser geolocation support করে না।';
      return;
    }
    document.getElementById('selectedLocation').innerHTML = '<span>📍</span><span>লোকেশন লোড হচ্ছে...</span>';
    navigator.geolocation.getCurrentPosition(
      (position) => setSelectedLocation(position.coords.latitude, position.coords.longitude, 'আপনার বর্তমান লোকেশন'),
      () => {
        document.getElementById('selectedLocation').innerHTML = '<span>📍</span><span>লোকেশন পাওয়া যায়নি। ম্যাপ থেকে নির্বাচন করুন।</span>';
      },
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 30000 },
    );
  }

  function pickerZoom(type) {
    if (type === 'division') return 8;
    if (type === 'district') return 10;
    if (type === 'upazila') return 12;
    return 14;
  }

  function renderPickerSearch(results) {
    const dropdown = document.getElementById('locationPickerSearchResults');
    if (!dropdown) return;
    if (!results.length) {
      dropdown.innerHTML = '<p class="px-3 py-3 text-xs text-slate-500">কোনো ফলাফল পাওয়া যায়নি</p>';
      dropdown.classList.remove('hidden');
      return;
    }
    dropdown.innerHTML = results
      .map((item, index) => {
        const hasCoords = Number.isFinite(Number(item.lat)) && Number.isFinite(Number(item.lng));
        return `<button type="button" data-index="${index}" class="block w-full border-b border-slate-100 px-3 py-2 text-left last:border-0 hover:bg-slate-50 ${hasCoords ? '' : 'opacity-50'}" ${hasCoords ? '' : 'disabled'}><span class="block text-[10px] font-bold text-[#951d1f]">${labels[item.type] || item.type}</span><span class="text-sm font-semibold text-slate-700">${escapeHtml(item.label)}</span></button>`;
      })
      .join('');
    dropdown.classList.remove('hidden');
    dropdown.querySelectorAll('[data-index]').forEach((button) => {
      button.addEventListener('click', () => {
        const item = results[Number(button.dataset.index)];
        if (!item) return;
        const lat = Number(item.lat);
        const lng = Number(item.lng);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
        locationPickerMap?.setView([lat, lng], pickerZoom(item.type), { animate: true });
        selectMapLocation(lat, lng);
        const input = document.getElementById('locationPickerSearch');
        if (input) input.value = item.label;
        dropdown.classList.add('hidden');
      });
    });
  }

  async function initSecurity() {
    const response = await fetch(`${appBase}/api/security.php`, {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });
    const result = await response.json();
    if (!response.ok || !result.ok || !result.csrf_token) {
      throw new Error('Security initialization failed');
    }
    csrfToken = result.csrf_token;
    const csrfInput = document.getElementById('csrfToken');
    if (csrfInput) csrfInput.value = csrfToken;

    if (result.turnstile?.local_test && result.local_turnstile_token) {
      turnstileToken = result.local_turnstile_token;
      return;
    }
    if (!result.turnstile?.enabled || !result.turnstile.site_key) return;

    const widget = document.getElementById('turnstileWidget');
    if (!widget) return;
    widget.classList.remove('hidden');
    await new Promise((resolve, reject) => {
      if (window.turnstile) return resolve();
      const script = document.createElement('script');
      script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
      script.async = true;
      script.defer = true;
      script.onload = resolve;
      script.onerror = () => reject(new Error('Turnstile failed to load'));
      document.head.appendChild(script);
    });
    turnstileWidgetId = window.turnstile.render(widget, {
      sitekey: result.turnstile.site_key,
      action: 'report',
      callback: (token) => {
        turnstileToken = token;
      },
      'expired-callback': () => {
        turnstileToken = '';
      },
      'error-callback': () => {
        turnstileToken = '';
      },
    });
  }

  async function submit(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const error = document.getElementById('reportFormError');
    const button = document.getElementById('submitReportBtn');
    const title = form.title.value.trim();
    const lat = form.latitude.value;
    const lng = form.longitude.value;
    if (!form.reportType.value || !title || !lat || !lng) {
      error.textContent = 'রিপোর্টের ধরন, শিরোনাম এবং লোকেশন দিন।';
      return;
    }
    if (form.willingToContact.value === 'yes' && !form.contactInfo.value.trim()) {
      error.textContent = 'যোগাযোগের তথ্য প্রয়োজন।';
      return;
    }

    button.disabled = true;
    button.textContent = 'রিপোর্ট পাঠানো হচ্ছে...';
    try {
      await securityReady;
      const widget = document.getElementById('turnstileWidget');
      if (widget && !widget.classList.contains('hidden') && !turnstileToken) {
        throw new Error('Security verification এখনো সম্পন্ন হয়নি। একটু পরে আবার চেষ্টা করুন।');
      }
      const data = new FormData(form);
      if (csrfToken) data.set('csrf_token', csrfToken);
      if (turnstileToken) data.set('cf-turnstile-response', turnstileToken);
      if (compressedImageFile) {
        data.set('image', compressedImageFile, compressedImageFile.name);
      } else if (form.image.files[0]) {
        const compressed = await compressImageFile(form.image.files[0]);
        data.set('image', compressed, compressed.name);
      }
      const response = await fetch(`${appBase}/api/report.php`, {
        method: 'POST',
        body: data,
        headers: { Accept: 'application/json' },
        cache: 'no-store',
      });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.message || 'রিপোর্ট save করা যায়নি।');
      closeReportModal();
      window.location.reload();
    } catch (submitError) {
      error.textContent = submitError.message;
      button.disabled = false;
      button.textContent = 'রিপোর্ট জমা দিন';
      if (turnstileWidgetId !== null && window.turnstile) {
        turnstileToken = '';
        window.turnstile.reset(turnstileWidgetId);
      }
    }
  }

  function bindUi() {
    document.getElementById('closeReportBtn')?.addEventListener('click', closeReportModal);
    document.getElementById('cancelReportBtn')?.addEventListener('click', closeReportModal);
    document.getElementById('reportModal')?.addEventListener('click', (event) => {
      if (event.target.id === 'reportModal') closeReportModal();
    });
    document.getElementById('getLocationBtn')?.addEventListener('click', getCurrentLocationForReport);
    document.getElementById('openMapSelectBtn')?.addEventListener('click', openLocationPicker);
    document.getElementById('closeMapSelectBtn')?.addEventListener('click', closeLocationPicker);
    document.getElementById('confirmMapLocationBtn')?.addEventListener('click', confirmMapLocation);
    document.getElementById('locationPickerLocateBtn')?.addEventListener('click', locateOnPickerMap);
    document.getElementById('contactYes')?.addEventListener('change', updateContactVisibility);
    document.getElementById('contactNo')?.addEventListener('change', updateContactVisibility);
    document.getElementById('reportImage')?.addEventListener('change', () => {
      handleImageChange().catch(() => {});
    });
    document.getElementById('removeImageBtn')?.addEventListener('click', clearImagePreview);
    document.getElementById('reportForm')?.addEventListener('submit', submit);
    document.getElementById('mapSelectModal')?.addEventListener('click', (event) => {
      if (event.target.id === 'mapSelectModal') closeLocationPicker();
    });

    const searchInput = document.getElementById('locationPickerSearch');
    const searchResults = document.getElementById('locationPickerSearchResults');
    searchInput?.addEventListener('input', () => {
      clearTimeout(searchTimer);
      const q = searchInput.value.trim();
      if (!q) {
        searchResults?.classList.add('hidden');
        return;
      }
      searchTimer = setTimeout(async () => {
        try {
          const response = await fetch(`${appBase}/api/search.php?q=${encodeURIComponent(q)}`, {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
          });
          const result = await response.json();
          renderPickerSearch(response.ok && result.ok ? result.results || [] : []);
        } catch {
          renderPickerSearch([]);
        }
      }, 180);
    });
    document.addEventListener('click', (event) => {
      if (!event.target.closest('#locationPickerSearch, #locationPickerSearchResults')) {
        searchResults?.classList.add('hidden');
      }
    });

    document.querySelectorAll('#openReportBtn, #reportLocationBtn, #heroReportBtn, #ctaReportBtn').forEach((button) => {
      button.addEventListener('click', (event) => {
        event.preventDefault();
        openReportModal();
      });
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('reportModal')) {
      /* Home page owns its own modal via app.js */
      window.MadokReportForm = {
        open: (lat, lng) => {
          if (typeof window.openReportModal === 'function') {
            if (Number.isFinite(Number(lat)) && Number.isFinite(Number(lng)) && typeof window.setSelectedLocation === 'function') {
              window.setSelectedLocation(Number(lat), Number(lng));
            }
            window.openReportModal();
            return;
          }
        },
      };
      return;
    }

    document.body.insertAdjacentHTML('beforeend', html);
    bindUi();
    window.MadokReportForm = { open: openReportModal };
    securityReady = initSecurity().catch((error) => {
      const box = document.getElementById('reportFormError');
      if (box) box.textContent = error.message;
      throw error;
    });
  });
})();
