document.addEventListener('click', (event) => {
  const toggle = event.target.closest('[data-toggle-sidebar]');
  if (toggle) document.getElementById('sidebar')?.classList.toggle('open');
});

const doctorSelect = document.querySelector('[data-doctor-select]');
if (doctorSelect && window.DOCTORS) {
  doctorSelect.addEventListener('change', () => {
    const doc = window.DOCTORS.find(d => String(d.id) === String(doctorSelect.value));
    if (!doc) return;
    const name = document.querySelector('[name="doctor_name"]');
    const email = document.querySelector('[name="doctor_email"]');
    const hospital = document.querySelector('[name="hospital_name"]');
    if (name) name.value = doc.dr_name || '';
    if (email) email.value = doc.email || '';
    if (hospital) hospital.value = [doc.hospital_address, doc.place].filter(Boolean).join(', ');
  });
}

function resizeSignatureCanvas(canvas) {
  const rect = canvas.getBoundingClientRect();
  const ratio = Math.max(window.devicePixelRatio || 1, 1);
  const oldData = canvas.toDataURL();
  canvas.width = Math.max(600, Math.floor(rect.width * ratio));
  canvas.height = Math.max(180, Math.floor(rect.height * ratio));
  const ctx = canvas.getContext('2d');
  ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
  ctx.lineWidth = 2.8;
  ctx.lineCap = 'round';
  ctx.lineJoin = 'round';
  ctx.strokeStyle = '#083f39';
  if (oldData && !oldData.includes('data:,')) {
    const img = new Image();
    img.onload = () => ctx.drawImage(img, 0, 0, rect.width, rect.height);
    img.src = oldData;
  }
}

const signatureCanvas = document.querySelector('[data-signature-pad]');
if (signatureCanvas) {
  const ctx = signatureCanvas.getContext('2d');
  let drawing = false;
  let hasInk = false;

  const getPoint = (event) => {
    const rect = signatureCanvas.getBoundingClientRect();
    const source = event.touches ? event.touches[0] : event;
    return { x: source.clientX - rect.left, y: source.clientY - rect.top };
  };

  const start = (event) => {
    event.preventDefault();
    drawing = true;
    hasInk = true;
    window.PharmaForceGeo?.captureLocationFromSignature?.();
    const point = getPoint(event);
    ctx.beginPath();
    ctx.moveTo(point.x, point.y);
  };

  const move = (event) => {
    if (!drawing) return;
    event.preventDefault();
    const point = getPoint(event);
    ctx.lineTo(point.x, point.y);
    ctx.stroke();
  };

  const end = () => { drawing = false; };

  resizeSignatureCanvas(signatureCanvas);
  window.addEventListener('resize', () => resizeSignatureCanvas(signatureCanvas));
  signatureCanvas.addEventListener('mousedown', start);
  signatureCanvas.addEventListener('mousemove', move);
  window.addEventListener('mouseup', end);
  signatureCanvas.addEventListener('touchstart', start, { passive: false });
  signatureCanvas.addEventListener('touchmove', move, { passive: false });
  signatureCanvas.addEventListener('touchend', end);

  document.querySelector('[data-clear-signature]')?.addEventListener('click', () => {
    const rect = signatureCanvas.getBoundingClientRect();
    ctx.clearRect(0, 0, rect.width, rect.height);
    hasInk = false;
    document.querySelector('[data-signature-data]').value = '';
    window.PharmaForceGeo?.clearLocation?.();
  });

  document.querySelector('[data-report-form]')?.addEventListener('submit', () => {
    const field = document.querySelector('[data-signature-data]');
    if (field && hasInk) field.value = signatureCanvas.toDataURL('image/png');
  });
}

const taskCity = document.querySelector('[data-task-city]');
const taskDoctor = document.querySelector('[data-task-doctor]');
const taskHospital = document.querySelector('[data-task-hospital]');
if (taskCity && taskDoctor) {
  const allDoctorOptions = Array.from(taskDoctor.querySelectorAll('option')).map(option => option.cloneNode(true));
  const resetDoctors = () => {
    const city = taskCity.value;
    taskDoctor.innerHTML = '';
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = city ? 'Select doctor' : 'Select city first';
    taskDoctor.appendChild(placeholder);
    allDoctorOptions.forEach((option) => {
      if (!option.value) return;
      if (String(option.dataset.city || '') === String(city)) taskDoctor.appendChild(option.cloneNode(true));
    });
    taskDoctor.disabled = !city;
    if (taskHospital) taskHospital.value = '';
  };
  taskCity.addEventListener('change', resetDoctors);
  taskDoctor.addEventListener('change', () => {
    const selected = taskDoctor.options[taskDoctor.selectedIndex];
    if (taskHospital && selected) taskHospital.value = selected.dataset.hospital || '';
  });
  resetDoctors();
}

// Dashboard calendar task preview modal
const taskModal = document.querySelector('[data-task-modal]');
const taskModalTitle = document.querySelector('[data-task-modal-title]');
const taskModalMeta = document.querySelector('[data-task-modal-meta]');
const taskModalDetails = document.querySelector('[data-task-modal-details]');
const taskModalBrief = document.querySelector('[data-task-modal-brief]');
const taskModalReport = document.querySelector('[data-task-modal-report]');
const taskModalView = document.querySelector('[data-task-modal-view]');

function safeText(value, fallback = 'Not provided') {
  const text = String(value || '').trim();
  return text || fallback;
}

function escapeHtml(value) {
  return String(value || '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function renderDoctorBrief(data) {
  const brief = data.doctorBrief || {};
  const doctorName = safeText(data.doctor, 'this doctor');

  if (!brief.hasHistory) {
    return `
      <div class="doctor-brief-card is-first-time">
        <div class="doctor-brief-head">
          <div>
            <span class="eyebrow">Pre-Visit Brief</span>
            <h3>First time meeting ${escapeHtml(doctorName)}</h3>
            <p>No previous report history was found for this doctor in the current visible records. This visit can become the first documented interaction.</p>
          </div>
          <div class="doctor-brief-count"><strong>0</strong><small>Past Visits</small></div>
        </div>
      </div>
    `;
  }

  const products = Array.isArray(brief.products) ? brief.products.filter(Boolean) : [];
  const recentVisits = Array.isArray(brief.recentVisits) ? brief.recentVisits.filter(Boolean) : [];
  const summary = safeText(brief.lastSummary || brief.lastRemarks || brief.managerComment, 'No detailed summary was saved in the last visit.');

  return `
    <div class="doctor-brief-card">
      <div class="doctor-brief-head">
        <div>
          <span class="eyebrow">Pre-Visit Brief</span>
          <h3>Previous meeting context</h3>
          <p>This doctor has existing visit history. Review the last interaction before generating the next report.</p>
        </div>
        <div class="doctor-brief-count"><strong>${escapeHtml(brief.totalVisits || recentVisits.length || 1)}</strong><small>Past Visits</small></div>
      </div>

      <div class="doctor-brief-grid">
        <div class="doctor-brief-mini">
          <span>Last Visit</span>
          <strong>${escapeHtml(safeText(brief.lastVisitDate))}</strong>
        </div>
        <div class="doctor-brief-mini">
          <span>Visited By</span>
          <strong>${escapeHtml(safeText(brief.lastVisitedBy))}</strong>
        </div>
        <div class="doctor-brief-mini">
          <span>Last Status</span>
          <strong>${escapeHtml(safeText(brief.lastStatus))}</strong>
        </div>
      </div>

      ${products.length ? `
        <div class="doctor-brief-products">
          <span>Products / Purpose Discussed</span>
          <div class="doctor-brief-pill-row">
            ${products.slice(0, 6).map((product) => `<span class="doctor-brief-pill">${escapeHtml(product)}</span>`).join('')}
          </div>
        </div>
      ` : ''}

      <div class="doctor-brief-summary">
        <strong>Last visit summary:</strong>
        ${escapeHtml(summary)}
      </div>

      ${recentVisits.length ? `
        <div class="doctor-brief-visits">
          <span>Recent Visit Notes</span>
          ${recentVisits.slice(0, 4).map((visit) => `
            <a class="doctor-brief-visit" href="${escapeHtml(visit.url || '#')}">
              <strong>${escapeHtml(safeText(visit.date))}</strong>
              <div>
                <strong>${escapeHtml(safeText(visit.product || visit.status, 'Visit note'))}</strong>
                <p>${escapeHtml(safeText(visit.summary, 'No notes saved.'))}</p>
              </div>
              <span class="badge">${escapeHtml(safeText(visit.rep))}</span>
            </a>
          `).join('')}
        </div>
      ` : ''}
    </div>
  `;
}

function closeTaskModal() {
  if (!taskModal) return;
  taskModal.hidden = true;
  taskModal.classList.remove('task-modal-wide');
  document.body.classList.remove('modal-open');
}

function openTaskModal(data) {
  if (!taskModal || !taskModalTitle || !taskModalMeta || !taskModalDetails) return;
  const hasHistory = Boolean(data.doctorBrief && data.doctorBrief.hasHistory);

  taskModal.classList.toggle('task-modal-wide', hasHistory);
  taskModalTitle.textContent = safeText(data.title, 'Scheduled task');
  taskModalMeta.innerHTML = `
    <span>${escapeHtml(safeText(data.start))}</span>
    ${data.end ? `<span>${escapeHtml(safeText(data.end))}</span>` : ''}
    ${data.rep ? `<span>${escapeHtml(safeText(data.rep))}</span>` : ''}
  `;

  const rows = [
    ['Doctor', data.doctor],
    ['Specialty', data.speciality],
    ['City / Area', data.city],
    ['Hospital / Clinic', data.hospital],
    ['Email', data.doctorEmail],
    ['Contact', data.doctorContact],
    ['Purpose', data.purpose],
    ['Medicine / Product', data.medicine],
    ['Notes', data.notes],
  ];

  taskModalDetails.innerHTML = rows
    .filter(([, value]) => String(value || '').trim() !== '')
    .map(([label, value]) => `<div class="modal-detail"><span>${escapeHtml(label)}</span><strong>${escapeHtml(safeText(value))}</strong></div>`)
    .join('') || '<div class="empty">No extra task details saved yet.</div>';

  if (taskModalBrief) taskModalBrief.innerHTML = renderDoctorBrief(data);
  if (taskModalReport) taskModalReport.href = data.reportUrl || 'report_form.php';
  if (taskModalView) taskModalView.href = data.taskUrl || 'tasks.php';
  taskModal.hidden = false;
  document.body.classList.add('modal-open');
}

document.addEventListener('click', (event) => {
  const trigger = event.target.closest('[data-task-open]');
  if (trigger) {
    event.preventDefault();
    try {
      openTaskModal(JSON.parse(trigger.dataset.task || '{}'));
    } catch (error) {
      openTaskModal({ title: trigger.textContent, reportUrl: trigger.getAttribute('href') || 'report_form.php' });
    }
  }

  if (event.target.closest('[data-close-task-modal]')) closeTaskModal();
  if (taskModal && event.target === taskModal) closeTaskModal();
});

document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') closeTaskModal();
});

// Report signature geotagging.
// Location capture is triggered automatically on the first signature stroke.
// Clearing the signature also clears the geotag so old location proof cannot be reused with a new signature.
(function () {
  const statusInput = document.querySelector('[data-geo-status]');
  const latInput = document.querySelector('[data-geo-latitude]');
  const lngInput = document.querySelector('[data-geo-longitude]');
  const accuracyInput = document.querySelector('[data-geo-accuracy]');
  const capturedAtInput = document.querySelector('[data-geo-captured-at]');

  if (!statusInput || !latInput || !lngInput) return;

  const statusLabel = document.querySelector('[data-geo-status-label]');
  const latLabel = document.querySelector('[data-geo-latitude-label]');
  const lngLabel = document.querySelector('[data-geo-longitude-label]');
  const accuracyLabel = document.querySelector('[data-geo-accuracy-label]');
  const message = document.querySelector('[data-geo-message]');
  const mapLink = document.querySelector('[data-geo-map-link]');
  const mapPreview = document.querySelector('[data-geo-map-preview]');
  const mapFrame = document.querySelector('[data-geo-map-frame]');

  let captureInProgress = false;
  let captureAttemptedForCurrentSignature = false;

  function setStatus(status, text) {
    statusInput.value = status;
    if (statusLabel) {
      statusLabel.textContent = text;
      statusLabel.className = 'location-status-pill ' + (status || 'waiting');
    }
  }

  function setMessage(text) {
    if (message) message.textContent = text;
  }

  function googleMapUrl(lat, lng) {
    return 'https://www.google.com/maps?q=' + encodeURIComponent(lat + ',' + lng);
  }

  function googleMapEmbedUrl(lat, lng) {
    return 'https://maps.google.com/maps?q=' + encodeURIComponent(lat + ',' + lng) + '&z=17&output=embed';
  }

  function updateMap(lat, lng) {
    if (mapLink) {
      mapLink.hidden = !(lat && lng);
      mapLink.href = lat && lng ? googleMapUrl(lat, lng) : '#';
    }

    if (mapPreview && mapFrame) {
      mapPreview.hidden = !(lat && lng);
      if (lat && lng) mapFrame.src = googleMapEmbedUrl(lat, lng);
      else mapFrame.removeAttribute('src');
    }
  }

  function updateDisplayFromInputs() {
    const lat = latInput.value;
    const lng = lngInput.value;
    const accuracy = accuracyInput ? accuracyInput.value : '';
    const status = statusInput.value;

    if (latLabel) latLabel.textContent = lat || 'Not captured';
    if (lngLabel) lngLabel.textContent = lng || 'Not captured';
    if (accuracyLabel) accuracyLabel.textContent = accuracy ? Math.round(Number(accuracy)) + ' meters' : 'Not captured';

    if (lat && lng) {
      setStatus('captured', 'Location Captured');
      updateMap(lat, lng);
    } else if (status) {
      const label = {
        denied: 'Permission Denied',
        unavailable: 'Unavailable',
        unsupported: 'Unsupported',
        error: 'Location Error',
        capturing: 'Capturing Location',
        waiting: 'Waiting for Signature'
      }[status] || 'Not Captured';
      setStatus(status, label);
      updateMap('', '');
    } else {
      setStatus('waiting', 'Waiting for Signature');
      updateMap('', '');
    }
  }

  function clearLocation() {
    latInput.value = '';
    lngInput.value = '';
    if (accuracyInput) accuracyInput.value = '';
    if (capturedAtInput) capturedAtInput.value = '';
    statusInput.value = 'waiting';
    captureAttemptedForCurrentSignature = false;
    captureInProgress = false;
    updateDisplayFromInputs();
    setMessage('Location cleared because the signature was cleared. Sign again to automatically capture a fresh location.');
  }

  function captureLocationFromSignature() {
    if (captureInProgress || captureAttemptedForCurrentSignature || latInput.value || lngInput.value) return;

    captureAttemptedForCurrentSignature = true;

    if (!navigator.geolocation) {
      setStatus('unsupported', 'Unsupported');
      setMessage('This browser does not support location capture.');
      return;
    }

    captureInProgress = true;
    setStatus('capturing', 'Capturing Location');
    setMessage('Signature started. Requesting location permission automatically. Please allow location access on this device.');

    navigator.geolocation.getCurrentPosition(
      function (position) {
        const coords = position.coords || {};
        const lat = Number(coords.latitude || 0).toFixed(7);
        const lng = Number(coords.longitude || 0).toFixed(7);
        const accuracy = coords.accuracy ? String(coords.accuracy) : '';

        latInput.value = lat;
        lngInput.value = lng;
        if (accuracyInput) accuracyInput.value = accuracy;
        if (capturedAtInput) capturedAtInput.value = new Date().toISOString().slice(0, 19).replace('T', ' ');

        captureInProgress = false;
        updateDisplayFromInputs();
        setMessage('Location captured automatically from this signature. Accuracy depends on the tablet/browser GPS signal.');
      },
      function (error) {
        const status = error && error.code === error.PERMISSION_DENIED
          ? 'denied'
          : (error && error.code === error.POSITION_UNAVAILABLE ? 'unavailable' : 'error');

        captureInProgress = false;
        setStatus(status, status === 'denied' ? 'Permission Denied' : 'Location Error');
        setMessage(status === 'denied'
          ? 'Location permission was denied. The report can still be saved, but it will show no location proof.'
          : 'Location could not be captured. Check GPS/location services and sign again after clearing the signature if needed.');
      },
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
    );
  }

  window.PharmaForceGeo = {
    captureLocationFromSignature,
    clearLocation,
    updateDisplayFromInputs
  };

  updateDisplayFromInputs();
})();

