export const number = (value, fallback) => Number.isFinite(Number(value)) && value !== null && value !== '' ? Number(value) : fallback;
export const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
export const color = (value, fallback) => typeof value === 'string' && /^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i.test(value) ? value : fallback;

export function selection(config, names, values) {
  const options = Object.fromEntries(names.map((name, index) => [name, values[index]]));
  const print = config.print_types.find((row) => row.option === options['Print Type']);
  const size = print?.sizes.find((row) => row.option === options.Sizes);
  const mount = print?.mounts.find((row) => row.label === options['Mounts and Frames']);
  if (!print || !size || !mount) return null;
  return { print, size, mount };
}

const materials = { metal: 'metal', acrylic: 'acrylic', paper: 'paper', wood: 'wood', 'wood print': 'wood', canvasewraptype: 'canvas', canvas: 'canvas', 'gallery wrap': 'canvas' };
const hangerTypes = new Set(['acrylic-stand', 'simple-box', 'mount-ezstick', 'floating-system', 'mount-posts', 'wood-mount', 'aluminum-mountModel', 'aluminum-mountModel-edited', 'black-mountModel-edited', 'flush-black', 'flush-silver']);
const inches = (value, fallback = 0) => number(String(value ?? '').match(/([\d.]+)(?:-inch|\s*["″])/i)?.[1], fallback);

export function appearance(selected) {
  const surface = selected.print.surface.settings;
  const mount = selected.mount.settings;
  const kind = materials[String(surface.material_type ?? '').trim().toLowerCase()];
  if (!kind) return null;
  const included = selected.print.included_addons ?? [];
  const wrap = included.find((addon) => addon.group === 'Wrap thickness');
  const style = included.find((addon) => addon.group === 'Wrap style');
  const matting = included.find((addon) => addon.group === 'Matting');
  const woodBorder = included.find((addon) => addon.group === 'Wood border');
  const frame = mount.selectMount === 'frames';
  const mountType = frame ? 'frame' : hangerTypes.has(mount.selectHangerType) ? mount.selectHangerType : null;
  const frameType = frame ? (mount.frame_type || 'box') : null;
  const rim = frame ? { flat: 1.125, box: .75, float: .625 }[frameType] ?? .75 : 0;
  const frameColor = color(mount.FrameColor, color(mount.frame_color, '#8B4513'));
  const removeBorder = included.some((addon) => addon.category === 'basic' && /remove\s*1\s*(?:["'])?\s*(?:inch)?\s*border/i.test(addon.label));
  const borderLabel = matting?.settings.exclusive_option || matting?.label;
  const woodLabel = woodBorder?.label;
  const mat = kind === 'paper' ? removeBorder ? 0 : frame ? inches(borderLabel, inches(matting?.label)) / 2 : .5
    : kind === 'wood' ? inches(woodLabel, number(woodBorder?.settings.border_size, number(surface.border_size, 0))) / 2 : 0;
  const labelColor = /white/i.test(matting?.label ?? '') ? '#ffffff' : /black/i.test(matting?.label ?? '') ? '#000000' : '#ffffff';
  const wrapCode = String(wrap?.settings.exclusive_option ?? '');
  const wrapDepth = number(wrapCode.match(/exc-op-([\d.]+)-gallery-wrap/)?.[1], inches(wrap?.label, .75));
  const unstretched = /not.stretched|canvas.only/i.test(wrap?.label ?? '') || /canvas-only/i.test(wrapCode);
  const thickness = { metal: number(surface.metal_thickness, .05), acrylic: number(surface.glass_depth, .125) + .125,
    canvas: unstretched ? .02 : wrapDepth, paper: .02, wood: .5 }[kind];
  const sideColor = kind === 'canvas' ? color(style?.settings.depth_side_color, color(style?.settings.border_color, '#ffffff'))
    : kind === 'metal' ? color(surface.metal_color, '#d9d5cf') : kind === 'wood' ? color(surface.frame_color, '#d8b47d') : color(surface.back_color, '#ffffff');
  return {
    kind, mountType, frameType, finish: frame && mount.frame_texture ? 'wood' : kind,
    frameColor, floating: frameType === 'float', rim, mat, matColor: color(matting?.settings.border_color, labelColor),
    depth: clamp(Math.max(thickness, frame ? frameType === 'float' ? 2 : frameType === 'box' ? 1.125 : .75 : thickness), .02, 4),
    printDepth: clamp(thickness, .02, 4), sideColor, wrapStyle: style?.settings.exclusive_option === 'exc-op-mirror-effect' ? 'mirror' : 'color',
    glass: kind === 'acrylic' ? .12 : 0,
    roughness: clamp(number(surface.surface_roughness, number(surface.metal_roughness, .35)), .02, 1),
    reflection: clamp(number(surface.reflection_intensity, .45), 0, 3),
    metalness: clamp(number(surface.metalness, 0), 0, 1),
    texture: surface.surface_texture || 'none', textureStrength: clamp(number(surface.texture_strength, 0), 0, 3),
    woodFinish: surface.wood_finish || 'natural',
    frameTexture: mount.frame_texture || null,
    mountSettings: mount, surfaceSettings: surface,
  };
}

export function surfaceStyles(view, imageUrl) {
  const reflective = view.kind === 'metal' || view.kind === 'acrylic';
  const polish = Math.pow(1 - view.roughness, 4);
  const reflectivity = reflective ? clamp(view.reflection / 1.5, 0, 1) : 0;
  return {
    '--surface-reflection': String(reflectivity * polish),
    '--shine-width': `${8 + view.roughness * 100}%`,
    '--shine-blur': `${view.roughness * 12}px`,
    '--surface-grain': String(view.kind === 'canvas' ? .18 : view.kind === 'wood' ? view.woodFinish === 'white' ? .045 : .16 : view.texture === 'none' ? 0 : clamp(view.textureStrength * .12, 0, .3)),
    '--surface-tint': view.kind === 'wood' ? view.sideColor : color(view.surfaceSettings.substrate_color, '#d9d5cf'),
    '--surface-tint-opacity': String(view.kind === 'wood' && view.woodFinish !== 'white' ? .1 : view.kind === 'metal' ? view.metalness * .12 : 0),
    '--edge-art': view.kind === 'canvas' && view.wrapStyle === 'mirror' ? `url(${JSON.stringify(imageUrl)})` : 'none',
  };
}
