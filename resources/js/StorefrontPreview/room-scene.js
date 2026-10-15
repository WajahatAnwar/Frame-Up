import * as THREE from 'three';

// Coordinates are fractions of the visible wall height. The room stays fixed
// in the viewport while product dimensions determine the artwork scale.
export function buildRoom(aspect, wallTexture) {
  const group = new THREE.Group();
  const wall = new THREE.MeshStandardMaterial({ color: '#f4f1e9', roughness: 1, emissive: '#e5e3dd', emissiveIntensity: .15 });
  if (wallTexture) {
    wall.bumpMap = wallTexture.clone();
    wall.bumpMap.wrapS = wall.bumpMap.wrapT = THREE.RepeatWrapping;
    wall.bumpMap.repeat.set(aspect * 3, 3);
    wall.bumpMap.needsUpdate = true;
    wall.map = wall.bumpMap;
    wall.map.colorSpace = THREE.SRGBColorSpace;
    wall.bumpScale = .001;
  }
  const backdrop = new THREE.Mesh(new THREE.PlaneGeometry(aspect * 1.1, 1.1), wall);
  backdrop.receiveShadow = true;
  group.add(backdrop);
  const returnWall = new THREE.Mesh(new THREE.PlaneGeometry(aspect * .05, 1.1), new THREE.MeshStandardMaterial({ color: '#deded9', roughness: 1 }));
  returnWall.position.set(-aspect * .475, 0, .001);
  group.add(returnWall);
  const box = (width, height, depth, x, y, z, color, roughness = .6) => {
    const mesh = new THREE.Mesh(new THREE.BoxGeometry(width, height, depth), new THREE.MeshStandardMaterial({ color, roughness }));
    mesh.position.set(x, y, z);
    mesh.castShadow = mesh.receiveShadow = true;
    group.add(mesh);
    return mesh;
  };
  const consoleY = -.395;
  box(aspect * .79, .012, .12, -aspect * .045, consoleY, .061, '#bf9569');
  box(aspect * .79, .14, .115, -aspect * .045, consoleY - .076, .061, '#ecece7');
  box(aspect * .16, .019, .07, -aspect * .12, consoleY + .015, .065, '#242c2e');
  box(aspect * .16, .012, .07, -aspect * .12, consoleY + .031, .065, '#111a20');
  const points = [[.018, 0], [.027, .008], [.029, .035], [.016, .075], [.012, .088]]
    .map(([x, y]) => new THREE.Vector2(x, y));
  const vase = new THREE.Mesh(new THREE.LatheGeometry(points, 32), new THREE.MeshStandardMaterial({ color: '#eeeadc', roughness: .7 }));
  vase.position.set(aspect * .075, consoleY + .006, .07);
  vase.castShadow = true;
  group.add(vase);
  const stemMaterial = new THREE.MeshStandardMaterial({ color: '#6b604d', roughness: 1 });
  for (let index = 0; index < 5; index++) {
    const curve = new THREE.CatmullRomCurve3([
      new THREE.Vector3(aspect * .075, consoleY + .08, .07),
      new THREE.Vector3(aspect * .06 - index * .011, consoleY + .115 + index * .004, .075),
      new THREE.Vector3(aspect * .025 - index * .017, consoleY + .135 + index * .006, .08),
    ]);
    const stem = new THREE.Mesh(new THREE.TubeGeometry(curve, 12, .00065, 4, false), stemMaterial);
    group.add(stem);
    for (let leaf = 1; leaf < 4; leaf++) {
      const bud = new THREE.Mesh(new THREE.SphereGeometry(.0018, 6, 4), stemMaterial);
      bud.position.copy(curve.getPoint(leaf / 4));
      group.add(bud);
    }
  }
  return group;
}

export function disposeRoom(group) {
  const materials = new Set();
  group.traverse((mesh) => {
    mesh.geometry?.dispose();
    if (mesh.material) materials.add(mesh.material);
  });
  for (const material of materials) {
    material.bumpMap?.dispose();
    material.dispose();
  }
}

export function buildRoomContactShadow(bounds) {
  const resolution = 128;
  const data = new Uint8Array(resolution * resolution * 4);
  for (let y = 0; y < resolution; y++) {
    for (let x = 0; x < resolution; x++) {
      const dx = Math.max(0, Math.abs((x + .5) / resolution - .5) - .36);
      const dy = Math.max(0, Math.abs((y + .5) / resolution - .5) - .36);
      const alpha = Math.exp(-dx * dx / (.055 * .055 * 2) - dy * dy / (.045 * .045 * 2));
      data[(y * resolution + x) * 4 + 3] = Math.round(alpha * 60);
    }
  }
  const texture = new THREE.DataTexture(data, resolution, resolution);
  texture.magFilter = texture.minFilter = THREE.LinearFilter;
  texture.needsUpdate = true;
  const size = bounds.getSize(new THREE.Vector3());
  const center = bounds.getCenter(new THREE.Vector3());
  const shadow = new THREE.Mesh(new THREE.PlaneGeometry(size.x * 1.26, size.y * 1.2),
    new THREE.MeshBasicMaterial({ map: texture, transparent: true, depthWrite: false, toneMapped: false }));
  shadow.position.set(center.x + size.x * .015, center.y - size.y * .02, bounds.min.z - .025);
  return shadow;
}
