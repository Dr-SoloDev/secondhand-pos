/**
 * photo-upload.js — WF-01: Mobile photo capture & upload
 * URL format: /photo-upload.html?po={id}&token={hmac}&expires={ts}
 */

(function () {
  'use strict';

  // ─── State ───────────────────────────────────────────────
  const params   = new URLSearchParams(location.search);
  const PO_ID    = params.get('po');
  const TOKEN    = params.get('token');
  const EXPIRES  = params.get('expires');
  // ใช้ path-based routing เหมือน common.js: /api/index.php/{route}
  const API_BASE = '/api/index.php';

  let selectedFile = null;

  // ─── Init ─────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', init);

  async function init() {
    if (!PO_ID || !TOKEN || !EXPIRES) {
      return showError('ลิงก์ไม่ถูกต้อง — กรุณาสแกน QR ใหม่');
    }
    if (Date.now() / 1000 > parseInt(EXPIRES)) {
      return showError('ลิงก์หมดอายุแล้ว (24 ชั่วโมง) — กรุณาให้พนักงานสร้าง QR ใหม่');
    }

    try {
      const data = await apiFetch(`purchase-orders/order?id=${PO_ID}`);
      renderPoInfo(data);
      await loadPhotos();
      show('stateMain');
    } catch (e) {
      showError(e.message || 'โหลดข้อมูลไม่สำเร็จ');
    }

    // ผูก camera input
    document.getElementById('cameraInput').addEventListener('change', onFileSelected);
  }

  // ─── Render PO info ───────────────────────────────────────
  function renderPoInfo(po) {
    document.getElementById('poBranch').textContent   = po.branch_name || '';
    document.getElementById('poRef').textContent      = po.reference_no || `PO #${PO_ID}`;
    document.getElementById('poSeller').textContent   = po.seller_name
      ? `ผู้ขาย: ${po.seller_name}`
      : 'ไม่ระบุผู้ขาย';
  }

  // ─── Load existing photos ─────────────────────────────────
  async function loadPhotos() {
    try {
      const data = await apiFetch(`purchase-orders/photos?id=${PO_ID}`, 'GET', null, true);
      renderGallery(data.photos || []);
    } catch (_) {
      // ไม่มีรูปยัง — ไม่เป็นไร
      renderGallery([]);
    }
  }

  function renderGallery(photos) {
    const gallery = document.getElementById('photoGallery');
    const count   = document.getElementById('galleryCount');
    gallery.innerHTML = '';

    if (photos.length === 0) {
      count.textContent = 'ยังไม่มีรูป';
      return;
    }

    count.textContent = `อัพโหลดแล้ว ${photos.length} รูป`;
    photos.forEach(p => {
      const img = document.createElement('img');
      img.src       = p.photo_url || p.photo_path;
      img.alt       = 'สินค้า';
      img.className = 'pu-thumb';
      img.loading   = 'lazy';
      gallery.appendChild(img);
    });
  }

  // ─── File selected from camera ────────────────────────────
  function onFileSelected(e) {
    const file = e.target.files[0];
    if (!file) return;

    // ตรวจขนาด client-side ก่อน
    if (file.size > 10 * 1024 * 1024) {
      alert('ไฟล์ใหญ่เกิน 10 MB กรุณาถ่ายรูปใหม่');
      e.target.value = '';
      return;
    }

    selectedFile = file;
    const reader = new FileReader();
    reader.onload = (ev) => {
      document.getElementById('previewImg').src = ev.target.result;
      hide('stateCameraBtn');
      show('statePreview');
    };
    reader.readAsDataURL(file);
  }

  // ─── Cancel → reset ───────────────────────────────────────
  window.cancelPhoto = function () {
    selectedFile = null;
    document.getElementById('cameraInput').value = '';
    document.getElementById('previewImg').src = '';
    hide('statePreview');
    show('stateCameraBtn');
  };

  // ─── Upload ───────────────────────────────────────────────
  window.uploadPhoto = async function () {
    if (!selectedFile) return;

    hide('statePreview');
    hide('stateCameraBtn');
    show('stateUploading');

    try {
      const form = new FormData();
      form.append('photo', selectedFile);

      // path-based URL: /api/index.php/purchase-orders/photos?id=...&token=...&expires=...
      const url = `${API_BASE}/purchase-orders/photos?id=${PO_ID}&token=${encodeURIComponent(TOKEN)}&expires=${EXPIRES}`;
      const res = await fetch(url, { method: 'POST', body: form });
      const json = await res.json();

      if (!res.ok || !json.success) {
        throw new Error(json.message || 'อัพโหลดไม่สำเร็จ');
      }

      // เพิ่มรูปใหม่เข้า gallery แบบ optimistic
      const gallery = document.getElementById('photoGallery');
      const img     = document.createElement('img');
      img.src       = json.data.photo_url;
      img.alt       = 'สินค้า';
      img.className = 'pu-thumb';
      gallery.appendChild(img);

      // update count
      const countEl  = document.getElementById('galleryCount');
      const prevCount = parseInt(countEl.textContent) || 0;
      const newCount  = gallery.querySelectorAll('img').length;
      countEl.textContent = `อัพโหลดแล้ว ${newCount} รูป`;

      showToast();
    } catch (err) {
      alert('ข้อผิดพลาด: ' + err.message);
    } finally {
      // reset สำหรับถ่ายรูปต่อ
      selectedFile = null;
      document.getElementById('cameraInput').value = '';
      hide('stateUploading');
      show('stateCameraBtn');
    }
  };

  // ─── API helper ───────────────────────────────────────────
  async function apiFetch(route, method = 'GET', body = null, useToken = false) {
    // path-based: /api/index.php/purchase-orders/order?id=42
    let url = `${API_BASE}/${route}`;
    if (useToken) {
      const sep = url.includes('?') ? '&' : '?';
      url += `${sep}token=${encodeURIComponent(TOKEN)}&expires=${EXPIRES}`;
    }
    const opts = { method };
    if (body) {
      opts.body    = JSON.stringify(body);
      opts.headers = { 'Content-Type': 'application/json' };
    }
    const res  = await fetch(url, opts);
    const json = await res.json();
    if (!res.ok) throw new Error(json.message || 'API error');
    return json.data || json;
  }

  // ─── UI helpers ───────────────────────────────────────────
  function show(id)   { document.getElementById(id)?.classList.remove('hidden'); }
  function hide(id)   { document.getElementById(id)?.classList.add('hidden'); }

  function showError(msg) {
    document.getElementById('errorMessage').textContent = msg;
    hide('stateLoading');
    show('stateError');
  }

  function showToast() {
    const t = document.getElementById('toastSuccess');
    t.classList.remove('hidden');
    setTimeout(() => t.classList.add('hidden'), 2500);
  }

})();
