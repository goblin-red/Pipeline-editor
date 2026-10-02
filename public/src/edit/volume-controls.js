/* Экранные ручки X/Y/Z: положение и размеры, один жест — одна отмена. */
import { state, on, emit } from 'goblin/core/state.js';
import { camera, toScreen, drawOne } from 'goblin/canvas/view.js';
import { box } from 'goblin/canvas/geometry.js';
import { AXES, axisDelta, thickness, elevation, liftOf, numeric } from 'goblin/canvas/projection.js';
import { carrySet, isHiddenByCollapse } from 'goblin/core/containers.js';
import { minSize } from 'goblin/core/kinds.js';
import { snapTo } from 'goblin/core/settings.js';
import { group } from 'goblin/edit/history.js';
import { commitDrop } from 'goblin/edit/drop.js';
import * as scene from 'goblin/edit/scene.js';
import { t } from 'goblin/core/i18n.js';

const SVG='http://www.w3.org/2000/svg';
let layer,tools,mode='move',drag=null,frame=null;
const selected=()=>state.selection.size===1?state.elements.get([...state.selection][0]):null;
const usable=e=>e&&e.type!=='arrow'&&!e.style?.locked&&!isHiddenByCollapse(e)&&!state.viewOnly;
const value=(e,key)=>key==='thick'?thickness(e):key==='z'?elevation(e):key==='width'?box(e).w:key==='height'?box(e).h:numeric(e.style?.[key]);

export function initVolumeControls() {
  if(layer)return;
  const wrap=document.getElementById('canvas-wrap');
  layer=document.createElementNS(SVG,'svg');layer.setAttribute('class','iso-overlay');layer.setAttribute('aria-label',t('editor.volume.controls'));
  tools=document.createElement('div');tools.className='iso-tools';
  for(const [key,label] of [['move',t('editor.volume.move')],['size',t('editor.volume.size')]]) {
    const b=document.createElement('button');b.className='btn';b.type='button';b.textContent=label;
    b.dataset.volumeMode=key;b.onclick=()=>{mode=key;render()};tools.append(b);
  }
  wrap.append(layer,tools);
  layer.addEventListener('pointerdown',start);
  layer.addEventListener('pointermove',move);
  layer.addEventListener('pointerup',event=>finish(event,false));
  layer.addEventListener('pointercancel',event=>finish(event,true));
  layer.addEventListener('lostpointercapture',event=>{if(drag)finish(event,true)});
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&drag){event.preventDefault();finish(null,true)}});
  for(const event of ['selection','element','scheme','drop','camera','projection','variant'])on(event,queue);
  on('folder',()=>{if(drag)finish(null,true);queue()});
  render();
}
function queue(){if(frame!==null)return;frame=requestAnimationFrame(()=>{frame=null;render()})}
function render() {
  const e=selected(),show=state.iso&&state.variant==='canvas'&&usable(e);
  layer.style.display=show?'':'none';tools.hidden=!show;
  if(!show){if(drag)finish(null,true);return}
  for(const button of tools.querySelectorAll('button')) {
    button.classList.toggle('on',button.dataset.volumeMode===mode);
    button.setAttribute('aria-pressed',String(button.dataset.volumeMode===mode));
  }
  const b=box(e),origin=toScreen(b.x+b.w,b.y+b.h,liftOf(e));
  // Захват принадлежит SVG-слою: обновление самих ручек не обрывает жест.
  layer.innerHTML=Object.entries(AXES).map(([axis,v])=>{
    const x=origin.x+v.x*76,y=origin.y+v.y*76;
    const key=mode==='move'?axis:({x:'width',y:'height',z:'thick'}[axis]);
    const caption=mode==='move'?axis.toUpperCase():({x:t('editor.volume.cap_x'),y:t('editor.volume.cap_y'),z:t('editor.volume.cap_z')}[axis]);
    const hint=t(mode==='move'?'editor.volume.move_by':'editor.volume.size_by',{axis:axis.toUpperCase()});
    return `<g class="iso-axis iso-axis-${axis}${drag?.axis===axis?' active':''}" data-axis="${axis}" data-key="${key}">
      <path class="iso-axis-hit" d="M ${origin.x+v.x*28} ${origin.y+v.y*28} L ${x} ${y}"/>
      <path class="iso-axis-line" d="M ${origin.x} ${origin.y} L ${x} ${y}"/>
      <circle class="iso-axis-knob" cx="${x}" cy="${y}" r="12"/>
      <text x="${x}" y="${y}" class="iso-axis-label">${caption}</text><title>${hint}</title></g>`;
  }).join('');
  layer.classList.toggle('dragging',!!drag);
  if(drag){const p=toScreen(b.x+b.w,b.y+b.h,liftOf(e));layer.insertAdjacentHTML('beforeend',
    `<g class="iso-readout"><rect x="${p.x-35}" y="${p.y+13}" width="70" height="25" rx="6"/><text x="${p.x}" y="${p.y+26}">${drag.axis.toUpperCase()}: ${Math.round(value(e,drag.key))}</text></g>`)}
}
function start(event) {
  const handle=event.target.closest('[data-axis]'),e=selected();
  if(!handle||!usable(e)||event.button!==0)return;
  event.preventDefault();event.stopPropagation();
  const key=handle.dataset.key,carry=mode==='move'?carrySet(state.selection):new Map([[e.id,true]]);
  const originals=new Map();
  for(const id of carry.keys()) {
    const child=state.elements.get(id);if(!child||child.style?.locked)continue;
    originals.set(id,{style:{...child.style},value:value(child,key)});
  }
  drag={axis:handle.dataset.axis,key,x:event.clientX,y:event.clientY,pointer:event.pointerId,originals,carry,mode,moved:false};
  layer.setPointerCapture(event.pointerId);render();
}
function move(event) {
  if(!drag)return;
  const delta=axisDelta(drag.axis,event.clientX-drag.x,event.clientY-drag.y,camera.zoom);
  if(Math.abs(delta)<.5&&!drag.moved)return;drag.moved=true;
  for(const [id,original] of drag.originals) {
    const e=state.elements.get(id);if(!e)continue;
    let next=original.value+delta;
    if(event.shiftKey)next=snapTo(next);
    if(drag.key==='width'||drag.key==='height')next=Math.max(minSize(e.type)[drag.key],next);
    if(drag.key==='thick')next=Math.min(2000,Math.max(0,next));
    e.style={...e.style,[drag.key]:next};drawOne(e);
  }
  render();
}
function restore(e,original,key){
  e.style={...e.style};
  if(Object.hasOwn(original.style,key))e.style[key]=original.style[key];else delete e.style[key];
}
function finish(event,cancel) {
  if(!drag)return;
  if(event&&!cancel)move(event);
  const ended=drag;drag=null;
  if(layer.hasPointerCapture(ended.pointer))layer.releasePointerCapture(ended.pointer);
  group(t(ended.mode==='move'?'editor.volume.step_move':'editor.volume.step_size',{axis:ended.axis.toUpperCase()}),()=>{
    for(const [id,original] of ended.originals) {
      const e=state.elements.get(id);if(!e)continue;
      let next=snapTo(value(e,ended.key));
      if(ended.key==='width'||ended.key==='height')next=Math.max(minSize(e.type)[ended.key],next);
      if(ended.key==='thick')next=Math.min(2000,Math.max(0,next));
      restore(e,original,ended.key);
      if(!cancel&&ended.moved&&next!==original.value)scene.patch(id,{style:{[ended.key]:next}});
      else drawOne(e);
    }
    if(!cancel&&ended.moved&&ended.mode==='move'&&ended.key!=='z')commitDrop(ended.carry);
  });
  emit('panel');render();
}
