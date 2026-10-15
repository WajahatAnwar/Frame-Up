import * as THREE from 'three';
import { RGBELoader } from 'three/addons/loaders/RGBELoader.js';
import { environmentSettings, sceneLights } from './lighting.js';
import { buildPrint, disposePrint } from './print-model.js';
import { buildRoom, disposeRoom, buildRoomContactShadow } from './room-scene.js';

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
    const textureLoader = new THREE.TextureLoader();
    const loadTexture = async (url, key) => {
      const texture = await textureLoader.loadAsync(url);
      if (signal.aborted || this.disposed) { texture.dispose(); return; }
      this[key] = texture;
    };
    let hdrBuffer;
    try {
      [hdrBuffer] = await Promise.all([
        fetch(hdrUrl, { signal }).then((response) => {
          if (!response.ok) throw new Error('The preview lighting environment could not be loaded.');
          return response.arrayBuffer();
        }),
        loadTexture(imageUrl, 'artwork'),
        textureUrl ? loadTexture(textureUrl, 'surfaceTexture') : Promise.resolve(),
      ]);
    } catch (error) {
      this.dispose();
      throw error;
    }
    if (signal.aborted || this.disposed) { this.dispose(); return false; }
    const hdr = new RGBELoader().parse(hdrBuffer);
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
    this.artwork.colorSpace = THREE.SRGBColorSpace;
    this.artwork.wrapS = this.artwork.wrapT = THREE.ClampToEdgeWrapping;
    this.artwork.anisotropy = this.renderer.capabilities.getMaxAnisotropy();
    if (textureUrl) {
      this.surfaceTexture.wrapS = this.surfaceTexture.wrapT = THREE.RepeatWrapping;
    }
    if (signal.aborted || this.disposed) { this.dispose(); return false; }
    this.container.replaceChildren(this.renderer.domElement);
    return true;
  }

  async loadRoom(imageUrl) {
    const texture = await new THREE.TextureLoader().loadAsync(imageUrl);
    texture.colorSpace = THREE.SRGBColorSpace;
    if (this.disposed) { texture.dispose(); return; }
    this.roomTexture?.dispose();
    this.roomTexture = texture;
    this.roomBackground = new THREE.Scene();
    this.roomBackgroundCamera = new THREE.OrthographicCamera(-1, 1, 1, -1, 0, 2);
    this.roomBackgroundCamera.position.z = 1;
    this.roomBackgroundMesh = new THREE.Mesh(new THREE.PlaneGeometry(2, 2),
      new THREE.MeshBasicMaterial({ map: texture, transparent: true, depthWrite: false, toneMapped: false }));
    this.roomBackground.add(this.roomBackgroundMesh);
  }

  async upgradeArtwork(imageUrl) {
    const texture = await new THREE.TextureLoader().loadAsync(imageUrl);
    if (this.disposed) { texture.dispose(); return; }
    texture.colorSpace = THREE.SRGBColorSpace;
    texture.wrapS = texture.wrapT = THREE.ClampToEdgeWrapping;
    texture.anisotropy = this.renderer.capabilities.getMaxAnisotropy();
    const previous = this.artwork;
    this.artwork = texture;
    this.key = null;
    previous?.dispose();
  }

  captureViewState() {
    return {
      position: this.camera.position.clone(), zoom: this.camera.zoom,
      near: this.camera.near, far: this.camera.far, rotation: this.group?.rotation.clone(),
      view: this.camera.view?.enabled ? { ...this.camera.view } : null,
      roomMix: this.roomMix ?? 0,
    };
  }

  update(selection, view, room, tilt, pixelWidth, pixelHeight, zoom = 1, focus = { x: 0, y: 0 }, viewport) {
    if (this.disposed || !this.artwork || pixelWidth <= 0 || pixelHeight <= 0) return;
    const key = JSON.stringify([selection.print.surface.id, selection.size.option, selection.mount.id, view, room]);
    const width = Number(selection.size.width), height = Number(selection.size.height);
    if (key !== this.key) {
      this.clearModel();
      this.group = buildPrint(width, height, view, this.artwork, this.surfaceTexture);
      this.finishProfiles = new Map();
      this.group.traverse((mesh) => {
        for (const material of mesh.material ? (Array.isArray(mesh.material) ? mesh.material : [mesh.material]) : []) {
          if (!this.finishProfiles.has(material)) this.finishProfiles.set(material, {
            roughness: material.roughness, metalness: material.metalness,
            bumpScale: material.bumpScale,
          });
        }
        if (mesh.userData.role === 'glass') this.glassMesh = mesh;
      });
      this.lights = sceneLights(width, height, room);
      this.scene.add(this.group, this.lights);
      const bounds = new THREE.Box3().setFromObject(this.group);
      this.modelBounds = bounds.clone();
      this.modelCenter = bounds.getCenter(new THREE.Vector3());
      this.modelSize = bounds.getSize(new THREE.Vector3());
      const sphere = bounds.getBoundingSphere(new THREE.Sphere());
      this.modelRadius = sphere.radius + sphere.center.length();
      if (room) {
        this.roomContactShadow = buildRoomContactShadow(bounds);
        this.scene.add(this.roomContactShadow);
        this.wallShadow = new THREE.Mesh(
          new THREE.PlaneGeometry(this.modelSize.x * 8, this.modelSize.y * 8),
          new THREE.ShadowMaterial({ opacity: .22, depthWrite: false }),
        );
        this.wallShadow.position.set(this.modelCenter.x, this.modelCenter.y, bounds.min.z - .05);
        this.wallShadow.receiveShadow = true;
        this.scene.add(this.wallShadow);
      }
      this.key = key;
    }
    this.scene.environment = this.environment?.texture ?? null;
    this.setFlatFinish(viewport?.flatFinish === true);
    const renderWidth = viewport?.width ?? pixelWidth;
    const renderHeight = viewport?.height ?? pixelHeight;
    if (renderWidth <= 0 || renderHeight <= 0) return;
    if (renderWidth !== this.pixelWidth || renderHeight !== this.pixelHeight) {
      this.renderer.setSize(renderWidth, renderHeight, false);
      this.pixelWidth = renderWidth;
      this.pixelHeight = renderHeight;
    }
    this.camera.aspect = renderWidth / renderHeight;
    this.scene.background = this.roomBackgroundMesh ? null : room ? this.roomTexture ?? null : null;
    if (this.wallShadow) this.wallShadow.visible = !this.roomTexture;
    if (this.roomTexture) {
      const imageAspect = this.roomTexture.image.width / this.roomTexture.image.height;
      const aspect = renderWidth / renderHeight;
      this.roomTexture.repeat.set(Math.min(1, aspect / imageAspect), Math.min(1, imageAspect / aspect));
      this.roomTexture.offset.set((1 - this.roomTexture.repeat.x) / 2, (1 - this.roomTexture.repeat.y) / 2);
    }
    this.camera.zoom = room && viewport ? 1 : viewport ? Math.max(.1, zoom * pixelHeight / renderHeight) : THREE.MathUtils.clamp(zoom, 1, 3);
    if (!room) this.camera.zoom *= 1 + .08 * THREE.MathUtils.clamp(viewport?.hoverLift ?? 0, 0, 1);
    const size = this.modelSize;
    const fitHeight = Math.max(size.y, size.x / (pixelWidth / pixelHeight));
    const visibleHeight = room && viewport ? fitHeight * renderHeight / pixelHeight : fitHeight;
    const distance = visibleHeight / (2 * Math.tan(THREE.MathUtils.degToRad(this.camera.fov / 2))) * (room ? 1 : 1.08) + size.z;
    const halfHeight = distance * Math.tan(THREE.MathUtils.degToRad(this.camera.fov / 2));
    const pan = room ? 0 : 1 - 1 / this.camera.zoom;
    const panX = viewport ? Math.max(0, size.x / 2 - halfHeight * this.camera.aspect / this.camera.zoom) : halfHeight * this.camera.aspect * pan;
    const panY = viewport ? Math.max(0, size.y / 2 - halfHeight / this.camera.zoom) : halfHeight * pan;
    this.camera.position.set(
      viewport ? this.modelCenter.x + (room ? (1 - 2 * (viewport.centerX ?? .47)) * halfHeight * this.camera.aspect / this.camera.zoom : 0) : THREE.MathUtils.clamp(focus.x, -1, 1) * panX,
      viewport ? this.modelCenter.y + (room ? (2 * (viewport.centerY ?? .38) - 1) * halfHeight / this.camera.zoom : 0) : THREE.MathUtils.clamp(focus.y, -1, 1) * panY,
      viewport ? distance + this.modelCenter.z : distance,
    );
    // Fit clipping planes to the entire model at any hover angle. A tiny near
    // plane and distant far plane lose precision on thin print surfaces.
    this.camera.near = Math.max(.01, distance - this.modelRadius * 1.25);
    this.camera.far = distance + this.modelRadius * 1.25;
    this.camera.clearViewOffset();
    this.camera.updateProjectionMatrix();
    this.group.rotation.set(room ? 0 : THREE.MathUtils.degToRad(tilt.x), room ? 0 : THREE.MathUtils.degToRad(tilt.y), 0);
    if (room && !this.roomTexture) {
      if (!this.roomScene || this.roomAspect !== this.camera.aspect) {
        if (this.roomScene) { this.scene.remove(this.roomScene); disposeRoom(this.roomScene); }
        this.roomScene = buildRoom(this.camera.aspect, this.surfaceTexture);
        this.roomAspect = this.camera.aspect;
        this.scene.add(this.roomScene);
      }
      const roomHeight = 2 * halfHeight / this.camera.zoom;
      this.roomScene.scale.setScalar(roomHeight);
      this.roomScene.position.set(this.camera.position.x, this.camera.position.y, this.modelBounds.min.z - .06);
      this.wallShadow.visible = false;
      this.roomScene.updateMatrixWorld(true);
      const roomBounds = new THREE.Box3().setFromObject(this.roomScene);
      this.camera.near = Math.max(.01, Math.min(this.camera.near, this.camera.position.z - roomBounds.max.z - .1));
      this.camera.far = Math.max(this.camera.far, this.camera.position.z - roomBounds.min.z + .1);
      this.camera.updateProjectionMatrix();
    }
    if (viewport && !room) this.inspectProjectedProduct(focus, renderWidth, renderHeight, zoom);
    const transition = viewport?.transition;
    this.roomMix = room ? 1 : 0;
    if (transition) {
      const { from, progress } = transition;
      this.camera.position.lerpVectors(from.position, this.camera.position.clone(), progress);
      this.camera.zoom = THREE.MathUtils.lerp(from.zoom, this.camera.zoom, progress);
      this.camera.near = THREE.MathUtils.lerp(from.near, this.camera.near, progress);
      this.camera.far = THREE.MathUtils.lerp(from.far, this.camera.far, progress);
      if (from.rotation) {
        this.group.rotation.x = THREE.MathUtils.lerp(from.rotation.x, this.group.rotation.x, progress);
        this.group.rotation.y = THREE.MathUtils.lerp(from.rotation.y, this.group.rotation.y, progress);
      }
      this.roomMix = THREE.MathUtils.lerp(from.roomMix, this.roomMix, progress);
      const targetView = this.camera.view?.enabled ? this.camera.view : null;
      if (from.view || targetView) {
        this.camera.setViewOffset(renderWidth, renderHeight,
          THREE.MathUtils.lerp(from.view?.offsetX ?? 0, targetView?.offsetX ?? 0, progress),
          THREE.MathUtils.lerp(from.view?.offsetY ?? 0, targetView?.offsetY ?? 0, progress), renderWidth, renderHeight);
      }
      this.camera.updateProjectionMatrix();
    }
    if (this.roomContactShadow) this.roomContactShadow.material.opacity = this.roomMix;
    if (this.roomBackgroundMesh) {
      const autoClear = this.renderer.autoClear;
      this.renderer.autoClear = false;
      this.renderer.clear();
      this.roomBackgroundMesh.material.opacity = this.roomMix;
      if (this.roomMix > 0) this.renderer.render(this.roomBackground, this.roomBackgroundCamera);
      this.renderer.clearDepth();
      this.renderer.render(this.scene, this.camera);
      this.renderer.autoClear = autoClear;
    } else this.renderer.render(this.scene, this.camera);
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

  setFlatFinish(flat) {
    if (!this.finishProfiles) return;
    for (const [material, profile] of this.finishProfiles) {
      if (profile.roughness !== undefined) material.roughness = flat ? 1 : profile.roughness;
      if (profile.metalness !== undefined) material.metalness = flat ? 0 : profile.metalness;
      if (profile.bumpScale !== undefined) material.bumpScale = flat ? 0 : profile.bumpScale;
    }
    if (this.glassMesh) this.glassMesh.visible = !flat;
  }

  clearModel() {
    if (this.roomContactShadow) {
      this.scene.remove(this.roomContactShadow);
      this.roomContactShadow.geometry.dispose();
      this.roomContactShadow.material.map.dispose();
      this.roomContactShadow.material.dispose();
      this.roomContactShadow = null;
    }
    if (this.roomScene) {
      this.scene.remove(this.roomScene);
      disposeRoom(this.roomScene);
      this.roomScene = null;
    }
    if (this.wallShadow) {
      this.scene.remove(this.wallShadow);
      this.wallShadow.geometry.dispose();
      this.wallShadow.material.dispose();
      this.wallShadow = null;
    }
    if (this.group) {
      this.scene.remove(this.group);
      disposePrint(this.group, [this.artwork, this.surfaceTexture]);
    }
    this.finishProfiles = null;
    this.glassMesh = null;
    if (this.lights) {
      this.scene.remove(this.lights);
      this.lights.traverse((light) => light.shadow?.map?.dispose());
    }
  }

  dispose() {
    if (this.disposed) return;
    this.disposed = true;
    this.clearModel();
    this.artwork?.dispose();
    this.surfaceTexture?.dispose();
    this.hdr?.dispose();
    this.roomTexture?.dispose();
    this.roomBackgroundMesh?.geometry.dispose();
    this.roomBackgroundMesh?.material.dispose();
    this.environment?.dispose();
    this.renderer.dispose();
    this.renderer.forceContextLoss();
    this.renderer.domElement.remove();
  }
}
