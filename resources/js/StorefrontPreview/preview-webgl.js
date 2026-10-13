import * as THREE from 'three';
import { RGBELoader } from 'three/addons/loaders/RGBELoader.js';
import { environmentSettings, sceneLights } from './lighting.js';
import { buildPrint, disposePrint } from './print-model.js';

export default class SpatialPreview {
  constructor(container) {
    this.container = container;
    this.renderer = new THREE.WebGLRenderer({ alpha: true, antialias: true });
    this.renderer.setPixelRatio(Math.min(devicePixelRatio || 1, 2));
    this.renderer.outputColorSpace = THREE.SRGBColorSpace;
    this.renderer.toneMapping = THREE.ACESFilmicToneMapping;
    this.renderer.toneMappingExposure = environmentSettings.exposure;
    this.renderer.setClearColor(0xffffff, 0);
    this.renderer.shadowMap.enabled = true;
    this.renderer.shadowMap.type = THREE.PCFSoftShadowMap;
    this.scene = new THREE.Scene();
    this.camera = new THREE.PerspectiveCamera(50, 1, .01, 1000);
    this.renderer.domElement.setAttribute('aria-hidden', 'true');
  }

  async load(imageUrl, hdrUrl, textureUrl, signal) {
    const response = await fetch(hdrUrl, { signal });
    if (!response.ok) throw new Error('The preview lighting environment could not be loaded.');
    const hdr = new RGBELoader().parse(await response.arrayBuffer());
    this.hdr = new THREE.DataTexture(hdr.data, hdr.width, hdr.height, THREE.RGBAFormat, hdr.type);
    this.hdr.mapping = THREE.EquirectangularReflectionMapping;
    this.hdr.colorSpace = THREE.LinearSRGBColorSpace;
    this.hdr.flipY = true;
    this.hdr.needsUpdate = true;
    const pmrem = new THREE.PMREMGenerator(this.renderer);
    this.environment = pmrem.fromEquirectangular(this.hdr);
    pmrem.dispose();
    this.scene.environment = this.environment.texture;
    // The HDR illuminates the print, but is not the visible background.
    const textureLoader = new THREE.TextureLoader();
    this.artwork = await textureLoader.loadAsync(imageUrl);
    this.artwork.colorSpace = THREE.SRGBColorSpace;
    this.artwork.wrapS = this.artwork.wrapT = THREE.ClampToEdgeWrapping;
    this.artwork.anisotropy = this.renderer.capabilities.getMaxAnisotropy();
    if (textureUrl) {
      this.surfaceTexture = await textureLoader.loadAsync(textureUrl);
      this.surfaceTexture.wrapS = this.surfaceTexture.wrapT = THREE.RepeatWrapping;
    }
    if (signal.aborted || this.disposed) { this.dispose(); return false; }
    this.container.replaceChildren(this.renderer.domElement);
    return true;
  }

  update(selection, view, room, tilt, pixelWidth, pixelHeight, zoom = 1, focus = { x: 0, y: 0 }, viewport) {
    if (this.disposed || !this.artwork || pixelWidth <= 0 || pixelHeight <= 0) return;
    const key = JSON.stringify([selection.print.surface.id, selection.size.option, selection.mount.id, view, room]);
    const width = Number(selection.size.width), height = Number(selection.size.height);
    if (key !== this.key) {
      this.clearModel();
      this.group = buildPrint(width, height, view, this.artwork, this.surfaceTexture);
      this.lights = sceneLights(width, height, room);
      this.scene.add(this.group, this.lights);
      const bounds = new THREE.Box3().setFromObject(this.group);
      this.modelBounds = bounds.clone();
      this.modelCenter = bounds.getCenter(new THREE.Vector3());
      this.modelSize = bounds.getSize(new THREE.Vector3());
      const sphere = bounds.getBoundingSphere(new THREE.Sphere());
      this.modelRadius = sphere.radius + sphere.center.length();
      this.key = key;
    }
    this.scene.environment = this.environment?.texture ?? null;
    const renderWidth = viewport?.width ?? pixelWidth;
    const renderHeight = viewport?.height ?? pixelHeight;
    if (renderWidth <= 0 || renderHeight <= 0) return;
    if (renderWidth !== this.pixelWidth || renderHeight !== this.pixelHeight) {
      this.renderer.setSize(renderWidth, renderHeight, false);
      this.pixelWidth = renderWidth;
      this.pixelHeight = renderHeight;
    }
    this.camera.aspect = renderWidth / renderHeight;
    this.camera.zoom = viewport ? Math.max(.1, zoom * pixelHeight / renderHeight) : THREE.MathUtils.clamp(zoom, 1, 3);
    const size = this.modelSize;
    const fitHeight = Math.max(size.y, size.x / (pixelWidth / pixelHeight));
    const distance = fitHeight / (2 * Math.tan(THREE.MathUtils.degToRad(this.camera.fov / 2))) * (room ? 1.2 : 1.08) + size.z;
    const halfHeight = distance * Math.tan(THREE.MathUtils.degToRad(this.camera.fov / 2));
    const pan = room ? 0 : 1 - 1 / this.camera.zoom;
    const panX = viewport ? Math.max(0, size.x / 2 - halfHeight * this.camera.aspect / this.camera.zoom) : halfHeight * this.camera.aspect * pan;
    const panY = viewport ? Math.max(0, size.y / 2 - halfHeight / this.camera.zoom) : halfHeight * pan;
    this.camera.position.set(
      viewport ? this.modelCenter.x : THREE.MathUtils.clamp(focus.x, -1, 1) * panX,
      viewport ? this.modelCenter.y + (room ? -halfHeight / this.camera.zoom * .4 : 0) : THREE.MathUtils.clamp(focus.y, -1, 1) * panY,
      viewport ? distance + this.modelCenter.z : distance,
    );
    // Fit clipping planes to the entire model at any hover angle. A tiny near
    // plane and distant far plane lose precision on thin print surfaces.
    this.camera.near = Math.max(.01, distance - this.modelRadius * 1.25);
    this.camera.far = distance + this.modelRadius * 1.25;
    this.camera.clearViewOffset();
    this.camera.updateProjectionMatrix();
    this.group.rotation.set(room ? 0 : THREE.MathUtils.degToRad(tilt.x), room ? 0 : THREE.MathUtils.degToRad(tilt.y), 0);
    if (viewport && !room) this.inspectProjectedProduct(focus, renderWidth, renderHeight, zoom);
    this.renderer.render(this.scene, this.camera);
  }

  inspectProjectedProduct(focus, width, height, zoom) {
    this.group.updateMatrixWorld(true);
    this.camera.updateMatrixWorld(true);
    const projected = new THREE.Box2();
    const bounds = this.modelBounds;
    for (const x of [bounds.min.x, bounds.max.x]) {
      for (const y of [bounds.min.y, bounds.max.y]) {
        for (const z of [bounds.min.z, bounds.max.z]) {
          const point = new THREE.Vector3(x, y, z).applyMatrix4(this.group.matrixWorld).project(this.camera);
          projected.expandByPoint(new THREE.Vector2((point.x + 1) * width / 2, (1 - point.y) * height / 2));
        }
      }
    }
    const size = projected.getSize(new THREE.Vector2());
    const center = projected.getCenter(new THREE.Vector2());
    // The reference allows white space around the inspected frame rather
    // than locking its edges against the viewport. Keep at least 35% of
    // each axis visible and ease the inspection range in as zoom increases.
    const inspection = THREE.MathUtils.clamp((zoom - 1) / .5, 0, 1);
    const rangeX = Math.max(0, (size.x - Math.min(size.x / zoom, width * .35)) / 2) * inspection;
    const rangeY = Math.max(0, (size.y - Math.min(size.y / zoom, height * .35)) / 2) * inspection;
    const x = (center.x - width / 2) * inspection + THREE.MathUtils.clamp(focus.x, -1, 1) * rangeX;
    const y = (center.y - height / 2) * inspection - THREE.MathUtils.clamp(focus.y, -1, 1) * rangeY;
    this.camera.setViewOffset(width, height, x, y, width, height);
  }

  clearModel() {
    if (this.group) {
      this.scene.remove(this.group);
      disposePrint(this.group, [this.artwork, this.surfaceTexture]);
    }
    if (this.lights) {
      this.scene.remove(this.lights);
      this.lights.traverse((light) => light.shadow?.map?.dispose());
    }
  }

  dispose() {
    this.disposed = true;
    this.clearModel();
    this.artwork?.dispose();
    this.surfaceTexture?.dispose();
    this.hdr?.dispose();
    this.environment?.dispose();
    this.renderer.dispose();
    this.renderer.forceContextLoss();
    this.renderer.domElement.remove();
  }
}
