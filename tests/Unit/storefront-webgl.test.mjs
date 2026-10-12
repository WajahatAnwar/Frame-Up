import assert from 'node:assert/strict';
import { test } from 'node:test';
import * as THREE from 'three';
import SpatialPreview from '../../resources/js/StorefrontPreview/preview-webgl.js';
import { disposePrint } from '../../resources/js/StorefrontPreview/print-model.js';
import { appearance } from '../../public/frame-up-preview/preview-model.js';

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
      preview.update(selection, view, false, { x: 0, y: 0 }, 300, 450, 20);
      assert.equal(preview.camera.zoom, 3);
    } finally {
      disposePrint(preview.group, [preview.artwork]);
      preview.artwork.dispose();
    }
  });
}
