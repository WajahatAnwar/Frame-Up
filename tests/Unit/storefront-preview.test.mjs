import assert from 'node:assert/strict';
import { test } from 'node:test';

globalThis.HTMLElement = class {};
globalThis.cancelAnimationFrame = () => {};
const { default: Preview } = await import('../../public/frame-up-preview/preview-runtime.js');
const { PreviewInteraction, roomPreviewDimensions } = await import('../../public/frame-up-preview/preview-model.js');

test('view changes interpolate from the current camera and reverse without an overlay or jump', () => {
  const preview = new Preview();
  let current = { zoom: 1, roomMix: 0 };
  preview.spatial = { captureViewState: () => ({ ...current }) };
  preview.reducedMotion = { matches: false };
  preview.beginViewTransition();
  assert.deepEqual(preview.viewTransition.from, current);
  assert.equal(preview.viewTransition.progress, 0);
  for (let i = 0; i < 15; i++) preview.stepViewTransition(.016);
  assert.ok(Math.abs(preview.viewTransition.progress - .5) < 1e-8);
  current = { zoom: .8, roomMix: .5 };
  preview.beginViewTransition();
  assert.deepEqual(preview.viewTransition.from, current, 'reversal must start at the visible intermediate view');
  for (let i = 0; i < 31; i++) preview.stepViewTransition(.016);
  assert.equal(preview.viewTransition, null);
  preview.reducedMotion.matches = true;
  preview.beginViewTransition();
  assert.equal(preview.viewTransition, null);
});

test('room artwork is enlarged proportionally and stays inside the clear wall area', () => {
  const viewport = { width: 760, height: 760 };
  const image = { width: 1254, height: 1254 };
  const square = roomPreviewDimensions(16, 16, viewport, image);
  assert.equal(Math.round(square.width), 374);
  assert.equal(Math.round(square.height), 374);
  const small = roomPreviewDimensions(4, 6, viewport, image);
  assert.equal(Math.round(small.width), 94);
  assert.equal(Math.round(small.height), 140);
  const portrait = roomPreviewDimensions(24, 36, viewport, image);
  assert.equal(Math.round(portrait.width), 279);
  assert.equal(Math.round(portrait.height), 418);
  const landscape = roomPreviewDimensions(48, 24, viewport, image);
  assert.equal(Math.round(landscape.width), 517);
  assert.equal(Math.round(landscape.height), 258);
  const original = roomPreviewDimensions(16, 16, viewport, image, 65, 1);
  assert.ok(Math.abs(square.width / original.width - 2) < 1e-8);
});

test('the runtime damps zoom through animation frames while retaining a fixed viewport', () => {
  const preview = new Preview();
  preview.interaction = new PreviewInteraction();
  preview.mode = 'spatial';
  preview.isConnected = true;
  preview.hidden = false;
  preview.reducedMotion = { matches: false };
  preview.stage = { dataset: {}, getBoundingClientRect: () => ({ width: 600, height: 600 }) };
  const draws = [];
  preview.spatial = { update: (...args) => draws.push(args) };
  preview.paintSpatial({}, {}, 300, 450);
  const frames = [];
  const originalFrame = globalThis.requestAnimationFrame;
  globalThis.requestAnimationFrame = (callback) => { frames.push(callback); return 1; };
  try {
    preview.setZoom(3);
    assert.equal(preview.interaction.state.zoom, 1, 'wheel/click target must not jump the camera');
    for (let i = 0; frames.length && i < 300; i++) frames.shift()(i * 1000 / 60);
    assert.equal(preview.interaction.state.zoom, 3);
    for (const draw of draws) assert.deepEqual(draw[8], { width: 600, height: 600 });
    assert.ok(draws[1][6] > 1 && draws[1][6] < 3);
  } finally { globalThis.requestAnimationFrame = originalFrame; }
});

test('clicking room artwork opens the spatial magnifier at the configured limit, and the next click restores normal zoom', () => {
  const preview = new Preview();
  preview.interaction = new PreviewInteraction({ maxZoom: 4 });
  preview.mode = 'room';
  preview.stage = { dataset: {}, getBoundingClientRect: () => ({ left: 0, top: 0, width: 600, height: 600 }) };
  let pressed;
  preview.toggle = { setAttribute: (name, value) => { pressed = value; } };
  let renders = 0;
  preview.render = () => { renders++; };
  preview.animateTilt = () => {};
  const event = { clientX: 500, clientY: 100, target: { closest: () => null } };

  preview.toggleZoom(event);

  assert.equal(preview.mode, 'spatial');
  assert.equal(preview.stage.dataset.mode, 'spatial');
  assert.equal(preview.toggle.textContent, 'View in a room');
  assert.equal(pressed, 'false');
  assert.equal(renders, 1);
  assert.equal(preview.interaction.target.zoom, 4);
  assert.equal(preview.interaction.state.zoom, 1, 'click magnification must remain damped');
  assert.ok(preview.interaction.target.panX > 0);
  preview.toggleZoom(event);
  assert.equal(preview.interaction.target.zoom, 1);
  assert.equal(preview.interaction.target.panX, 0);
});

test('exiting the inline magnifier returns smoothly to the cursor hover angle', () => {
  const preview = new Preview();
  preview.mode = 'spatial';
  preview.zoom = 2;
  preview.zoomFocus = { x: .5, y: -.5 };
  preview.reducedMotion = { matches: false };
  preview.stage = { dataset: {} };
  preview.paintSpatial = () => {};
  let animated = false;
  preview.animateTilt = () => { animated = true; };
  preview.toggleZoom({ target: { closest: () => null } });
  assert.equal(preview.zoom, 1);
  assert.deepEqual(preview.currentTilt, { x: 0, y: 0 });
  assert.deepEqual(preview.targetTilt, { x: 0, y: -7.5 });
  assert.equal(animated, true);
});

test('magnifier follows the cursor across the full preview area', () => {
  const preview = new Preview();
  preview.stage = { getBoundingClientRect: () => ({ left: 100, top: 50, width: 200, height: 400 }) };
  preview.updateZoomFocus({ clientX: 250, clientY: 150 });
  assert.deepEqual(preview.zoomFocus, { x: .5, y: .5 });
  preview.updateZoomFocus({ clientX: 900, clientY: 900 });
  assert.deepEqual(preview.zoomFocus, { x: 1, y: -1 });
});

test('magnification enlarges the retained canvas inside the stage and pans to its edges', () => {
  const preview = new Preview();
  preview.mode = 'spatial';
  preview.zoom = 2;
  preview.zoomFocus = { x: 1, y: -1 };
  preview.hidden = false;
  preview.currentTilt = { x: 5, y: 10 };
  const style = {};
  preview.stage = { getBoundingClientRect: () => ({ width: 400, height: 400 }), style: { setProperty: (key, value) => { style[key] = value; } } };
  let draw;
  preview.spatial = { update: (...args) => { draw = args; } };
  preview.paintSpatial({}, {}, 300, 350);
  assert.equal(style['--magnifier-width'], '600px');
  assert.equal(style['--magnifier-height'], '700px');
  assert.equal(style['--magnifier-left'], '100px');
  assert.equal(style['--magnifier-top'], '350px');
  assert.deepEqual(draw[3], { x: 0, y: 0 });
  assert.equal(draw[4], 600);
  assert.equal(draw[5], 700);
  assert.equal(draw[6], 1);
});

test('leaving hover settles the retained canvas at exactly zero rotation', () => {
  const preview = new Preview();
  preview.mode = 'spatial';
  preview.isConnected = true;
  preview.reducedMotion = { matches: false };
  preview.currentTilt = { x: 6, y: -15 };
  preview.targetTilt = { x: 0, y: 0 };
  const frames = [];
  const originalFrame = globalThis.requestAnimationFrame;
  globalThis.requestAnimationFrame = (callback) => { frames.push(callback); return 1; };
  let painted;
  preview.paintSpatial = () => { painted = { ...preview.currentTilt }; };
  try {
    preview.animateTilt();
    let count = 0;
    while (frames.length && count++ < 100) frames.shift()();
    assert.deepEqual(painted, { x: 0, y: 0 });
    assert.equal(preview.animation, null);
    assert.equal(frames.length, 0);
  } finally {
    globalThis.requestAnimationFrame = originalFrame;
  }
});

test('zoom controls bound magnification and redraw the existing preview', () => {
  const preview = new Preview();
  preview.querySelectorAll = () => [];
  let draws = 0;
  preview.paintSpatial = () => { draws++; };
  preview.setZoom(2);
  assert.equal(preview.zoom, 2);
  preview.setZoom(8);
  assert.equal(preview.zoom, 3);
  preview.setZoom(.2);
  assert.equal(preview.zoom, 1);
  assert.equal(draws, 3);
});

test('clicking artwork toggles close-up zoom without affecting the room button', () => {
  const preview = new Preview();
  preview.mode = 'spatial';
  preview.zoom = 1;
  preview.paintSpatial = () => {};
  preview.stage = { dataset: {} };
  const artwork = { target: { closest: () => null } };
  preview.toggleZoom(artwork);
  assert.equal(preview.zoom, 2);
  assert.equal(preview.stage.dataset.zoomed, 'true');
  preview.toggleZoom({ target: { closest: () => ({}) } });
  assert.equal(preview.zoom, 2);
  preview.toggleZoom(artwork);
  assert.equal(preview.zoom, 1);
  preview.mode = 'room';
  preview.toggle = { setAttribute: () => {} };
  preview.render = () => {};
  preview.toggleZoom(artwork);
  assert.equal(preview.mode, 'spatial');
  assert.equal(preview.zoom, 2);
});

test('changing print type resets choices while leaving all theme sizes visible', () => {
  const preview = new Preview();
  preview.product = { optionNames: ['Print Type', 'Sizes', 'Mounts and Frames'] };
  preview.values = ['Gloss', '10x20', 'Stand'];
  preview.config = { print_types: [
    { option: 'Gloss', sizes: [{ option: '10x20' }], mounts: [{ label: 'Stand' }] },
    { option: 'Satin', sizes: [{ option: '4x6' }, { option: '8x8' }], mounts: [{ label: 'Float Mount' }] },
  ] };
  const radio = (name, value, checked = false) => {
    const label = { toggleAttribute: () => {} };
    return { type: 'radio', name, value, checked, disabled: false, dataset: { optionName: name, currentChecked: String(checked) },
      label, closest: (selector) => selector === 'label' ? label : selector === 'fieldset' ? null : picker };
  };
  const inputs = [radio('Print Type', 'Satin', true), radio('Sizes', '10x20', true), radio('Sizes', '4x6'), radio('Sizes', '8x8'), radio('Mounts and Frames', 'Stand', true), radio('Mounts and Frames', 'Float Mount')];
  const picker = { querySelectorAll: () => inputs };
  preview.scope = picker;
  preview.resetPrintTypeOptions({ target: inputs[0] });
  assert.deepEqual(preview.values, ['Satin', '4x6', 'Float Mount']);
  assert.equal(inputs[1].checked, false);
  assert.equal(inputs[2].checked, true);
  assert.equal(inputs[5].checked, true);
  assert.equal(inputs[1].label.hidden, undefined);
  assert.equal(inputs[1].disabled, false);
  assert.equal(inputs[4].disabled, true);
  picker.querySelector = () => ({ value: '1' });
  picker.querySelectorAll = (selector) => selector.includes('data-selected-variant')
    ? [{ textContent: JSON.stringify({ id: 1, options: ['Gloss', '10x20', 'Stand'] }) }] : inputs;
  preview.readOptions();
  assert.deepEqual(preview.values, ['Satin', '4x6', 'Float Mount']);
  inputs[0].value = 'Gloss';
  preview.resetPrintTypeOptions({ target: inputs[0] });
  assert.deepEqual(preview.values, ['Gloss', '10x20', 'Stand']);
  assert.equal(inputs[1].label.hidden, undefined);
  assert.equal(inputs[4].disabled, false);
});

test('changing sizes does not reset the selected mount', () => {
  const preview = new Preview();
  preview.values = ['Metal', '8x8', 'Float Mount'];
  preview.resetPrintTypeOptions({ target: { dataset: { optionName: 'Sizes' } } });
  assert.deepEqual(preview.values, ['Metal', '8x8', 'Float Mount']);
});

test('size changes use the confirmed variant while old and new theme selectors coexist', () => {
  const preview = new Preview();
  preview.product = { optionNames: ['Print Type', 'Sizes', 'Mounts and Frames'] };
  preview.values = ['Metal - Gloss', '4x6', 'Float Mount'];
  const variants = [
    { id: 1, options: ['Metal - Gloss', '4x6', 'Float Mount'] },
    { id: 2, options: ['Metal - Gloss', '8x12', 'Float Mount'] },
  ];
  preview.scope = {
    querySelector: () => ({ value: '2' }),
    querySelectorAll: () => variants.map((variant) => ({ textContent: JSON.stringify(variant) })),
  };
  preview.readOptions();
  assert.deepEqual(preview.values, variants[1].options);
  const print = {
    option: 'Metal - Gloss',
    sizes: [{ option: '4x6', width: 4, height: 6 }, { option: '8x12', width: 8, height: 12 }],
    mounts: [{ label: 'Float Mount' }],
  };
  assert.equal(Preview.selection({ print_types: [print] }, preview.product.optionNames, preview.values).size.width, 8);
});

test('temporary gallery moves retain the existing renderer', async () => {
  const preview = new Preview();
  let disposals = 0;
  preview.dispose = () => { disposals++; };
  preview.isConnected = false;
  preview.disconnectedCallback();
  preview.isConnected = true;
  await Promise.resolve();
  assert.equal(disposals, 0);
  preview.isConnected = false;
  preview.disconnectedCallback();
  await Promise.resolve();
  assert.equal(disposals, 1);
});

test('a gallery already containing the preview can be found on reconnect', () => {
  const preview = new Preview();
  const gallery = {};
  preview.dataset = {};
  preview.contains = () => false;
  globalThis.document = { querySelector: () => ({ querySelector: () => gallery }) };
  assert.equal(preview.findGallery(), gallery);
});

test('Horizon mounts in the stable media column outside the replaced media-gallery', () => {
  const preview = new Preview();
  const column = {};
  const gallery = {};
  preview.dataset = {};
  preview.contains = () => false;
  globalThis.document = {
    querySelector: () => ({
      querySelector: (selector) => selector === '.product-information__media' ? column : selector === 'media-gallery' ? gallery : null,
    }),
  };
  assert.equal(preview.findGallery(), column);
});

test('Horizon initial variant JSON selects the configured surface, size and mount', () => {
  const preview = new Preview();
  const variant = { id: 46083452797063, options: ['HD Metal - White Satin', '4x6', '2.5" Acrylic Stand'] };
  preview.product = { optionNames: ['Print Type', 'Sizes', 'Mounts and Frames'] };
  preview.scope = {
    querySelector: () => ({ value: String(variant.id) }),
    querySelectorAll: (selector) => selector.includes('variant-picker') ? [{ textContent: JSON.stringify(variant) }] : [],
  };
  preview.readOptions();
  assert.deepEqual(preview.values, variant.options);
});

test('Horizon completed size changes update the renderer without resetting room view', async () => {
  const preview = new Preview();
  preview.product = { id: '8875409637511', optionNames: ['Print Type', 'Sizes', 'Mounts and Frames'] };
  preview.config = { print_types: [] };
  preview.scope = { contains: () => true };
  preview.isConnected = true;
  preview.mode = 'room';
  let painted;
  preview.render = () => { painted = preview.values; };
  await preview.onProductSelect({
    target: {},
    promise: Promise.resolve({ detail: { productId: '8875409637511', resource: { options: ['HD Metal - White Satin', '5x7', 'Float Mount'] } } }),
  });
  assert.deepEqual(painted, ['HD Metal - White Satin', '5x7', 'Float Mount']);
  assert.equal(preview.mode, 'room');
});

test('an older Horizon response cannot replace a newer selected size', async () => {
  const preview = new Preview();
  preview.product = { id: '8875409637511', optionNames: ['Print Type', 'Sizes', 'Mounts and Frames'] };
  preview.config = { print_types: [] };
  preview.scope = { contains: () => true };
  preview.isConnected = true;
  preview.render = () => {};
  let finishOld;
  const oldRequest = preview.onProductSelect({ target: {}, promise: new Promise((resolve) => { finishOld = resolve; }) });
  await preview.onProductSelect({ target: {}, promise: Promise.resolve({ detail: { productId: preview.product.id, resource: { options: ['Metal', '8x8', 'Float Mount'] } } }) });
  finishOld({ detail: { productId: preview.product.id, resource: { options: ['Metal', '5x7', 'Float Mount'] } } });
  await oldRequest;
  assert.equal(preview.values[1], '8x8');
});

test('a collapsed stage keeps the native image visible until a drawable layout is available', () => {
  const preview = new Preview();
  preview.isConnected = true;
  preview.product = { optionNames: ['Print Type', 'Sizes', 'Mounts and Frames'] };
  preview.values = ['Metal', '4x6', 'Float Mount'];
  preview.config = { print_types: [{
    option: 'Metal', surface: { id: 1, label: 'Metal', settings: { material_type: 'Metal' } },
    sizes: [{ option: '4x6', width: 4, height: 6 }],
    mounts: [{ label: 'Float Mount', settings: {} }],
  }] };
  const hiddenMedia = new Set();
  const nativeMedia = {
    setAttribute: (name) => hiddenMedia.add(name),
    removeAttribute: (name) => hiddenMedia.delete(name),
  };
  preview.gallery = {
    children: [preview, nativeMedia], setAttribute: () => {}, removeAttribute: () => {},
    querySelectorAll: () => [nativeMedia],
  };
  preview.stage = { getBoundingClientRect: () => ({ width: 0, height: 320 }) };
  preview.render();
  assert.equal(preview.hidden, true);
  assert.equal(hiddenMedia.size, 0);

  const painted = [];
  preview.dataset = {};
  preview.mode = 'spatial';
  preview.currentTilt = { x: 3, y: -9 };
  preview.description = {};
  preview.spatial = { update: (...args) => painted.push(args) };
  preview.stage = { dataset: {}, style: { setProperty: () => {} }, getBoundingClientRect: () => ({ width: 600, height: 600 }) };
  preview.render();
  assert.equal(preview.hidden, false);
  assert.equal(painted.length, 1);
  assert.ok(painted[0][4] > 0 && painted[0][5] > 0);
  assert.ok(hiddenMedia.has('data-frame-up-original-media'));
  preview.values[1] = '8x8';
  preview.render();
  assert.equal(preview.hidden, false);
  assert.equal(painted.at(-1)[0].size.option, '4x6');
  assert.equal(preview.values[1], '8x8', 'preview fallback must not alter the unavailable cart selection');
  assert.ok(hiddenMedia.has('data-frame-up-original-media'));
  assert.match(preview.description.textContent, /unavailable/);
  preview.paint = null;
  preview.render();
  assert.equal(preview.hidden, false, 'initial unavailable selections should also retain the spatial preview');
  preview.values[1] = '4x6';
  preview.render();
  assert.doesNotMatch(preview.description.textContent, /unavailable/);
  preview.interaction = new PreviewInteraction();
  preview.reducedMotion = { matches: false };
  preview.interaction.setZoom(3);
  preview.interaction.pointer(1, 1);
  preview.interaction.step(1, true);
  preview.config.print_types[0].sizes.push({ option: '8x8', width: 8, height: 8 });
  preview.values[1] = '8x8';
  preview.render();
  assert.equal(preview.interaction.state.zoom, 1);
  assert.equal(preview.interaction.state.panX, 0);
  assert.equal(painted.at(-1)[0].size.width, 8);
  assert.deepEqual(painted.at(-1)[8], { width: 600, height: 600 });
});
