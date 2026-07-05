import{ag as o,a8 as c,ai as n}from"./index-DF0M5oiR.js";/**
 * @license lucide-react v1.16.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const _=[["rect",{width:"18",height:"11",x:"3",y:"11",rx:"2",ry:"2",key:"1w4ew1"}],["path",{d:"M7 11V7a5 5 0 0 1 9.9-1",key:"1mm8w8"}]],m=o("lock-open",_);/**
 * @license lucide-react v1.16.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const u=[["rect",{width:"18",height:"11",x:"3",y:"11",rx:"2",ry:"2",key:"1w4ew1"}],["path",{d:"M7 11V7a5 5 0 0 1 10 0v4",key:"fwvmzm"}]],g=o("lock",u);function s(e){const a=typeof e=="number"?e:Number(e??0);return Number.isFinite(a)?a:0}const h={getPendientes:async(e,a)=>{const r={empresa_id:e};return a&&(r.sucursal_id=a),((await c.get(n.cierres.pendientes,{params:r})).pendientes??[]).map(t=>({fecha:String(t.fecha??"").slice(0,10),sucursal_id:s(t.sucursal_id),sucursal_nombre:String(t.sucursal_nombre??"Sucursal"),total_ventas:s(t.total_ventas),cantidad_ventas:s(t.cantidad_ventas)}))},getCierres:async(e,a,r,d)=>{const t={empresa_id:e,sucursal_id:a};return r&&(t.fecha_desde=r),d&&(t.fecha_hasta=d),((await c.get(n.cierres.list,{params:t})).cierres??[]).map(i=>({id:s(i.id),sucursal_id:s(i.sucursal_id),fecha_cierre:String(i.fecha_cierre??"").slice(0,10),estado:String(i.estado??""),total_ventas:s(i.total_ventas),cantidad_ventas:s(i.cantidad_ventas)}))},cerrarDia:e=>c.post(n.cierres.create,e),reabrir:(e,a)=>c.post(n.cierres.reabrir(e),a),getDetalle:async(e,a)=>await c.get(`${n.cierres.detail(e)}?empresa_id=${a}`)};export{g as L,m as a,h as c};
