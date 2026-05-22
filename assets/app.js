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
const taskModalReport = document.querySelector('[data-task-modal-report]');
const taskModalView = document.querySelector('[data-task-modal-view]');

function safeText(value, fallback = 'Not provided') {
  const text = String(value || '').trim();
  return text || fallback;
}

function closeTaskModal() {
  if (!taskModal) return;
  taskModal.hidden = true;
  document.body.classList.remove('modal-open');
}

function openTaskModal(data) {
  if (!taskModal || !taskModalTitle || !taskModalMeta || !taskModalDetails) return;
  taskModalTitle.textContent = safeText(data.title, 'Scheduled task');
  taskModalMeta.innerHTML = `
    <span>${safeText(data.start)}</span>
    ${data.end ? `<span>${safeText(data.end)}</span>` : ''}
    ${data.rep ? `<span>${safeText(data.rep)}</span>` : ''}
  `;

  const rows = [
    ['Doctor', data.doctor],
    ['Specialty', data.speciality],
    ['City / Area', data.city],
    ['Hospital / Clinic', data.hospital],
    ['Purpose', data.purpose],
    ['Medicine / Product', data.medicine],
    ['Notes', data.notes],
  ];

  taskModalDetails.innerHTML = rows
    .filter(([, value]) => String(value || '').trim() !== '')
    .map(([label, value]) => `<div class="modal-detail"><span>${label}</span><strong>${safeText(value)}</strong></div>`)
    .join('') || '<div class="empty">No extra task details saved yet.</div>';

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
