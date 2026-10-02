/* Боковые грани и проекция тени. Никаких изменений модели. */
import { state } from 'goblin/core/state.js';
import { ISO_B, thickness, elevation, liftOf } from 'goblin/canvas/projection.js';

const SVG = 'http://www.w3.org/2000/svg';
function outline(element, w, h, radius) {
  if (element.type === 'decision') {
    const cut = Math.min(26, w / 3);
    return [[cut, 0], [w-cut, 0], [w,h/2], [w-cut,h], [cut,h], [0,h/2]];
  }
  if (element.type === 'gateway') {
    return Array.from({ length: 48 }, (_, i) => {
      const a = i * Math.PI / 24;return [w/2 + w/2*Math.cos(a), h/2 + h/2*Math.sin(a)];
    });
  }
  const r = Math.min(radius, w/2, h/2), pts = [];
  for (const [cx, cy, start] of [[w-r,r,-90],[w-r,h-r,0],[r,h-r,90],[r,r,180]]) {
    for (let i=0;i<=5;i++) { const a=(start+i*18)*Math.PI/180;pts.push([cx+r*Math.cos(a),cy+r*Math.sin(a)]); }
  }
  return pts;
}
export function renderVolume(node, element, b) {
  node.classList.toggle('below-ground',state.iso && elevation(element)<0);
  if (!state.iso) { node.style.transform='';node.querySelector('.node-solid')?.remove();return; }
  const lift=liftOf(element), shift=lift/(2*ISO_B);
  node.style.transform=`translate(${-shift}px,${-shift}px)`;
  let solid=node.querySelector('.node-solid');
  if (!solid) { solid=document.createElementNS(SVG,'svg');solid.setAttribute('class','node-solid');solid.setAttribute('aria-hidden','true');node.prepend(solid); }
  const radius=parseFloat(getComputedStyle(node.querySelector('.node-card')).borderRadius)||10;
  node.style.setProperty('--iso-shadow-blur',Math.min(14,1+Math.max(0,elevation(element))*.045)+'px');
  node.style.setProperty('--iso-shadow-alpha',String(Math.max(.07,.24-Math.max(0,elevation(element))*.0006)));
  const key=JSON.stringify([b.w,b.h,thickness(element),elevation(element),element.type,radius,state.selection.has(element.id)]);
  if (solid.dataset.shape===key) return;
  solid.dataset.shape=key;solid.setAttribute('width',b.w);solid.setAttribute('height',b.h);
  const points=outline(element,b.w,b.h,radius), down=thickness(element)/(2*ISO_B);
  let html='';
  if (lift>0 && !['group','area'].includes(element.type)) {
    const spread=1+Math.min(.45,Math.max(0,elevation(element))*.0012);
    html+=`<polygon class="iso-ground" points="${points.map(([x,y])=>`${b.w/2+(x-b.w/2)*spread+shift},${b.h/2+(y-b.h/2)*spread+shift}`).join(' ')}"/>`;
    if (elevation(element)>8 && state.selection.has(element.id)) html+=`<path class="iso-pillar" d="M ${b.w} ${b.h} L ${b.w+shift} ${b.h+shift}"/>`;
  }
  const visible=[];
  if (down>0) for (let i=0;i<points.length;i++) {
    const a=points[i],c=points[(i+1)%points.length],nx=c[1]-a[1],ny=a[0]-c[0];
    if (nx+ny<=0) continue;
    visible.push([a,c]);
    html+=`<polygon class="iso-face ${nx>ny?'iso-face-right':'iso-face-front'}" points="${a} ${c} ${c[0]+down},${c[1]+down} ${a[0]+down},${a[1]+down}"/>`;
  }
  if(visible.length){
    const a=visible[0][0],c=visible[visible.length-1][1];
    html+=`<path class="iso-solid-outline" d="M ${a} L ${a[0]+down},${a[1]+down} ${visible.map(([,p])=>`L ${p[0]+down},${p[1]+down}`).join(' ')} L ${c}"/>`;
  }
  solid.innerHTML=html;
}
