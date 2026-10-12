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
    this.artwork.anisotropy = this.renderer.capabilities.getMaxAnisotropy();
    if (textureUrl) {
      this.surfaceTexture = await textureLoader.loadAsync(textureUrl);
      this.surfaceTexture.wrapS = this.surfaceTexture.wrapT = THREE.RepeatWrapping;
    }
    if (signal.aborted || this.disposed) { this.dispose(); return false; }
    this.container.replaceChildren(this.renderer.domElement);
    return true;
  }

  update(selection, view, room, tilt, pixelWidth, pixelHeight, zoom = 1) {
    if (this.disposed || !this.artwork || pixelWidth <= 0 || pixelHeight <= 0) return;
    const key = JSON.stringify([selection.print.surface.id, selection.size.option, selection.mount.id, view, room]);
    const width = Number(selection.size.width), height = Number(selection.size.height);
    if (key !== this.key) {
      this.clearModel();
      this.group = buildPrint(width, height, view, this.artwork, this.surfaceTexture);
      this.lights = sceneLights(width, height, room);
      this.scene.add(this.group, this.lights);
      const bounds = new THREE.Box3().setFromObject(this.group);
      this.modelSize = bounds.getSize(new THREE.Vector3());
      const sphere = bounds.getBoundingSphere(new THREE.Sphere());
      this.modelRadius = sphere.radius + sphere.center.length();
      this.key = key;
    }
    if (pixelWidth !== this.pixelWidth || pixelHeight !== this.pixelHeight) {
      this.renderer.setSize(pixelWidth, pixelHeight, false);
      this.pixelWidth = pixelWidth;
      this.pixelHeight = pixelHeight;
    }
    this.camera.aspect = pixelWidth / pixelHeight;
    this.camera.zoom = THREE.MathUtils.clamp(zoom, 1, 3);
    const size = this.modelSize;
    const fitHeight = Math.max(size.y, size.x / this.camera.aspect);
    const distance = fitHeight / (2 * Math.tan(THREE.MathUtils.degToRad(this.camera.fov / 2))) * 1.2 + size.z;
    this.camera.position.set(0, 0, distance);
    // Fit clipping planes to the entire model at any hover angle. A tiny near
    // plane and distant far plane lose precision on thin print surfaces.
    this.camera.near = Math.max(.01, distance - this.modelRadius * 1.25);
    this.camera.far = distance + this.modelRadius * 1.25;
    this.camera.updateProjectionMatrix();
    this.group.rotation.set(room ? 0 : THREE.MathUtils.degToRad(tilt.x), room ? 0 : THREE.MathUtils.degToRad(tilt.y), 0);
    this.renderer.render(this.scene, this.camera);
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
