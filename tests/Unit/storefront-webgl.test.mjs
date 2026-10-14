import assert from 'node:assert/strict';
import { test } from 'node:test';
import * as THREE from 'three';
import SpatialPreview from '../../resources/js/StorefrontPreview/preview-webgl.js';
import { disposePrint } from '../../resources/js/StorefrontPreview/print-model.js';
import { appearance } from '../../public/frame-up-preview/preview-model.js';
import { buildRoomContactShadow } from '../../resources/js/StorefrontPreview/room-scene.js';

test('room transition interpolates camera placement and background without drawing a duplicate product', () => {
  const preview = Object.create(SpatialPreview.prototype);
  preview.artwork = new THREE.Texture({ width: 1600, height: 2400 });
  preview.roomTexture = new THREE.Texture({ width: 1254, height: 1254 });
  preview.roomBackground = new THREE.Scene();
  preview.roomBackgroundCamera = new THREE.OrthographicCamera();
  preview.roomBackgroundMesh = { material: { opacity: 0 } };
  preview.scene = new THREE.Scene();
  preview.camera = new THREE.PerspectiveCamera(50, 1, .01, 1000);
  const draws = [];
  preview.renderer = { autoClear: true, setSize: () => {}, clear: () => {}, clearDepth: () => {}, render: (scene) => draws.push(scene) };
  const selection = {
    print: { surface: { id: 1, settings: { material_type: 'Metal', metal_thickness: .02 } } },
    size: { option: '24x36', width: 24, height: 36 }, mount: { id: 2, settings: {} },
  };
  const view = appearance(selection);
  const viewport = { width: 600, height: 600 };
  try {
    preview.update(selection, view, false, { x: 0, y: 12 }, 300, 450, 1, { x: 0, y: 0 }, viewport);
    const from = preview.captureViewState();
    preview.update(selection, view, true, { x: 0, y: 0 }, 240, 360, 1, { x: 0, y: 0 }, viewport);
    const target = preview.captureViewState();
    const group = preview.group;
    for (const progress of [0, .5, 1]) {
      draws.length = 0;
      preview.update(selection, view, true, { x: 0, y: 0 }, 240, 360, 1, { x: 0, y: 0 },
        { ...viewport, transition: { from, progress } });
      assert.ok(preview.camera.position.distanceTo(from.position.clone().lerp(target.position, progress)) < 1e-8);
      assert.ok(Math.abs(preview.camera.zoom - (.75 + .25 * progress)) < 1e-8);
      assert.ok(Math.abs(preview.group.rotation.y - THREE.MathUtils.degToRad(12) * (1 - progress)) < 1e-8);
      assert.equal(preview.roomBackgroundMesh.material.opacity, progress);
      assert.equal(preview.roomContactShadow.material.opacity, progress);
      assert.equal(preview.group, group);
      assert.equal(draws.filter((scene) => scene === preview.scene).length, 1);
      assert.equal(preview.renderer.autoClear, true);
    }
  } finally {
    preview.clearModel();
    preview.artwork.dispose();
    preview.roomTexture.dispose();
  }
});

test('room contact shadow follows the complete product bounds with transparent soft edges', () => {
  const bounds = new THREE.Box3(new THREE.Vector3(-12, -18, -.5), new THREE.Vector3(12, 18, .5));
  const shadow = buildRoomContactShadow(bounds);
  try {
    assert.ok(Math.abs(shadow.geometry.parameters.width - 28.8) < 1e-8);
    assert.ok(shadow.position.x > 0 && shadow.position.y < 0, 'shadow falls slightly right and below the product');
    assert.ok(shadow.position.z < bounds.min.z && shadow.position.z > bounds.min.z - .06, 'shadow sits between the product and wall');
    const { data, width } = shadow.material.map.image;
    assert.equal(data[(64 * width + 64) * 4 + 3], 54);
    assert.equal(data[3], 0);
    assert.equal(shadow.material.depthWrite, false);
    assert.equal(shadow.material.toneMapped, false);
  } finally {
    shadow.geometry.dispose();
    shadow.material.map.dispose();
    shadow.material.dispose();
  }
});

test('procedural room keeps the complete product separate from fixed wall, console and vase geometry', () => {
  const preview = Object.create(SpatialPreview.prototype);
  preview.artwork = new THREE.Texture({ width: 1600, height: 2400 });
  preview.scene = new THREE.Scene();
  preview.camera = new THREE.PerspectiveCamera(50, 1, .01, 1000);
  preview.renderer = { setSize: () => {}, render: () => {} };
  const selection = {
    print: { surface: { id: 1, settings: { material_type: 'Metal', metal_thickness: .02 } } },
    size: { option: '4x6', width: 4, height: 6 },
    mount: { id: 2, settings: { selectHangerType: 'floating-system' } },
  };
  const view = appearance(selection);
  try {
    preview.update(selection, view, true, { x: 6, y: 12 }, 40, 60, 1, { x: 1, y: 1 }, { width: 600, height: 600 });
    const room = preview.roomScene;
    const contactShadow = preview.roomContactShadow;
    assert.equal(contactShadow.parent, preview.scene);
    assert.ok(room.children.some((mesh) => mesh.geometry.type === 'LatheGeometry'), 'room includes the vase');
    assert.ok(room.children.some((mesh) => mesh.geometry.type === 'TubeGeometry'), 'room includes branches');
    assert.equal(preview.group.parent, preview.scene, 'product must remain independent of room furniture');
    assert.equal(preview.wallShadow.visible, false, 'the physical wall receives shadows without a second shadow layer');
    const bounds = new THREE.Box3().setFromObject(room);
    assert.equal(preview.camera.zoom, 1, 'room camera must not use the clamped product magnifier zoom');
    assert.equal(room.scale.x, room.scale.z, 'room furniture must not be flattened to compensate for camera distance');
    assert.ok(preview.camera.position.z - bounds.max.z > preview.camera.near, 'furniture must stay in front of the near plane even for small prints');
    assert.ok(preview.camera.position.z - bounds.min.z < preview.camera.far, 'room wall must remain inside the far plane');
    const position = room.position.clone();
    preview.update(selection, view, true, { x: -6, y: -12 }, 40, 60, 1, { x: -1, y: -1 }, { width: 600, height: 600 });
    assert.equal(preview.roomScene, room);
    assert.ok(room.position.equals(position), 'pointer movement must not move room furniture');
    preview.update(selection, view, false, { x: 0, y: 0 }, 300, 450, 3, { x: 1, y: -1 }, { width: 600, height: 600 });
    assert.equal(preview.roomScene, null);
    assert.equal(room.parent, null, 'magnifier contains only the product');
    assert.equal(contactShadow.parent, null, 'magnifier must remove the room contact shadow');
  } finally {
    preview.clearModel();
    preview.artwork.dispose();
  }
});

test('room artwork fits the requested size and stays anchored on the wall without hover or zoom', () => {
  const selection = {
    print: { surface: { id: 1, settings: { material_type: 'Metal', metal_thickness: .02 } } },
    size: { option: '24x36', width: 24, height: 36 },
    mount: { id: 2, settings: { selectHangerType: 'floating-system' } },
  };
  const preview = Object.create(SpatialPreview.prototype);
  preview.artwork = new THREE.Texture({ width: 1600, height: 2400 });
  preview.scene = new THREE.Scene();
  preview.camera = new THREE.PerspectiveCamera(50, 1, .01, 1000);
  preview.renderer = { setSize: () => {}, render: () => {} };
  const view = appearance(selection);
  preview.roomTexture = new THREE.Texture({ width: 1200, height: 800 });

  try {
    preview.update(selection, view, true, { x: 6, y: 12 }, 240, 360, 1, { x: 1, y: -1 }, { width: 600, height: 600, centerX: .47, centerY: .38 });
    preview.camera.updateMatrixWorld();
    const center = preview.modelCenter.clone().project(preview.camera);
    assert.ok(Math.abs(center.x - (-.06)) < 1e-8);
    assert.ok(Math.abs(center.y - .24) < 1e-8);
    assert.equal(preview.group.rotation.x, 0);
    assert.equal(preview.group.rotation.y, 0);
    assert.equal(preview.scene.background, preview.roomTexture);
    assert.ok(Math.abs(preview.roomTexture.repeat.x - 2 / 3) < 1e-8);
    assert.equal(preview.roomTexture.repeat.y, 1);
    assert.equal(preview.wallShadow.receiveShadow, true);
    assert.equal(preview.wallShadow.visible, false, 'photo room uses only the soft contact shadow, without the hard accessory silhouette');
    const top = new THREE.Vector3(0, 18, .01).project(preview.camera);
    const bottom = new THREE.Vector3(0, -18, .01).project(preview.camera);
    assert.ok(Math.abs((top.y - bottom.y) * 300 - 360) < 18, 'room fitting must retain the requested artwork size within depth padding');

    preview.update(selection, view, true, { x: -6, y: -12 }, 240, 360, 1, { x: -1, y: 1 }, { width: 600, height: 600, centerX: .6, centerY: .3 });
    preview.camera.updateMatrixWorld();
    const moved = preview.modelCenter.clone().project(preview.camera);
    assert.ok(Math.abs(moved.x - .2) < 1e-8);
    assert.ok(Math.abs(moved.y - .4) < 1e-8);
    assert.equal(preview.camera.view?.enabled ?? false, false, 'room view must not retain magnifier inspection offsets');

    const wall = preview.wallShadow;
    preview.update(selection, view, false, { x: 6, y: 12 }, 240, 360, 1, { x: 0, y: 0 }, { width: 600, height: 600 });
    assert.equal(preview.scene.background, null, 'close-up must remove the room background');
    assert.equal(preview.wallShadow, null);
    assert.equal(wall.parent, null);
    assert.ok(preview.group.rotation.y > 0, 'returning to close-up must restore 3D hover');
  } finally {
    preview.clearModel();
    preview.roomTexture.dispose();
    preview.artwork.dispose();
  }
});

for (const [width, height] of [[4, 6], [20, 30], [48, 96]]) {
  test(`thin ${width}x${height} print keeps distinct surface depth and remains inside clipping planes during hover`, () => {
    const selection = {
      print: { surface: { id: 1, settings: { material_type: 'Metal', metal_thickness: .02 } } },
      size: { option: `${width}x${height}`, width, height },
      mount: { id: 2, settings: { selectHangerType: 'floating-system' } },
    };
    const preview = Object.create(SpatialPreview.prototype);
    preview.artwork = new THREE.Texture({ width: 1600, height: 1263 });
    preview.scene = new THREE.Scene();
    preview.camera = new THREE.PerspectiveCamera(50, 1, .01, 1000);
    let resizes = 0;
    preview.renderer = { setSize: () => { resizes++; }, render: () => {} };
    const view = appearance(selection);
    try {
      preview.update(selection, view, false, { x: 0, y: 0 }, 300, 450);
      preview.camera.updateMatrixWorld();
      const panelDepth = new THREE.Vector3(0, 0, view.printDepth / 2).project(preview.camera).z;
      const image = preview.group.children.find((mesh) => mesh.userData.role === 'artwork');
      const artworkDepth = image.position.clone().project(preview.camera).z;
      assert.ok(panelDepth - artworkDepth > 1e-7, 'artwork must remain distinguishable from the panel in the depth buffer');
      assert.equal(image.material.depthTest, true);
      assert.equal(image.material.polygonOffset, true);
      assert.ok(image.material.polygonOffsetUnits < 0);

      for (const tilt of [{ x: 6, y: 15 }, { x: -6, y: -15 }]) {
        preview.update(selection, view, false, tilt, 300, 450);
        const bounds = new THREE.Box3().setFromObject(preview.group);
        const cameraDistance = preview.camera.position.z;
        assert.ok(cameraDistance - bounds.max.z > preview.camera.near);
        assert.ok(cameraDistance - bounds.min.z < preview.camera.far);
      }
      assert.equal(resizes, 1, 'hover should draw without resizing the canvas');
      const group = preview.group;
      const initialX = new THREE.Vector3(1, 0, 0).project(preview.camera).x;
      preview.update(selection, view, false, { x: 0, y: 0 }, 300, 450, 2);
      assert.equal(preview.camera.zoom, 2);
      assert.ok(Math.abs(new THREE.Vector3(1, 0, 0).project(preview.camera).x - initialX * 2) < 1e-8);
      assert.equal(preview.group, group, 'zoom must retain the existing print geometry');
      assert.equal(resizes, 1);
      preview.update(selection, view, false, { x: 0, y: 0 }, 300, 450, 2, { x: initialX, y: 0 });
      preview.camera.updateMatrixWorld();
      assert.ok(Math.abs(new THREE.Vector3(1, 0, 0).project(preview.camera).x - initialX) < 1e-8, 'magnification must keep the point under the cursor anchored');
      preview.update(selection, view, false, { x: 0, y: 0 }, 300, 450, 1, { x: 1, y: 1 });
      assert.equal(preview.camera.position.x, 0);
      assert.equal(preview.camera.position.y, 0);
      preview.update(selection, view, false, { x: 0, y: 0 }, 300, 450, 20);
      assert.equal(preview.camera.zoom, 3);
      const originalGroup = preview.group;
      preview.update(selection, view, false, { x: 0, y: 0 }, 300, 450, 1, { x: 0, y: 0 }, { width: 600, height: 600 });
      const viewportResizes = resizes;
      preview.camera.updateMatrixWorld();
      const bounds = new THREE.Box3().setFromObject(preview.group);
      for (const x of [bounds.min.x, bounds.max.x]) for (const y of [bounds.min.y, bounds.max.y]) {
        const point = new THREE.Vector3(x, y, bounds.max.z).project(preview.camera);
        assert.ok(Math.abs(point.x) < 1 && Math.abs(point.y) < 1, 'the complete model must fit at default zoom');
      }
      for (const zoom of [1.2, 2, 3, 2, 1]) {
        preview.update(selection, view, false, { x: 0, y: 0 }, 300, 450, zoom, { x: 1, y: -1 }, { width: 600, height: 600 });
        assert.equal(preview.group, originalGroup);
        assert.equal(resizes, viewportResizes, 'damped zoom must retain a fixed drawing buffer');
        assert.ok(Number.isFinite(preview.camera.position.x));
      }
      const projectedEdges = () => {
        preview.camera.updateMatrixWorld();
        const points = [];
        for (const x of [bounds.min.x, bounds.max.x]) for (const y of [bounds.min.y, bounds.max.y]) for (const z of [bounds.min.z, bounds.max.z]) {
          points.push(new THREE.Vector3(x, y, z).project(preview.camera));
        }
        return { left: Math.min(...points.map((point) => point.x)), right: Math.max(...points.map((point) => point.x)), bottom: Math.min(...points.map((point) => point.y)), top: Math.max(...points.map((point) => point.y)) };
      };
      for (const focus of [{ x: -1, y: 1 }, { x: 1, y: -1 }]) {
        preview.update(selection, view, false, { x: 0, y: 0 }, 300, 450, 6, focus, { width: 600, height: 600 });
        const edges = projectedEdges();
        assert.ok(focus.x < 0 ? edges.left > -1 : edges.right < 1, 'inspection must expose the horizontal frame edge with surrounding white space');
        assert.ok(focus.y > 0 ? edges.top < 1 : edges.bottom > -1, 'inspection must expose the vertical frame edge with surrounding white space');
        assert.ok(edges.right > -1 && edges.left < 1 && edges.top > -1 && edges.bottom < 1, 'inspection must keep part of the complete product visible');
        assert.equal(preview.camera.position.x, preview.modelCenter.x, 'inspection must not orbit or translate the camera sideways');
        assert.equal(preview.group, originalGroup);
      }
      const closeOffset = Math.abs(preview.camera.view.offsetY);
      preview.update(selection, view, false, { x: 0, y: 0 }, 300, 450, 3, { x: 1, y: -1 }, { width: 600, height: 600 });
      assert.ok(Math.abs(preview.camera.view.offsetY) < closeOffset, 'inspection range must increase with zoom');
      preview.update(selection, view, false, { x: 0, y: 0 }, 300, 450, 1, { x: 1, y: -1 }, { width: 600, height: 600 });
      const restored = projectedEdges();
      assert.ok(restored.left > -1 && restored.right < 1 && restored.bottom > -1 && restored.top < 1, 'zooming out must restore the complete product');
      assert.equal(preview.camera.view.offsetX, 0);
      assert.equal(preview.camera.view.offsetY, 0);
      assert.equal(resizes, viewportResizes);
      const imageColor = image.material.color.clone();
      const map = image.material.map;
      const reflection = image.material.envMapIntensity;
      const lights = preview.lights.children.filter((light) => light.isLight);
      const intensities = lights.map((light) => light.intensity);
      preview.environment = { texture: new THREE.Texture() };
      for (const zoom of [1, 1.075, 3, 1]) {
        preview.update(selection, view, false, { x: 0, y: 0 }, 300, 450, zoom, { x: 0, y: 0 }, { width: 600, height: 600 });
        assert.equal(preview.scene.environment, preview.environment.texture);
        assert.equal(image.material.envMapIntensity, reflection);
        assert.equal(image.material.map, map);
        assert.ok(image.material.color.equals(imageColor));
      }
      preview.environment.texture.dispose();
      for (const [tilt, zoom] of [[{ x: 0, y: 0 }, 1], [{ x: 6, y: -12 }, 1], [{ x: 0, y: 0 }, 3], [{ x: 0, y: 0 }, 1]]) {
        preview.update(selection, view, false, tilt, 300, 450, zoom, { x: 0, y: 0 }, { width: 600, height: 600 });
        assert.equal(image.material.envMapIntensity, reflection, 'magnification must retain configured HDR reflections');
        assert.equal(preview.scene.environment, preview.environment.texture);
        assert.deepEqual(lights.map((light) => light.intensity), intensities);
        assert.ok(image.material.color.equals(imageColor));
        assert.equal(image.material.map, map);
        assert.equal(preview.group, originalGroup);
      }
    } finally {
      disposePrint(preview.group, [preview.artwork]);
      preview.artwork.dispose();
    }
  });
}
