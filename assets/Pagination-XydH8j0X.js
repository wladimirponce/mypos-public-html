import{ap as m,aA as e,an as p,f as u}from"./index-BPyNox5e.js";import{C as b}from"./chevron-right-BcUfLwlV.js";/**
 * @license lucide-react v1.16.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const j=[["path",{d:"m15 18-6-6 6-6",key:"1wnfg3"}]],v=m("chevron-left",j);/**
 * @license lucide-react v1.16.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const y=[["path",{d:"m11 17-5-5 5-5",key:"13zhaf"}],["path",{d:"m18 17-5-5 5-5",key:"h8a8et"}]],N=m("chevrons-left",y);/**
 * @license lucide-react v1.16.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const w=[["path",{d:"m6 17 5-5-5-5",key:"xnjwq"}],["path",{d:"m13 17 5-5-5-5",key:"17xmmf"}]],g=m("chevrons-right",w);function M({page:r,totalPages:s,totalItems:t,perPage:a,itemLabel:x="registros",disabled:o=!1,onPageChange:l}){const c=Math.max(1,s),n=Math.min(Math.max(1,r),c),f=t===0?0:(n-1)*a+1,h=Math.min(n*a,t);return e.jsxs("div",{className:"flex flex-col gap-3 border-t border-slate-100 pt-4 lg:flex-row lg:items-center lg:justify-between",children:[e.jsxs("p",{className:"text-xs text-muted-foreground",children:["Mostrando ",e.jsxs("span",{className:"font-semibold text-slate-700",children:[f,"-",h]})," de"," ",e.jsx("span",{className:"font-semibold text-slate-700",children:t})," ",x]}),e.jsxs("nav",{className:"flex flex-wrap items-center gap-1","aria-label":"Paginacion",children:[e.jsx(d,{label:"Primera pagina",disabled:o||n===1,onClick:()=>l(1),children:e.jsx(N,{className:"h-4 w-4"})}),e.jsx(d,{label:"Pagina anterior",disabled:o||n===1,onClick:()=>l(n-1),children:e.jsx(v,{className:"h-4 w-4"})}),k(n,c).map(i=>typeof i=="number"?e.jsx("button",{type:"button","aria-label":`Pagina ${i}`,"aria-current":i===n?"page":void 0,disabled:o,onClick:()=>l(i),className:p("h-8 min-w-8 rounded-md border px-2 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary disabled:pointer-events-none disabled:opacity-50",i===n?"border-primary bg-primary text-primary-foreground shadow-sm":"border-border bg-white text-slate-700 hover:bg-slate-50"),children:i},i):e.jsx("span",{className:"flex h-8 min-w-7 items-center justify-center text-xs text-muted-foreground",children:"..."},i)),e.jsx(d,{label:"Pagina siguiente",disabled:o||n===c,onClick:()=>l(n+1),children:e.jsx(b,{className:"h-4 w-4"})}),e.jsx(d,{label:"Ultima pagina",disabled:o||n===c,onClick:()=>l(c),children:e.jsx(g,{className:"h-4 w-4"})})]})]})}function d({label:r,disabled:s,onClick:t,children:a}){return e.jsx(u,{variant:"secondary",size:"sm",className:"h-8 w-8 px-0","aria-label":r,title:r,disabled:s,onClick:t,children:a})}function k(r,s){return s<=7?Array.from({length:s},(t,a)=>a+1):r<=4?[1,2,3,4,5,"ellipsis-end",s]:r>=s-3?[1,"ellipsis-start",s-4,s-3,s-2,s-1,s]:[1,"ellipsis-start",r-1,r,r+1,"ellipsis-end",s]}export{M as P};
