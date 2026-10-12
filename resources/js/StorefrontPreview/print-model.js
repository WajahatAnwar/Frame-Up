import * as THREE from 'three';
import { printMaterialSettings } from './lighting.js';

export function buildPrint(width, height, view, artwork, surfaceTexture) {
  const group = new THREE.Group();
  const depth = view.printDepth;
  const side = new THREE.MeshStandardMaterial({ color: view.sideColor, roughness: view.kind === 'metal' ? .36 : .6, metalness: view.kind === 'metal' ? .3 : 0 });
  const back = new THREE.MeshStandardMaterial({ color: view.surfaceSettings.back_metal_color || view.sideColor, roughness: .36, metalness: view.kind === 'metal' ? .3 : 0 });
  const blank = new THREE.MeshStandardMaterial({ color: view.matColor, roughness: .9 });
  const panel = new THREE.Mesh(new THREE.BoxGeometry(width, height, depth), [side, side, side, side, blank, back]);
  panel.castShadow = true;
  panel.receiveShadow = true;
  group.add(panel);
  const texture = artwork.clone();
  const imageAspect = artwork.image.width / artwork.image.height;
  const aspect = width / height;
  texture.repeat.set(imageAspect > aspect ? aspect / imageAspect : 1, imageAspect < aspect ? imageAspect / aspect : 1);
  texture.offset.set((1 - texture.repeat.x) / 2, (1 - texture.repeat.y) / 2);
  texture.needsUpdate = true;
  // Bias the artwork forward in the depth buffer without changing print thickness.
  const material = new THREE.MeshStandardMaterial({
    ...printMaterialSettings(view), map: texture,
    polygonOffset: true, polygonOffsetFactor: -1, polygonOffsetUnits: -1,
  });
  if (material.emissiveIntensity > 0) material.emissiveMap = texture;
  if (surfaceTexture && view.textureStrength > 0) {
    material.bumpMap = surfaceTexture;
    material.bumpScale = view.textureStrength;
  }
  const mat = Math.min(view.mat, Math.min(width, height) / 3);
  const image = new THREE.Mesh(new THREE.PlaneGeometry(width - mat * 2, height - mat * 2), material);
  image.position.z = depth / 2 + .001;
  image.userData.role = 'artwork';
  group.add(image);
  if (view.kind === 'acrylic') {
    const glass = new THREE.Mesh(new THREE.BoxGeometry(width, height, Math.max(.01, depth - .125)), new THREE.MeshPhysicalMaterial({ color: '#ffffff', roughness: .04, transmission: .95, thickness: depth, ior: 1.49, transparent: true, opacity: .18, envMapIntensity: view.reflection }));
    glass.position.z = depth / 2 + .01;
    group.add(glass);
  }
  if (view.kind === 'canvas' && view.wrapStyle === 'mirror') {
    for (const [index, horizontal] of [[0, true], [1, true], [2, false], [3, false]]) {
      const edge = artwork.clone();
      edge.repeat.set(horizontal ? -Math.min(depth / width, 1) : 1, horizontal ? 1 : -Math.min(depth / height, 1));
      edge.offset.set(index === 1 ? 1 : 0, index === 2 ? 1 : 0);
      edge.needsUpdate = true;
      const edgeMaterial = side.clone();
      edgeMaterial.color.set('#ffffff');
      edgeMaterial.map = edge;
      panel.material[index] = edgeMaterial;
    }
    side.dispose();
  }
  const addBox = (w, h, d, x, y, z, color = view.frameColor) => {
    const mesh = new THREE.Mesh(new THREE.BoxGeometry(w, h, d), new THREE.MeshStandardMaterial({ color, roughness: .4, metalness: .1 }));
    mesh.position.set(x, y, z);
    mesh.castShadow = true;
    group.add(mesh);
    return mesh;
  };
  if (view.mountType === 'frame') {
    const rim = view.rim;
    const gap = view.floating ? .125 : 0;
    for (const sign of [-1, 1]) {
      addBox(rim, height + (rim + gap) * 2, view.depth, sign * (width / 2 + gap + rim / 2), 0, 0);
      addBox(width + gap * 2, rim, view.depth, 0, sign * (height / 2 + gap + rim / 2), 0);
    }
  } else if (view.mountType === 'acrylic-stand') {
    const stand = addBox(Math.min(width * .6, 3), .16, 2.5, 0, -height / 2, -1, '#eeeeee');
    stand.material.transparent = true;
    stand.material.opacity = .55;
  } else if (view.mountType === 'floating-system' || view.mountType === 'wood-mount') {
    const inset = Math.min(width, height) * .2;
    const mountDepth = Math.max(.05, Number(view.mountSettings.FloatDepth) || .3);
    for (const sign of [-1, 1]) {
      addBox(.5, height - inset * 2, mountDepth, sign * (width / 2 - inset), 0, -depth / 2 - mountDepth / 2);
      addBox(width - inset * 2, .5, mountDepth, 0, sign * (height / 2 - inset), -depth / 2 - mountDepth / 2);
    }
  } else if (view.mountType === 'mount-posts') {
    for (const x of [-1, 1]) for (const y of [-1, 1]) {
      const post = new THREE.Mesh(new THREE.CylinderGeometry(.15, .15, .5, 20), new THREE.MeshStandardMaterial({ color: '#d9d5cf', metalness: 1, roughness: .15 }));
      post.rotation.x = Math.PI / 2;
      post.position.set(x * (width / 2 - .5), y * (height / 2 - .5), 0);
      group.add(post);
    }
  }
  return group;
}

export function disposePrint(group, sharedTextures = []) {
  const materials = new Set();
  const textures = new Set();
  group.traverse((mesh) => {
    mesh.geometry?.dispose();
    for (const material of [mesh.material].flat().filter(Boolean)) materials.add(material);
  });
  for (const material of materials) {
    for (const key of ['map', 'emissiveMap', 'bumpMap']) if (material[key] && !sharedTextures.includes(material[key])) textures.add(material[key]);
    material.dispose();
  }
  textures.forEach((texture) => texture.dispose());
}
