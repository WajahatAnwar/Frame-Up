import assert from 'node:assert/strict';
import { test } from 'node:test';
import { PreviewInteraction } from '../../public/frame-up-preview/preview-model.js';

const settle = (interaction) => {
  for (let i = 0; i < 300 && interaction.step(1 / 60); i++);
};

test('clicked magnifier zooms out on exit, resumes on entry, and second click restores 3D hover', () => {
  const interaction = new PreviewInteraction();
  interaction.pointer(.5, .5);
  interaction.toggleMagnifier();
  settle(interaction);
  assert.equal(interaction.state.zoom, 3);
  assert.equal(interaction.state.tiltX, 0);
  for (let i = 0; i < 3; i++) {
    interaction.leave();
    assert.equal(interaction.target.zoom, 1);
    assert.equal(interaction.magnifierEnabled, true);
    assert.equal(interaction.state.zoom, 3, 'exit must zoom out smoothly');
    settle(interaction);
    assert.equal(interaction.state.zoom, 1);
    interaction.pointer(-.5, .5);
    assert.equal(interaction.target.zoom, 3);
    settle(interaction);
    assert.equal(interaction.state.zoom, 3);
  }
  interaction.toggleMagnifier();
  settle(interaction);
  assert.equal(interaction.magnifierEnabled, false);
  assert.equal(interaction.state.zoom, 1);
  assert.equal(interaction.state.tiltY, -6);
  interaction.leave();
  settle(interaction);
  interaction.pointer(.5, -.5);
  settle(interaction);
  assert.equal(interaction.state.zoom, 1);
  assert.equal(interaction.state.tiltX, -3);
  interaction.reset();
  assert.equal(interaction.magnifierEnabled, false);
});

test('HDR lighting fades in on centered hover and fades out completely on exit', () => {
  const interaction = new PreviewInteraction();
  assert.equal(interaction.environmentBlend, 0);
  interaction.pointer(0, 0);
  assert.equal(interaction.step(1 / 60), true);
  assert.ok(interaction.environmentBlend > 0 && interaction.environmentBlend < 1);
  settle(interaction);
  assert.equal(interaction.environmentBlend, 1);
  assert.equal(interaction.state.tiltY, 0);
  interaction.setZoom(3);
  assert.equal(interaction.step(1 / 60), true);
  settle(interaction);
  interaction.pointer(0, 0);
  assert.equal(interaction.step(1 / 60), false);
  interaction.leave();
  assert.equal(interaction.step(1 / 60), true);
  settle(interaction);
  assert.equal(interaction.environmentBlend, 0);
  assert.equal(interaction.step(1 / 60), false);
});

test('center is straight, edge tilt is bounded, and leaving settles at zero', () => {
  const interaction = new PreviewInteraction();
  interaction.pointer(0, 0);
  settle(interaction);
  assert.equal(interaction.state.tiltX, 0);
  assert.equal(interaction.state.tiltY, 0);
  interaction.pointer(8, -8);
  interaction.step(1 / 60);
  assert.ok(interaction.state.tiltX > -6 && interaction.state.tiltX < 0);
  settle(interaction);
  assert.equal(interaction.state.tiltX, -6);
  assert.equal(interaction.state.tiltY, 12);
  interaction.leave();
  settle(interaction);
  assert.equal(interaction.state.tiltX, 0);
  assert.equal(interaction.state.tiltY, 0);
});

test('repeated wheel input respects zoom limits and zoomed panning is damped and bounded', () => {
  const interaction = new PreviewInteraction();
  interaction.wheel(-100);
  assert.ok(interaction.target.zoom > 1);
  interaction.step(1 / 60);
  assert.ok(interaction.state.zoom > 1 && interaction.state.zoom < interaction.target.zoom);
  for (let i = 0; i < 100; i++) interaction.wheel(-100);
  assert.equal(interaction.target.zoom, 3);
  interaction.pointer(10, -10);
  interaction.step(1 / 60);
  assert.ok(interaction.state.panX > 0 && interaction.state.panX < 1);
  settle(interaction);
  assert.equal(interaction.state.panX, 1);
  assert.equal(interaction.state.panY, -1);
  assert.equal(interaction.state.tiltX, 0);
  interaction.leave();
  settle(interaction);
  assert.equal(interaction.state.panX, 0);
  for (let i = 0; i < 100; i++) interaction.wheel(100);
  settle(interaction);
  assert.equal(interaction.state.zoom, 1);
});

test('damping is consistent across different frame rates', () => {
  const fast = new PreviewInteraction(), slow = new PreviewInteraction();
  for (const interaction of [fast, slow]) { interaction.pointer(1, 1); interaction.setZoom(3); }
  for (let i = 0; i < 60; i++) fast.step(1 / 60);
  for (let i = 0; i < 30; i++) slow.step(1 / 30);
  assert.ok(Math.abs(fast.state.zoom - slow.state.zoom) < .00001);
  assert.ok(Math.abs(fast.state.panX - slow.state.panX) < .00001);
});

test('settings control tilt, movement, zoom and responsiveness; reset clears variant interaction', () => {
  const interaction = new PreviewInteraction({ tiltX: '4', tiltY: '8', minZoom: '.75', maxZoom: '4', movement: '.5', damping: '15', zoomSpeed: '.004' });
  interaction.pointer(1, 1);
  settle(interaction);
  assert.equal(interaction.state.tiltX, 4);
  assert.equal(interaction.state.tiltY, 8);
  interaction.setZoom(20);
  interaction.step(1 / 60, true);
  assert.equal(interaction.state.zoom, 4);
  assert.equal(interaction.state.panX, .5);
  interaction.setZoom(0);
  settle(interaction);
  assert.equal(interaction.state.zoom, .75);
  interaction.reset();
  assert.deepEqual(interaction.state, { tiltX: 0, tiltY: 0, zoom: 1, panX: 0, panY: 0 });
});
