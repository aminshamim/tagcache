// Deterministic pastel-ish color from tag text
export function hashTagToColor(tag: string, alpha=1){
  let h=0;
  for(let i=0;i<tag.length;i++) h = Math.imul(31,h) + tag.charCodeAt(i) | 0;
  const hue = Math.abs(h)%360;
  const sat = 55 + (Math.abs(h)>>3)%20; // 55-74
  const light = 60 + (Math.abs(h)>>5)%15; // 60-74
  return `hsl(${hue}deg ${sat}% ${light}% / ${alpha})`;
}
