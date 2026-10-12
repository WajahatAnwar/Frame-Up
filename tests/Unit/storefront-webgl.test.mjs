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
        const blend = zoom === 1 ? 1 : zoom === 3 ? 0 : .5;
        preview.update(selection, view, false, { x: 0, y: 0 }, 300, 450, zoom, { x: 0, y: 0 }, { width: 600, height: 600 });
        assert.equal(preview.scene.environment, blend > 0 ? preview.environment.texture : null);
        assert.ok(Math.abs(image.material.envMapIntensity - reflection * blend) < 1e-8);
        assert.equal(preview.sourceArtwork.visible, blend < 1);
        assert.ok(Math.abs(preview.sourceArtwork.material.opacity - (1 - blend)) < 1e-8);
        assert.equal(preview.sourceArtwork.material.map, map, 'unlit artwork must preserve the same image and crop');
        assert.equal(preview.sourceArtwork.material.toneMapped, false);
        assert.equal(preview.sourceArtwork.material.isMeshBasicMaterial, true);
        assert.equal(preview.sourceArtwork.material.color.getHexString(), 'ffffff', 'source artwork must have no tint or brightness multiplier');
        assert.equal(image.material.map, map);
        assert.ok(image.material.color.equals(imageColor));
      }
      preview.environment.texture.dispose();
      for (const [tilt, zoom] of [[{ x: 0, y: 0 }, 1], [{ x: 6, y: -12 }, 1], [{ x: 0, y: 0 }, 3], [{ x: 0, y: 0 }, 1]]) {
        preview.update(selection, view, false, tilt, 300, 450, zoom, { x: 0, y: 0 }, { width: 600, height: 600 });
        assert.equal(image.material.envMapIntensity, zoom > 1 ? 0 : reflection, 'magnification disables reflective hover effects');
        assert.equal(preview.scene.environment, zoom > 1 ? null : preview.environment.texture);
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
