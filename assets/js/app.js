(() => {
  const workspaceSection = document.getElementById('workspaceSection');
  const uploadMessage = document.getElementById('uploadMessage');
  const canvasArea = document.getElementById('canvasArea');
  const fileInput = document.getElementById('fileInput');
  const browseBtn = document.getElementById('browseBtn');
  const startOverBtn = document.getElementById('startOverBtn');
  const submitBtn = document.getElementById('submitBtn');
  const submitStatus = document.getElementById('submitStatus');
  const editPromptBtn = document.getElementById('editPromptBtn');
  const customPromptRow = document.getElementById('customPromptRow');
  const customPromptInput = document.getElementById('customPromptInput');

  const imageCanvas = document.getElementById('imageCanvas');
  const overlayCanvas = document.getElementById('overlayCanvas');
  const imageCtx = imageCanvas.getContext('2d');
  const overlayCtx = overlayCanvas.getContext('2d');
  // Global image size caps for testing (both width and height)
  const MAX_IMAGE_WIDTH = 1024;
  const MAX_IMAGE_HEIGHT = 1024;
  // Keep canvas sizing stable on mobile scroll (URL bar hide/show)
  const CANVAS_MAX_HEIGHT_PX = 600; // fixed cap independent of viewport height
  let lastViewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
  let selectionPromptEl = null; // legacy inline prompt (kept for positioning helpers, but hidden)
  let lastResultUrl = null;

  const state = {
    file: null,
    image: null,
    naturalWidth: 0,
    naturalHeight: 0,
    displayWidth: 0,
    displayHeight: 0,
    scaleX: 1,
    scaleY: 1,
    markersDisplay: [], // {x,y,width,height} in display (CSS pixel) coordinates
    dragState: {
      isDragging: false,
      startX: 0,
      startY: 0
    },
    history: [], // Array of base64 image blobs
    historyIndex: -1, // Current position in history
    isSubmitting: false,
    selectionPromptText: 'delete',
    isEditingPrompt: false,
    isCustomPromptOpen: false
  };

  function setHidden(el, hidden) {
    el.classList.toggle('hidden', !!hidden);
  }

  function preventDefaults(e) {
    e.preventDefault();
    e.stopPropagation();
  }

  function enableDnD() {
    function preventDefaults(e) { e.preventDefault(); e.stopPropagation(); }
    ;['dragenter','dragover','dragleave','drop'].forEach(ev => workspaceSection.addEventListener(ev, preventDefaults));
    ;['dragenter','dragover'].forEach(ev => workspaceSection.addEventListener(ev, () => workspaceSection.classList.add('dragover')));
    ;['dragleave','drop'].forEach(ev => workspaceSection.addEventListener(ev, () => workspaceSection.classList.remove('dragover')));
    workspaceSection.addEventListener('drop', (e) => {
      const dt = e.dataTransfer;
      if (!dt) return;
      const file = (dt.files && dt.files[0]) || null;
      if (file) onFileSelected(file);
    });
  }

  function onFileSelected(file) {
    if (!file || !(file.type && file.type.startsWith('image/'))) {
      alert('Please select an image file');
      return;
    }
    state.file = file;
    const img = new Image();
    img.onload = () => {
      // Resize image if it's too large (max 1024px on either dimension)
      const maxSize = 1024;
      let { width: newWidth, height: newHeight } = img;
      
      if (newWidth > maxSize || newHeight > maxSize) {
        const ratio = Math.min(maxSize / newWidth, maxSize / newHeight);
        newWidth = Math.round(newWidth * ratio);
        newHeight = Math.round(newHeight * ratio);
        
        // Create a resized canvas
        const resizeCanvas = document.createElement('canvas');
        resizeCanvas.width = newWidth;
        resizeCanvas.height = newHeight;
        const resizeCtx = resizeCanvas.getContext('2d');
        resizeCtx.drawImage(img, 0, 0, newWidth, newHeight);
        
        // Create new image from resized canvas
        const resizedImg = new Image();
        resizedImg.onload = () => {
          state.image = resizedImg;
          state.naturalWidth = resizedImg.naturalWidth;
          state.naturalHeight = resizedImg.naturalHeight;
          setupImageState();
        };
        resizedImg.src = resizeCanvas.toDataURL('image/jpeg', 0.9);
      } else {
        state.image = img;
        state.naturalWidth = img.naturalWidth;
        state.naturalHeight = img.naturalHeight;
        setupImageState();
      }
      
      function setupImageState() {
        // Make the canvas visible BEFORE measuring width so we don't get 0
        setHidden(uploadMessage, true);
        setHidden(canvasArea, false);
        
        // Wait a frame to ensure layout is updated, then size canvases accurately
        requestAnimationFrame(() => {
          layoutCanvases();
          drawImage();
          state.markersDisplay = [];
          drawOverlay();
          ensurePromptElement();
          hidePrompt();
          
          // Add initial state to history
          const canvas = document.createElement('canvas');
          canvas.width = state.naturalWidth;
          canvas.height = state.naturalHeight;
          const ctx = canvas.getContext('2d');
          ctx.drawImage(state.image, 0, 0);
          state.history = [canvas.toDataURL()];
          state.historyIndex = 0;
          updateUndoRedoButtons();
          submitStatus.textContent = '';
        });
      }
    };
    img.onerror = () => alert('Failed to load image');
    img.src = URL.createObjectURL(file);
  }

  function layoutCanvases() {
    if (!state.image) return;
    const wrapper = imageCanvas.parentElement;
    const maxWidth = wrapper.clientWidth || 800;
    const maxHeight = CANVAS_MAX_HEIGHT_PX; // fixed cap, ignore viewport height changes
    const iw = state.naturalWidth;
    const ih = state.naturalHeight;
    
    // Calculate scale to fit within both width and height constraints
    const scaleW = maxWidth / iw;
    const scaleH = maxHeight / ih;
    const scale = Math.min(scaleW, scaleH);
    
    let dw = Math.max(1, Math.round(iw * scale));
    let dh = Math.max(1, Math.round(ih * scale));

    const dpr = window.devicePixelRatio || 1;

    // Set CSS size
    imageCanvas.style.width = dw + 'px';
    imageCanvas.style.height = dh + 'px';
    overlayCanvas.style.width = dw + 'px';
    overlayCanvas.style.height = dh + 'px';

    // Set internal pixel size scaled by DPR for crisp rendering
    imageCanvas.width = Math.max(1, Math.round(dw * dpr));
    imageCanvas.height = Math.max(1, Math.round(dh * dpr));
    overlayCanvas.width = Math.max(1, Math.round(dw * dpr));
    overlayCanvas.height = Math.max(1, Math.round(dh * dpr));

    // Reset transforms then scale so drawing uses CSS pixels
    imageCtx.setTransform(1, 0, 0, 1, 0, 0);
    overlayCtx.setTransform(1, 0, 0, 1, 0, 0);
    imageCtx.scale(dpr, dpr);
    overlayCtx.scale(dpr, dpr);

    state.displayWidth = dw;
    state.displayHeight = dh;
    state.scaleX = state.naturalWidth / dw;
    state.scaleY = state.naturalHeight / dh;
  }

  function ensurePromptElement() {
    if (selectionPromptEl) return selectionPromptEl;
    const stage = imageCanvas.parentElement;
    const el = document.createElement('div');
    el.id = 'selectionPrompt';
    el.className = 'selection-prompt hidden';
    el.contentEditable = 'true';
    el.spellcheck = false;
    el.textContent = state.selectionPromptText || 'delete';
    el.addEventListener('mousedown', (e) => { e.stopPropagation(); });
    el.addEventListener('touchstart', (e) => { e.stopPropagation(); }, { passive: true });
    el.addEventListener('input', () => {
      const txt = (el.textContent || '').trim();
      state.selectionPromptText = txt || 'delete';
    });
    stage.appendChild(el);
    selectionPromptEl = el;
    return el;
  }

  function hidePrompt() {
    state.isEditingPrompt = false;
    if (selectionPromptEl) selectionPromptEl.classList.add('hidden');
  }

  function showPrompt() {
    const el = ensurePromptElement();
    el.classList.remove('hidden');
    state.isEditingPrompt = true;
    // focus shortly after showing to allow editing
    setTimeout(() => {
      try { el.focus(); } catch (_) {}
    }, 0);
  }

  function clearPrompt() {
    state.selectionPromptText = '';
    if (selectionPromptEl) {
      selectionPromptEl.textContent = '';
      selectionPromptEl.classList.add('hidden');
    }
    if (customPromptInput) customPromptInput.value = '';
    if (customPromptRow) customPromptRow.classList.add('hidden');
    if (editPromptBtn) editPromptBtn.setAttribute('aria-expanded', 'false');
    state.isCustomPromptOpen = false;
  }

  function positionPromptWithinBox(box) {
    if (!box) return;
    const stage = imageCanvas.parentElement;
    if (!stage) return;
    const el = ensurePromptElement();
    const stageRect = stage.getBoundingClientRect();
    const overlayRect = overlayCanvas.getBoundingClientRect();
    const offsetLeft = overlayRect.left - stageRect.left;
    const offsetTop = overlayRect.top - stageRect.top;
    const padding = 6;
    el.style.position = 'absolute';
    el.style.left = Math.round(offsetLeft + box.x + padding) + 'px';
    el.style.top = Math.round(offsetTop + box.y + padding) + 'px';
  }

  function ensureEditButtonElement() {
    if (selectionEditBtnEl) return selectionEditBtnEl;
    const stage = imageCanvas.parentElement;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'selection-edit-btn hidden';
    btn.title = 'Edit prompt';
    btn.setAttribute('aria-label', 'Edit prompt');
    btn.textContent = '✎';
    btn.addEventListener('mousedown', (e) => { e.stopPropagation(); });
    btn.addEventListener('touchstart', (e) => { e.stopPropagation(); }, { passive: true });
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      const el = ensurePromptElement();
      if (!state.selectionPromptText) state.selectionPromptText = 'delete';
      el.textContent = state.selectionPromptText;
      showPrompt();
      hideEditButton();
    });
    stage.appendChild(btn);
    selectionEditBtnEl = btn;
    return btn;
  }

  function showEditButton() {
    const btn = ensureEditButtonElement();
    btn.classList.remove('hidden');
  }

  function hideEditButton() {
    if (selectionEditBtnEl) selectionEditBtnEl.classList.add('hidden');
  }

  function positionEditButtonWithinBox(box) {
    if (!box) return;
    const stage = imageCanvas.parentElement;
    if (!stage) return;
    const btn = ensureEditButtonElement();
    const stageRect = stage.getBoundingClientRect();
    const overlayRect = overlayCanvas.getBoundingClientRect();
    const offsetLeft = overlayRect.left - stageRect.left;
    const offsetTop = overlayRect.top - stageRect.top;
    const padding = 6;
    btn.style.position = 'absolute';
    btn.style.left = Math.round(offsetLeft + box.x + padding) + 'px';
    btn.style.top = Math.round(offsetTop + box.y + padding) + 'px';
  }

  function drawImage() {
    if (!state.image) return;
    imageCtx.clearRect(0, 0, state.displayWidth, state.displayHeight);
    imageCtx.drawImage(state.image, 0, 0, state.displayWidth, state.displayHeight);
  }

  function drawOverlay() {
    overlayCtx.clearRect(0, 0, state.displayWidth, state.displayHeight);
    
    // Draw selection boxes
    for (let i = 0; i < state.markersDisplay.length; i++) {
      const box = state.markersDisplay[i];
      const x = box.x;
      const y = box.y;
      const w = box.width;
      const h = box.height;
      
      overlayCtx.save();
      
      // Draw main selection box
      overlayCtx.strokeStyle = '#ff0000';
      overlayCtx.lineWidth = 2;
      overlayCtx.setLineDash([5, 5]);
      overlayCtx.beginPath();
      overlayCtx.rect(x, y, w, h);
      overlayCtx.stroke();
      
      overlayCtx.restore();
    }
  }
  
  
  

  function getClickPosOnCanvas(evt, canvas) {
    // Prefer offsetX/Y for reliable coordinates in CSS pixels
    if (typeof evt.offsetX === 'number' && typeof evt.offsetY === 'number') {
      return { x: evt.offsetX, y: evt.offsetY };
    }
    const rect = canvas.getBoundingClientRect();
    const x = evt.clientX - rect.left;
    const y = evt.clientY - rect.top;
    return { x, y };
  }

  function findMarkerIndexNear(dx, dy, tolerancePx = 20) {
    // dx, dy are display-space coords
    let bestIdx = -1;
    let bestDist = Infinity;
    for (let i = 0; i < state.markersDisplay.length; i++) {
      const m = state.markersDisplay[i];
      const d = Math.hypot(m.x - dx, m.y - dy);
      if (d < tolerancePx && d < bestDist) {
        bestDist = d;
        bestIdx = i;
      }
    }
    return bestIdx;
  }

  
  function onPointerDown(evt) {
    if (!state.image) return;
    evt.preventDefault();
    
    const rect = overlayCanvas.getBoundingClientRect();
    const x = evt.clientX - rect.left;
    const y = evt.clientY - rect.top;
    
    // Start drag selection - clear existing selections and start new one
    state.markersDisplay = [];
    state.dragState.isDragging = true;
    state.dragState.startX = x;
    state.dragState.startY = y;
    
    overlayCanvas.style.cursor = 'crosshair';
    document.body.style.cursor = 'crosshair'; // Show crosshair even outside canvas
    // Do not open inline prompt; use top-row editor instead
    ensurePromptElement();
    hidePrompt();
  }

  function onPointerMove(evt) {
    if (!state.image || !state.dragState.isDragging) return;
    
    const rect = overlayCanvas.getBoundingClientRect();
    let x = evt.clientX - rect.left;
    let y = evt.clientY - rect.top;
    
    // Constrain coordinates to canvas bounds while still allowing out-of-bounds dragging
    x = Math.max(-100, Math.min(state.displayWidth + 100, x));
    y = Math.max(-100, Math.min(state.displayHeight + 100, y));
    
    // Calculate selection rectangle
    const startX = state.dragState.startX;
    const startY = state.dragState.startY;
    
    // Calculate initial rectangle
    let left = Math.min(startX, x);
    let top = Math.min(startY, y);
    let width = Math.abs(x - startX);
    let height = Math.abs(y - startY);
    
    // Constrain final rectangle to canvas bounds with 5px margin
    const margin = 5; // Ensure box is always 5px inside the image
    left = Math.max(margin, left);
    top = Math.max(margin, top);
    width = Math.min(width, state.displayWidth - left - margin);
    height = Math.min(height, state.displayHeight - top - margin);
    
    // Update the selection box (limit to one selection)
    state.markersDisplay = [{
      x: left,
      y: top,
      width: width,
      height: height
    }];
    
    drawOverlay();
    const box = state.markersDisplay[0];
    positionPromptWithinBox(box); // keep internal positioning updated if needed
  }

  function onPointerUp() {
    if (state.dragState.isDragging) {
      state.dragState.isDragging = false;
      overlayCanvas.style.cursor = 'crosshair';
      document.body.style.cursor = 'default'; // Reset body cursor
      
      // Remove very small selections (likely accidental clicks)
      if (state.markersDisplay.length > 0) {
        const box = state.markersDisplay[0];
        if (box.width < 10 || box.height < 10) {
          state.markersDisplay = [];
          drawOverlay();
          clearPrompt();
        }
      }
    }
  }

  function addToHistory(base64Image) {
    // Remove any redo history
    if (state.historyIndex < state.history.length - 1) {
      state.history = state.history.slice(0, state.historyIndex + 1);
    }
    
    // Add new state
    state.history.push(base64Image);
    state.historyIndex++;
    
    // Update buttons
    updateUndoRedoButtons();
  }
  
  function updateUndoRedoButtons() {
    const undoBtn = document.getElementById('undoBtn');
    const redoBtn = document.getElementById('redoBtn');
    
    undoBtn.disabled = state.historyIndex <= 0;
    redoBtn.disabled = state.historyIndex >= state.history.length - 1;
  }
  
  function onUndo() {
    if (state.historyIndex > 0) {
      state.historyIndex--;
      loadImageFromHistory();
    }
  }
  
  function onRedo() {
    if (state.historyIndex < state.history.length - 1) {
      state.historyIndex++;
      loadImageFromHistory();
    }
  }
  
  function loadImageFromHistory() {
    const base64Image = state.history[state.historyIndex];
    const img = new Image();
    img.onload = () => {
      state.image = img;
      drawImage();
      state.markersDisplay = []; // Clear any selections
      drawOverlay();
      clearPrompt();
      updateUndoRedoButtons();
    };
    img.src = base64Image;
  }


  async function onSubmit() {
    if (!state.file) {
      alert('Please upload an image first.');
      return;
    }
    if (state.isSubmitting) return;
    if (!state.markersDisplay.length) {
      if (!confirm('No markers placed. Proceed anyway?')) return;
    }
    // Mark submitting, disable and show loading spinner in the button
    state.isSubmitting = true;
    if (!submitBtn.dataset.originalHtml) {
      submitBtn.dataset.originalHtml = submitBtn.innerHTML;
    }
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="bx bx-loader-alt bx-spin" aria-hidden="true"></i><span style="margin-left:8px">Processing…</span>';
    submitBtn.setAttribute('aria-busy', 'true');
    submitStatus.textContent = 'Preparing current image for processing...';
    try {
      // Prepare export canvas; enforce max dimension 1024 on output
      const origW = state.naturalWidth;
      const origH = state.naturalHeight;
      const maxW = MAX_IMAGE_WIDTH;
      const maxH = MAX_IMAGE_HEIGHT;
      const scaleToFit = Math.min(1, maxW / origW, maxH / origH);
      const outW = Math.max(1, Math.round(origW * scaleToFit));
      const outH = Math.max(1, Math.round(origH * scaleToFit));

      const canvas = document.createElement('canvas');
      const ctx = canvas.getContext('2d');
      canvas.width = outW;
      canvas.height = outH;
      
      // Draw the current state.image (which gets updated after each edit) scaled to output size
      ctx.drawImage(state.image, 0, 0, outW, outH);

      // Draw the red dashed selection box directly onto the export canvas
      // so the server does not need an image library.
      const naturalMarkers = state.markersDisplay.map(box => ({
        x: Math.round(box.x * state.scaleX),
        y: Math.round(box.y * state.scaleY),
        width: Math.round(box.width * state.scaleX),
        height: Math.round(box.height * state.scaleY)
      }));
      if (naturalMarkers.length > 0) {
        const sx = outW / origW;
        const sy = outH / origH;
        const m0 = naturalMarkers[0];
        const m = { x: Math.round(m0.x * sx), y: Math.round(m0.y * sy), width: Math.round(m0.width * sx), height: Math.round(m0.height * sy) };
        ctx.save();
        ctx.strokeStyle = '#ff0000';
        ctx.lineWidth = 3;
        ctx.setLineDash([10, 5]);
        ctx.strokeRect(m.x, m.y, m.width, m.height);
        ctx.restore();
      }
      
      // Convert canvas to blob
      // Convert canvas to JPEG (~0.85) to reduce payload; strips alpha/EXIF implicitly
      const canvasBlob = await new Promise(resolve => {
        try {
          const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
          // Convert dataURL to Blob
          const byteString = atob(dataUrl.split(',')[1]);
          const mimeString = dataUrl.split(',')[0].split(':')[1].split(';')[0];
          const ab = new ArrayBuffer(byteString.length);
          const ia = new Uint8Array(ab);
          for (let i = 0; i < byteString.length; i++) ia[i] = byteString.charCodeAt(i);
          resolve(new Blob([ab], { type: mimeString }));
        } catch (_) {
          canvas.toBlob(resolve, 'image/jpeg', 0.85);
        }
      });
      
      submitStatus.textContent = 'Sending to AI for processing...';
      
      const fd = new FormData();
      fd.append('image', canvasBlob, 'current-image.jpg');
      // Also send coordinates scaled to the exported image size for reference
      const expSx = outW / origW;
      const expSy = outH / origH;
      const scaledMarkers = naturalMarkers.map(b => ({
        x: Math.round(b.x * expSx),
        y: Math.round(b.y * expSy),
        width: Math.round(b.width * expSx),
        height: Math.round(b.height * expSy)
      }));
      fd.append('markers', JSON.stringify(scaledMarkers));
      // Include editable selection prompt
      fd.append('prompt', (state.selectionPromptText || 'delete'));

      const resp = await fetch('./api/process.php', { method: 'POST', body: fd });
      const ct = resp.headers.get('content-type') || '';
      const mode = resp.headers.get('x-processing-mode') || 'unknown';
      if (!resp.ok) {
        const msg = ct.includes('application/json') ? (await resp.json()).error : `HTTP ${resp.status}`;
        throw new Error(msg || 'Server error');
      }
      if (ct.includes('application/json')) {
        const data = await resp.json();
        if (data && data.error) throw new Error(data.error);
        
        // Handle our new JSON response with result image
        if (data.success && data.resultImage) {
          // Convert base64 to blob for result image
          const resultBin = atob(data.resultImage);
          const resultArray = new Uint8Array(resultBin.length);
          for (let i = 0; i < resultBin.length; i++) {
            resultArray[i] = resultBin.charCodeAt(i);
          }
          const resultBlob = new Blob([resultArray], { type: data.mimeType });
          
          if (lastResultUrl) {
            try { URL.revokeObjectURL(lastResultUrl); } catch (_) {}
          }
          const resultUrl = URL.createObjectURL(resultBlob);
          lastResultUrl = resultUrl;
          
          // Update canvas with the edited image for further editing
          const editedImg = new Image();
          editedImg.onload = () => {
            state.image = editedImg;
            state.naturalWidth = editedImg.naturalWidth;
            state.naturalHeight = editedImg.naturalHeight;
            layoutCanvases();
            drawImage();
            // Clear markers since we've applied the edit
            state.markersDisplay = [];
            drawOverlay();
            clearPrompt();
            
            // Convert blob URL to base64 for history
            const historyCanvas = document.createElement('canvas');
            historyCanvas.width = editedImg.naturalWidth;
            historyCanvas.height = editedImg.naturalHeight;
            const historyCtx = historyCanvas.getContext('2d');
            historyCtx.drawImage(editedImg, 0, 0);
            addToHistory(historyCanvas.toDataURL());
          };
          editedImg.src = resultUrl;
          
          
          
          // Auto-download functionality (since download button was removed)
          // Users can right-click on the canvas to save if needed
          
          submitStatus.textContent = `Done (${mode}) - Canvas updated with edited image`;
          return;
        }
        
        throw new Error('Unexpected JSON response');
      } else {
        throw new Error('Unknown response type');
      }
    } catch (err) {
      console.error(err);
      alert(err.message || 'Something went wrong. Please try again.');
      submitStatus.textContent = 'Error';
    } finally {
      // Restore button
      submitBtn.disabled = false;
      state.isSubmitting = false;
      submitBtn.innerHTML = submitBtn.dataset.originalHtml || 'Delete Them!';
      submitBtn.removeAttribute('aria-busy');
    }
  }

  function onRevert() {
    if (!state.history[0]) return; // No original image to revert to
    
    // Load the original image from history
    state.historyIndex = 0;
    loadImageFromHistory();
    
    // Clear any selections
    state.markersDisplay = [];
    drawOverlay();
    
    // Update status
    submitStatus.textContent = 'Reverted to original image';
  }

  function onResize() {
    if (!state.image) return;
    const currentViewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
    // Ignore height-only changes (mobile URL bar hide/show). Recalc only on width changes.
    if (Math.abs(currentViewportWidth - lastViewportWidth) < 2) return;
    lastViewportWidth = currentViewportWidth;
    const oldW = state.displayWidth || overlayCanvas.clientWidth || 1;
    const oldH = state.displayHeight || overlayCanvas.clientHeight || 1;
    const prevMarkers = state.markersDisplay.map(m => ({ ...m }));
    layoutCanvases();
    drawImage();
    const newW = state.displayWidth || overlayCanvas.clientWidth || oldW;
    const newH = state.displayHeight || overlayCanvas.clientHeight || oldH;
    const sx = newW / oldW;
    const sy = newH / oldH;
    const sr = (sx + sy) / 2;
    state.markersDisplay = prevMarkers.map(m => ({ x: m.x * sx, y: m.y * sy, radius: m.radius * sr }));
    drawOverlay();
    positionPromptWithinBox(state.markersDisplay[0]);
  }

  // UI bindings - iOS-friendly event handling
  browseBtn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    fileInput.click();
  });
  browseBtn.addEventListener('touchend', (e) => {
    e.preventDefault();
    e.stopPropagation();
    fileInput.click();
  });
  uploadMessage.addEventListener('click', (e) => {
    if (e.target === browseBtn) return;
    e.preventDefault();
    fileInput.click();
  });
  uploadMessage.addEventListener('touchend', (e) => {
    if (e.target === browseBtn) return;
    e.preventDefault();
    fileInput.click();
  });
  fileInput.addEventListener('change', (e) => {
    const file = e.target.files && e.target.files[0];
    if (!file) return; // User canceled; do nothing
    onFileSelected(file);
  });
  enableDnD();

  // Toggle custom prompt row
  if (editPromptBtn && customPromptRow && customPromptInput) {
    function toggleCustomPrompt(e) {
      e.preventDefault();
      const isOpen = !customPromptRow.classList.contains('hidden');
      if (isOpen) {
        customPromptRow.classList.add('hidden');
        editPromptBtn.setAttribute('aria-expanded', 'false');
        state.isCustomPromptOpen = false;
      } else {
        customPromptRow.classList.remove('hidden');
        editPromptBtn.setAttribute('aria-expanded', 'true');
        state.isCustomPromptOpen = true;
        // Pre-fill
        customPromptInput.value = (state.selectionPromptText && state.selectionPromptText !== 'delete') ? state.selectionPromptText : '';
        setTimeout(() => { try { customPromptInput.focus(); } catch(_) {} }, 0);
      }
    }
    // Add both click and touchend for iOS
    addButtonEvents(editPromptBtn, toggleCustomPrompt);

    customPromptInput.addEventListener('input', () => {
      const v = (customPromptInput.value || '').trim();
      state.selectionPromptText = v || 'delete';
    });
  }

  // Mouse event listeners
  overlayCanvas.addEventListener('mousedown', onPointerDown);
  document.addEventListener('mousemove', onPointerMove); // Listen on document for out-of-bounds dragging
  document.addEventListener('mouseup', onPointerUp);
  
  // Touch event listeners for mobile
  overlayCanvas.addEventListener('touchstart', (e) => {
    e.preventDefault(); // Prevent scrolling while drawing
    const touch = e.touches[0];
    onPointerDown({ 
      clientX: touch.clientX,
      clientY: touch.clientY,
      preventDefault: () => {}
    });
  });
  
  document.addEventListener('touchmove', (e) => {
    if (!state.dragState.isDragging) return; // allow normal UI interactions
    e.preventDefault(); // Prevent scrolling while drawing
    const touch = e.touches[0];
    onPointerMove({
      clientX: touch.clientX,
      clientY: touch.clientY,
      preventDefault: () => {}
    });
  });
  
  document.addEventListener('touchend', (e) => {
    if (!state.dragState.isDragging) return; // do not block taps on inputs/buttons
    e.preventDefault();
    onPointerUp();
  });
  
  document.addEventListener('touchcancel', (e) => {
    if (!state.dragState.isDragging) return;
    e.preventDefault();
    onPointerUp();
  });
  // Add both click and touchend events for all buttons to ensure mobile responsiveness
  function addButtonEvents(element, handler) {
    element.addEventListener('click', handler);
    element.addEventListener('touchend', (e) => {
      e.preventDefault();
      handler(e);
    });
  }

  addButtonEvents(document.getElementById('undoBtn'), onUndo);
  addButtonEvents(document.getElementById('redoBtn'), onRedo);
  // Debounced submit: ignore if a request is already in flight
  addButtonEvents(submitBtn, () => {
    if (state.isSubmitting) return;
    onSubmit();
  });
  addButtonEvents(document.getElementById('revertBtn'), onRevert);
  
  // New upload and download buttons
  addButtonEvents(document.getElementById('uploadNewBtn'), () => {
    // Do NOT revert yet; only trigger the picker. We'll update state only after a file is chosen.
    try { fileInput.value = ''; } catch (_) {}
    fileInput.click();
  });
  
  addButtonEvents(document.getElementById('downloadCurrentBtn'), async () => {
    // Render current image state to a temporary canvas with enforced caps
    const origW = state.naturalWidth;
    const origH = state.naturalHeight;
    const maxW = MAX_IMAGE_WIDTH;
    const maxH = MAX_IMAGE_HEIGHT;
    const scaleToFit = Math.min(1, maxW / origW, maxH / origH);
    const outW = Math.max(1, Math.round(origW * scaleToFit));
    const outH = Math.max(1, Math.round(origH * scaleToFit));

    const canvas = document.createElement('canvas');
    canvas.width = outW;
    canvas.height = outH;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(state.image, 0, 0, outW, outH);

    // Prefer Blob for sharing/downloading (JPEG 100%)
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 1.0));
    if (!blob) return;

    // Use Web Share API only on mobile (iOS/Android). Desktop should directly download.
    const isMobile = (() => {
      if (navigator.userAgentData && typeof navigator.userAgentData.mobile === 'boolean') {
        return navigator.userAgentData.mobile;
      }
      const ua = navigator.userAgent || '';
      return /Android|iPhone|iPad|iPod|Mobile|Silk|Kindle|BlackBerry|Opera Mini|IEMobile/i.test(ua);
    })();

    if (isMobile) {
      try {
        const file = new File([blob], 'edited-image.jpg', { type: 'image/jpeg' });
        if (navigator.canShare && navigator.canShare({ files: [file] })) {
          await navigator.share({ files: [file], title: 'Edited photo' });
          return;
        }
      } catch (_) {
        // fall through to download
      }
    }

    // Fallback 1: download via blob URL (desktop, some Android)
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'edited-image.jpg';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);

    // Fallback 2: open data URL in a new tab (iOS older versions → user taps and Save Image)
    // const dataUrl = canvas.toDataURL('image/jpeg', 1.0);
    // window.open(dataUrl, '_blank');
  });


  window.addEventListener('resize', onResize);
})();


