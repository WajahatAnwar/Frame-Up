import * as THREE from 'three';

// Ported from EditableFrame.jsx, SceneLights.jsx and RoomSceneLights.jsx.
export const environmentSettings = Object.freeze({ file: 'lebombo_1k.hdr', intensity: 1, rotation: [0, 0, 0], exposure: 1 });

export function sceneLights(width, height, room = false) {
  const lights = new THREE.Group();
  const maximum = Math.max(width, height);
  if (!room) lights.add(new THREE.AmbientLight(0xffffff, .1));
  const positions = room ? [[0, height * 2, maximum]] : [[width / 2, height, -maximum / 3], [-width / 2, height, -maximum / 3]];
  for (const position of positions) {
    const light = new THREE.SpotLight(0xffffff, .25, room ? 85 : maximum * 2.5, Math.PI / 4, room ? .85 : 1, room ? .3 : 0);
    light.position.set(...position);
    light.castShadow = true;
    light.shadow.mapSize.set(room ? 2048 : 512, room ? 2048 : 512);
    lights.add(light, light.target);
  }
  return lights;
}

export function printMaterialSettings(view) {
  const metal = view.kind === 'metal';
  const color = metal ? view.surfaceSettings.substrate_color || view.surfaceSettings.metal_color || '#d9d5cf' : '#ffffff';
  const bump = view.textureStrength;
  // Same image-preserving emission calculation as MetalPrintModel.jsx.
  const baseEmission = THREE.MathUtils.lerp(.55, .18, view.metalness);
  return {
    color, emissive: color,
    emissiveIntensity: metal ? THREE.MathUtils.lerp(baseEmission, .38, THREE.MathUtils.clamp(bump / 2, 0, 1)) : 0,
    metalness: metal ? view.metalness : 0,
    roughness: view.roughness,
    envMapIntensity: view.reflection,
  };
}
