import{createLucideIcon,jsxRuntimeExports}from"./index-xlnfMN3M.js";import{Copy,Terminal,notify,notifyError}from"./bundle-hiUU1khd.js";import{sendSqlToTerminal}from"./sendSqlToTerminal-Ggtg8QdA.js";/**
 * @license lucide-react v0.454.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const ChartColumn=createLucideIcon("ChartColumn",[["path",{d:"M3 3v16a2 2 0 0 0 2 2h16",key:"c24i48"}],["path",{d:"M18 17V9",key:"2bz60n"}],["path",{d:"M13 17V5",key:"1frdt8"}],["path",{d:"M8 17v-3",key:"17ska0"}]]);/**
 * @license lucide-react v0.454.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Check=createLucideIcon("Check",[["path",{d:"M20 6 9 17l-5-5",key:"1gmf2c"}]]);/**
 * @license lucide-react v0.454.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Lightbulb=createLucideIcon("Lightbulb",[["path",{d:"M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5",key:"1gvzjb"}],["path",{d:"M9 18h6",key:"x1upvd"}],["path",{d:"M10 22h4",key:"ceow96"}]]);/**
 * @license lucide-react v0.454.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Pin=createLucideIcon("Pin",[["path",{d:"M12 17v5",key:"bb1du9"}],["path",{d:"M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z",key:"1nkz8b"}]]);function SqlScriptActions({sql,connectionId,database,copyLabel="Copiar",showTerminal=!0}){const text=String(sql??"").trim();if(!text)return null;const onCopy=async()=>{try{await navigator.clipboard.writeText(text),notify("Copiado al portapapeles")}catch{notifyError(new Error("No se pudo copiar"))}},onTerminal=()=>{if(!connectionId){notifyError(new Error("No hay conexión activa para el terminal"));return}sendSqlToTerminal({connectionId,database,sql:text})};return jsxRuntimeExports.jsxs("div",{className:"flex flex-wrap gap-1",children:[jsxRuntimeExports.jsxs("button",{type:"button",className:"nv-btn !text-2xs",onClick:onCopy,children:[jsxRuntimeExports.jsx(Copy,{size:10})," ",copyLabel]}),showTerminal&&connectionId&&jsxRuntimeExports.jsxs("button",{type:"button",className:"nv-btn !text-2xs",onClick:onTerminal,children:[jsxRuntimeExports.jsx(Terminal,{size:10})," Enviar al Terminal SQL"]})]})}export{ChartColumn,Check,Lightbulb,Pin,SqlScriptActions};
//# sourceMappingURL=SqlScriptActions-6MnXk1GK.js.map
