import * as model from './preview-model.js?v=46';
const { number, clamp } = model;

export default class FrameUpPreview extends HTMLElement {
  static selection = model.selection;
  static appearance = model.appearance;

  connectedCallback() {
    if (this.started) return;
    this.started = true;
    this.controller = new AbortController();
    this.cleanups = [];
    this.init().catch((error) => {
      this.spatial?.dispose();
      this.restoreGallery();
      console.warn('Frame Up spatial preview could not be initialized.', error);
    });
  }

  disconnectedCallback() {
    if (this.relocating) return;
    // Moving a gallery during a theme update must not destroy its renderer.
    queueMicrotask(() => {
      if (!this.isConnected) this.dispose();
    });
  }

  dispose() {
    this.clearViewTransition();
    this.controller?.abort();
    this.cleanups?.forEach((cleanup) => cleanup());
    cancelAnimationFrame(this.animation);
    clearInterval(this.variantPoll);
    clearTimeout(this.changeTimer);
    this.resizeObserver?.disconnect();
    this.themeObserver?.disconnect();
    this.spatial?.dispose();
    this.restoreGallery();
    this.started = false;
  }

  async init() {
    const json = this.querySelector('[data-frame-up-product]');
    if (!json) return;
    this.product = JSON.parse(json.textContent);
    if (!this.product.type || !this.product.image) return;
    const proxyPath = this.dataset.proxyPath || '/apps/frame-up';
    if (!proxyPath.startsWith('/') || proxyPath.startsWith('//')) return;
    const url = new URL(`${proxyPath.replace(/\/$/, '')}/preview`, location.origin);
    url.searchParams.set('product_id', this.product.id);
    url.searchParams.set('product_type', this.product.type);
    const provided = this.querySelector('[data-frame-up-config]');
    let result;
    if (provided) {
      result = JSON.parse(provided.textContent);
    } else {
      const response = await fetch(url, { signal: this.controller.signal, headers: { Accept: 'application/json' } });
      if (!response.ok) return;
      result = await response.json();
    }
    if (!result.enabled || result.data?.product_id !== `gid://shopify/Product/${this.product.id}`) return;
    this.config = result.data;
    this.stage = this.querySelector('.frame-up-stage');
    this.roomImage = this.querySelector('.frame-up-room');
    if (this.roomImage) this.listen(this.roomImage, 'load', () => { if (this.gallery) this.render(); });
    this.toggle = this.querySelector('.frame-up-view-toggle');
    this.description = this.querySelector('[data-frame-up-description]');
    this.variant = this.product.selectedVariant;
    this.values = [...(this.variant?.options ?? [])];
    const { default: SpatialPreview } = await import(this.dataset.webglUrl);
    this.spatial = new SpatialPreview(this.querySelector('.frame-up-object'));
    const imageUrl = this.product.image.startsWith('//') ? `https:${this.product.image}` : this.product.image;
    if (!await this.spatial.load(imageUrl, this.dataset.environmentUrl, this.dataset.surfaceTextureUrl, this.controller.signal)) return;
    if (this.dataset.roomScene !== 'procedural' && (this.roomImage?.currentSrc || this.roomImage?.src)) {
      try {
        await this.spatial.loadRoom(this.roomImage.currentSrc || this.roomImage.src);
        this.stage.dataset.roomRenderer = 'webgl';
      } catch (error) {
        console.warn('Frame Up room background could not be loaded.', error);
      }
    }
    if (this.dataset.roomScene === 'procedural') this.stage.dataset.roomRenderer = 'webgl';
    this.stage.dataset.renderer = 'webgl';
    if (!this.isConnected || this.controller.signal.aborted) return;
    this.gallery = this.findGallery();
    if (!this.gallery) return;
    this.scope = this.gallery.closest('.shopify-section') || this.gallery.closest('section') || document.querySelector('main') || document;
    this.relocating = true;
    this.gallery.prepend(this);
    this.relocating = false;
    this.readOptions();
    this.mode = 'spatial';
    this.currentTilt = { x: 0, y: 0 };
    this.targetTilt = { ...this.currentTilt };
    this.reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
    this.zoom = 1;
    this.interaction = new model.PreviewInteraction(this.dataset);
    this.listen(this.stage, 'wheel', (event) => {
      if (event.target.closest('button, a')) return;
      event.preventDefault();
      this.openSpatialCloseUp();
      this.updateZoomFocus(event);
      const delta = event.deltaY * (event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? this.stage.clientHeight : 1);
      this.interaction.wheel(delta);
      this.zoom = this.interaction.target.zoom;
      this.animateTilt();
    }, { passive: false });
    const touchDistance = (touches) => Math.hypot(touches[0].clientX - touches[1].clientX, touches[0].clientY - touches[1].clientY);
    this.listen(this.stage, 'touchstart', (event) => {
      if (event.touches.length !== 2 || this.mode !== 'spatial') return;
      this.pinch = { distance: touchDistance(event.touches), zoom: this.zoom };
    }, { passive: true });
    this.listen(this.stage, 'touchmove', (event) => {
      if (event.touches.length !== 2 || !this.pinch || this.mode !== 'spatial') return;
      event.preventDefault();
      if (this.pinch.distance > 0) this.setZoom(this.pinch.zoom * touchDistance(event.touches) / this.pinch.distance);
    }, { passive: false });
    for (const eventName of ['touchend', 'touchcancel']) this.listen(this.stage, eventName, () => { this.pinch = null; });
    this.listen(this.stage, 'click', (event) => this.toggleZoom(event));
    this.listen(this.stage, 'keydown', (event) => {
      if (event.target !== this.stage || this.mode !== 'spatial') return;
      if (['Enter', ' '].includes(event.key)) { event.preventDefault(); this.toggleZoom(event); }
      else if (event.key === 'Escape') { this.interaction.magnifierEnabled = false; this.setZoom(1); }
    });
    // Reset dependent controls before the theme reads its selected option IDs.
    this.listen(this.scope, 'change', (event) => this.resetPrintTypeOptions(event), { capture: true });
    for (const name of ['pointerenter', 'pointermove']) this.listen(this.stage, name, (event) => {
      if (this.mode !== 'spatial' || event.pointerType === 'touch') return;
      this.updateZoomFocus(event);
      this.zoom = this.interaction.target.zoom;
      this.animateTilt();
    });
    for (const name of ['pointerleave', 'pointercancel']) this.listen(this.stage, name, () => {
      this.interaction.leave(); this.zoom = this.interaction.target.zoom; this.animateTilt();
    });
    this.listen(this.toggle, 'click', () => {
      this.beginViewTransition();
      this.mode = this.mode === 'spatial' ? 'room' : 'spatial';
      this.stage.dataset.mode = this.mode;
      this.toggle.textContent = this.mode === 'room' ? 'View close up' : 'View in a room';
      this.toggle.setAttribute('aria-pressed', String(this.mode === 'room'));
      this.interaction.reset();
      this.setZoom(1);
      this.render();
    });
    this.listen(document, 'change', () => {
      clearTimeout(this.changeTimer);
      this.changeTimer = setTimeout(() => { this.readOptions(); this.filterPrintTypeOptions(); this.render(); }, 80);
    });
    this.listen(document, 'shopify:product:select', (event) => this.onProductSelect(event));
    // Dawn replaces variant-selects asynchronously, briefly keeping both the old
    // and new selectors in the DOM. Reconcile again when that update completes.
    this.themeObserver = new MutationObserver((records) => {
      if (!records.some((record) => !this.contains(record.target))) return;
      clearTimeout(this.changeTimer);
      this.changeTimer = setTimeout(() => { this.readOptions(); this.filterPrintTypeOptions(); this.render(); }, 80);
    });
    this.themeObserver.observe(this.scope, { childList: true, subtree: true });
    for (const eventName of ['variant:change', 'variant-change', 'product:variant-change', 'product:change']) {
      this.listen(document, eventName, (event) => {
        const variant = event.detail?.variant ?? event.detail?.productVariant;
        if (!variant || !Array.isArray(variant.options)) return;
        if (variant.product_id && String(variant.product_id) !== this.product.id) return;
        if (!variant.product_id && !this.scope.contains(event.target)) return;
        this.variant = variant;
        this.values = variant.options;
        this.render();
      });
    }
    // Themes can update their variant ID programmatically without a DOM change event.
    this.variantPoll = setInterval(() => {
      if (document.hidden || !this.isConnected) return;
      const formId = this.scope.querySelector('form[action*="/cart/add"] [name="id"]')?.value;
      if (!formId || formId === this.formVariantId) return;
      this.formVariantId = formId;
      this.readOptions();
      this.render();
    }, 500);
    this.resizeObserver = new ResizeObserver(() => this.render());
    this.resizeObserver.observe(this.gallery);
    this.filterPrintTypeOptions();
    this.render();
  }

  listen(target, type, callback, options) {
    target.addEventListener(type, callback, options);
    this.cleanups.push(() => target.removeEventListener(type, callback, options));
  }

  setZoom(value) {
    if (this.interaction) {
      this.interaction.setZoom(value);
      this.zoom = this.interaction.target.zoom;
      this.animateTilt();
      return;
    }
    this.zoom = clamp(number(value, 1), 1, 3);
    if (this.stage) this.stage.dataset.zoomed = String(this.zoom > 1);
    this.paintSpatial();
  }

  toggleZoom(event) {
    if (event.target.closest('button, a')) return;
    this.openSpatialCloseUp();
    this.updateZoomFocus(event);
    if (this.interaction) {
      this.interaction.toggleMagnifier();
      this.zoom = this.interaction.target.zoom;
      this.animateTilt();
      return;
    }
    const zoomingOut = this.zoom > 1;
    cancelAnimationFrame(this.animation);
    this.animation = null;
    this.currentTilt = { x: 0, y: 0 };
    this.targetTilt = { x: 0, y: 0 };
    this.setZoom(zoomingOut ? 1 : 2);
    if (zoomingOut && this.zoomFocus && this.reducedMotion && !this.reducedMotion.matches) {
      this.targetTilt = { x: 0, y: -this.zoomFocus.x * 15 };
      this.animateTilt();
    }
  }

  openSpatialCloseUp() {
    if (this.mode !== 'room') return;
    this.beginViewTransition();
    this.mode = 'spatial';
    this.stage.dataset.mode = 'spatial';
    this.toggle.textContent = 'View in a room';
    this.toggle.setAttribute('aria-pressed', 'false');
    this.render();
  }

  clearViewTransition() {
    this.viewTransition = null;
  }

  beginViewTransition() {
    this.clearViewTransition();
    if (this.reducedMotion?.matches) return;
    if (!this.spatial?.captureViewState) return;
    this.viewTransition = { from: this.spatial.captureViewState(), elapsed: 0, progress: 0 };
  }

  stepViewTransition(seconds) {
    if (!this.viewTransition) return false;
    this.viewTransition.elapsed += Math.max(0, Math.min(seconds, .05));
    const progress = Math.min(1, this.viewTransition.elapsed / .48);
    this.viewTransition.progress = progress * progress * (3 - 2 * progress);
    if (progress === 1) { this.clearViewTransition(); return false; }
    return true;
  }

  updateZoomFocus(event) {
    if (!Number.isFinite(event.clientX) || !Number.isFinite(event.clientY)) return;
    const rect = this.stage.getBoundingClientRect();
    if (rect.width <= 0 || rect.height <= 0) return;
    this.zoomFocus = {
      x: clamp((event.clientX - rect.left) / rect.width * 2 - 1, -1, 1),
      y: clamp(1 - (event.clientY - rect.top) / rect.height * 2, -1, 1),
    };
    this.interaction?.pointer(this.zoomFocus.x, this.zoomFocus.y);
  }

  optionName(control) {
    return control.dataset?.optionName || control.closest('fieldset')?.querySelector('legend')?.textContent.trim().replace(/:\s*$/, '')
      || this.product.optionNames.find((name, index) => [name, `options[${name}]`, `${name}-${index + 1}`].includes(control.name?.trim()));
  }

  resetPrintTypeOptions(event) {
    const control = event.target;
    if (this.optionName(control) !== 'Print Type') return;
    const print = this.config.print_types.find((row) => row.option === control.value);
    if (!print) return;
    this.pendingPrintType = print.option;
    const picker = control.closest('variant-picker, variant-selects') || this.scope;
    for (const [name, value] of [['Sizes', print.sizes[0]?.option], ['Mounts and Frames', print.mounts[0]?.label]]) {
      if (!value) continue;
      for (const input of picker.querySelectorAll('select, input[type="radio"]')) {
        if (this.optionName(input) !== name) continue;
        if (input.type === 'radio') {
          input.checked = input.value === value;
          if (input.dataset.currentChecked !== undefined) input.dataset.currentChecked = String(input.checked);
        } else {
          input.value = value;
          for (const option of input.options) option.toggleAttribute('selected', option.value === value);
        }
      }
      this.values[this.product.optionNames.indexOf(name)] = value;
    }
    this.values[this.product.optionNames.indexOf('Print Type')] = print.option;
    this.filterPrintTypeOptions(print);
  }

  filterPrintTypeOptions(print = this.config.print_types.find((row) => row.option === this.values[this.product.optionNames.indexOf('Print Type')])) {
    if (!print) return;
    for (const input of this.scope.querySelectorAll('select, input[type="radio"]')) {
      const name = this.optionName(input);
      const allowed = name === 'Mounts and Frames' ? print.mounts.map((row) => row.label) : null;
      if (!allowed) continue;
      for (const option of input.type === 'radio' ? [input] : input.options) {
        const hidden = !allowed.includes(option.value);
        const item = input.type === 'radio' ? option.closest('label') || this.scope.querySelector(`label[for="${CSS.escape(option.id)}"]`) : option;
        if (item) {
          item.hidden = hidden;
          item.toggleAttribute('data-frame-up-option-hidden', hidden);
        }
        if (hidden && !option.disabled) { option.disabled = true; option.dataset.frameUpDisabled = 'true'; }
        else if (!hidden && option.dataset.frameUpDisabled) { option.disabled = false; delete option.dataset.frameUpDisabled; }
      }
    }
  }

  async onProductSelect(event) {
    if (!this.scope.contains(event.target) || !event.promise) return;
    const request = this.selectionRequest = (this.selectionRequest ?? 0) + 1;
    try {
      const result = await event.promise;
      if (!this.isConnected || request !== this.selectionRequest) return;
      if (String(result.detail?.productId) !== this.product.id) return;
      const variant = result.detail?.resource;
      if (!Array.isArray(variant?.options)) return;
      this.variant = variant;
      this.values = [...variant.options];
      this.pendingPrintType = null;
      this.filterPrintTypeOptions();
      this.render();
    } catch (error) {
      if (error.name !== 'AbortError') console.warn('Frame Up variant update could not be loaded.', error);
    }
  }

  findGallery() {
    const main = document.querySelector('main') || document;
    if (this.dataset.mediaSelector) {
      try { return main.querySelector(this.dataset.mediaSelector); } catch { return null; }
    }
    // Horizon replaces media-gallery on every selection. Its surrounding media
    // column remains mounted, so the preview must live outside that element.
    for (const selector of ['.product__media-wrapper', '.product-information__media', '[data-testid="product-information-media"]', '[data-product-media-gallery]', 'media-gallery', '[id^="MediaGallery-"]', '.product-gallery', '.product-media']) {
      const element = main.querySelector(selector);
      if (element && !this.contains(element)) return element;
    }
    return null;
  }

  readOptions() {
    if (!this.scope) return;
    const formId = this.scope.querySelector('form[action*="/cart/add"] [name="id"]')?.value;
    for (const json of this.scope.querySelectorAll('variant-selects [data-selected-variant], variant-picker script[type="application/json"]')) {
      try {
        const variant = JSON.parse(json.textContent);
        if (variant && String(variant.id) === formId && Array.isArray(variant.options)) {
          if (this.pendingPrintType && variant.options[this.product.optionNames.indexOf('Print Type')] !== this.pendingPrintType) continue;
          this.variant = variant;
          this.values = [...variant.options];
          this.pendingPrintType = null;
          return;
        }
      } catch { /* A theme can temporarily replace this JSON during a transition. */ }
    }
    this.product.optionNames.forEach((name, index) => {
      const inputs = [...this.scope.querySelectorAll('select, input[type="radio"]')];
      const control = inputs.find((input) => {
        if (input.type === 'radio' && !input.checked) return false;
        const legend = input.closest('fieldset')?.querySelector('legend')?.textContent.trim().replace(/:\s*$/, '');
        return input.dataset?.optionName === name || input.name === `options[${name}]` || input.name.trim() === name || input.name.trim() === `${name}-${index + 1}` || legend === name;
      });
      if (control) this.values[index] = control.value;
    });
  }

  render() {
    if (!this.config || !this.gallery || !this.isConnected) return;
    const requested = FrameUpPreview.selection(this.config, this.product.optionNames, this.values);
    const print = this.config.print_types.find((row) => row.option === this.values[this.product.optionNames.indexOf('Print Type')]);
    const previous = this.paint?.selection;
    const selection = requested || (previous?.print === print ? previous : print?.sizes.length && print?.mounts.length ? {
      print, size: print.sizes[0],
      mount: print.mounts.find((row) => row.label === this.values[this.product.optionNames.indexOf('Mounts and Frames')]) || print.mounts[0],
    } : null);
    if (!selection) { this.restoreGallery(); return; }
    const appearance = FrameUpPreview.appearance(selection);
    if (!appearance) { this.restoreGallery(); return; }
    const selectionKey = JSON.stringify([selection.print.surface.id, selection.size.option, selection.mount.id]);
    if (this.interaction && this.selectionKey && selectionKey !== this.selectionKey) {
      this.interaction.reset(); this.zoom = 1;
    }
    this.selectionKey = selectionKey;
    this.hidden = false;
    this.gallery.setAttribute('data-frame-up-media-host', '');
    const width = number(selection.size.width, this.product.imageWidth);
    const height = number(selection.size.height, this.product.imageHeight);
    if (width <= 0 || height <= 0) { this.restoreGallery(); return; }
    const rect = this.stage.getBoundingClientRect();
    if (rect.width <= 0 || rect.height <= 0) { this.restoreGallery(); return; }
    const aspect = width / height;
    const room = this.mode === 'room';
    const spatialScale = clamp(Math.max(width, height) / 24, .85, 1);
    const roomImageWidth = this.dataset.roomScene === 'procedural' ? rect.width : this.roomImage?.naturalWidth || rect.width;
    const roomImageHeight = this.dataset.roomScene === 'procedural' ? rect.height : this.roomImage?.naturalHeight || rect.height;
    const roomDimensions = room ? model.roomPreviewDimensions(width, height, rect,
      { width: roomImageWidth, height: roomImageHeight }, number(this.dataset.roomWidth, 65),
      clamp(number(this.dataset.roomScale, 1.75), .75, 2)) : null;
    let objectWidth = room ? roomDimensions.width : rect.width * .69 * spatialScale;
    let objectHeight = objectWidth / aspect;
    const maxHeight = rect.height * (room ? .55 : .78);
    if (objectHeight > maxHeight) { objectHeight = maxHeight; objectWidth = objectHeight * aspect; }
    const pxPerInch = objectWidth / width;
    this.stage.dataset.material = appearance.kind;
    this.stage.dataset.surfaceId = String(selection.print.surface.id);
    this.stage.dataset.finish = appearance.finish;
    this.stage.dataset.mount = appearance.mountType || '';
    for (const [key, value] of Object.entries({
      '--object-width': `${objectWidth}px`, '--object-height': `${objectHeight}px`,
      '--frame-depth': `${clamp(appearance.depth * pxPerInch, 1, 45)}px`,
      '--frame-rim': `${appearance.rim * pxPerInch}px`, '--mat-size': `${appearance.mat * pxPerInch}px`,
      '--frame-color': appearance.frameColor, '--mat-color': appearance.matColor,
      '--side-color': appearance.sideColor, '--glass-opacity': String(appearance.glass),
    })) this.stage.style.setProperty(key, value);
    this.paintSpatial(selection, appearance, objectWidth, objectHeight);
    for (const child of this.gallery.children) {
      if (child !== this) child.setAttribute('data-frame-up-original-media', '');
    }
    this.description.textContent = `${requested ? '' : 'The selected combination is unavailable. Showing an available preview: '}${selection.print.surface.label}, ${selection.size.option}, ${selection.mount.label}. ${room ? 'Room view.' : 'Spatial preview. Move your pointer to change the viewing angle.'}`;
  }

  animateTilt() {
    if (this.interaction) {
      if (this.animation || (this.mode !== 'spatial' && !this.viewTransition)) return;
      let previous;
      const tick = (time) => {
        this.animation = null;
        if (!this.isConnected || (this.mode !== 'spatial' && !this.viewTransition)) return;
        const dt = previous === undefined ? 1 / 60 : (time - previous) / 1000;
        previous = time;
        const moving = this.mode === 'spatial' && this.interaction.step(dt, this.reducedMotion.matches);
        const transitioning = this.stepViewTransition(dt);
        this.paintSpatial();
        if (moving || transitioning) this.animation = requestAnimationFrame(tick);
      };
      this.animation = requestAnimationFrame(tick);
      return;
    }
    if (this.animation || this.mode !== 'spatial' || this.reducedMotion.matches) return;
    const tick = () => {
      this.animation = null;
      if (!this.isConnected || this.mode !== 'spatial' || this.reducedMotion.matches) return;
      this.currentTilt.x += (this.targetTilt.x - this.currentTilt.x) * .12;
      this.currentTilt.y += (this.targetTilt.y - this.currentTilt.y) * .12;
      const moving = Math.abs(this.targetTilt.x - this.currentTilt.x) + Math.abs(this.targetTilt.y - this.currentTilt.y) > .02;
      if (!moving) this.currentTilt = { ...this.targetTilt };
      this.paintSpatial();
      if (moving) {
        this.animation = requestAnimationFrame(tick);
      }
    };
    this.animation = requestAnimationFrame(tick);
  }

  restoreGallery() {
    this.hidden = true;
    this.gallery?.removeAttribute('data-frame-up-media-host');
    this.gallery?.querySelectorAll('[data-frame-up-original-media]').forEach((child) => child.removeAttribute('data-frame-up-original-media'));
  }

  paintSpatial(selection, appearance, width, height) {
    if (selection) this.paint = { selection, appearance, width, height };
    if (!this.paint || !this.spatial || this.hidden) return;
    const value = this.paint;
    if (this.interaction) {
      const state = this.interaction.state;
      const room = this.mode === 'room';
      const rect = this.stage.getBoundingClientRect();
      const viewport = room ? { width: rect.width, height: rect.height,
        centerX: clamp(number(this.dataset.roomCenterX, 47), 10, 90) / 100,
        centerY: clamp(number(this.dataset.roomCenterY, 38), 10, 70) / 100,
      } : { width: rect.width, height: rect.height };
      if (this.viewTransition) viewport.transition = this.viewTransition;
      this.stage.dataset.zoomed = String(!room && state.zoom > 1.001);
      this.spatial.update(value.selection, value.appearance, room,
        this.reducedMotion.matches ? { x: 0, y: 0 } : { x: state.tiltX, y: state.tiltY },
        value.width, value.height, room ? 1 : state.zoom, { x: state.panX, y: state.panY },
        viewport);
      return;
    }
    const zoom = this.mode === 'room' ? 1 : this.zoom ?? 1;
    const magnifiedWidth = value.width * zoom;
    const magnifiedHeight = value.height * zoom;
    if (zoom > 1) {
      const rect = this.stage.getBoundingClientRect();
      const focus = this.zoomFocus ?? { x: 0, y: 0 };
      this.stage.style.setProperty('--magnifier-width', `${magnifiedWidth}px`);
      this.stage.style.setProperty('--magnifier-height', `${magnifiedHeight}px`);
      this.stage.style.setProperty('--magnifier-left', `${rect.width / 2 - focus.x * Math.max(0, magnifiedWidth - rect.width) / 2}px`);
      this.stage.style.setProperty('--magnifier-top', `${rect.height / 2 - focus.y * Math.max(0, magnifiedHeight - rect.height) / 2}px`);
    }
    this.spatial.update(value.selection, value.appearance, this.mode === 'room', zoom > 1 || this.reducedMotion?.matches ? { x: 0, y: 0 } : this.currentTilt, magnifiedWidth, magnifiedHeight, 1);
  }
}
