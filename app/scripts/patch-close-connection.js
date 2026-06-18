/**
 * Apply Windows hot-fix: "Close Connection" in connection tree (shared navicat-ui bundle).
 * Run after frontend build: node scripts/patch-close-connection.js
 */
const fs = require('fs');
const path = require('path');

const indexHtml = fs.readFileSync(path.join(__dirname, '../public/index.html'), 'utf8');
const match = indexHtml.match(/\/assets\/(index-[^"]+\.js)/);
if (!match) {
  console.error('No bundle script in public/index.html');
  process.exit(1);
}
const bundlePath = path.join(__dirname, '../public/assets', match[1]);

let s = fs.readFileSync(bundlePath, 'utf8');

if (s.includes('closeConn:')) {
  console.log('Already patched:', bundlePath);
  process.exit(0);
}

const replacements = [
  [
    'toggleConn:n=>e(r=>{const s=new Set(r.expandedConns);return s.has(n)?s.delete(n):s.add(n),{expandedConns:s}}),toggleDb',
    'toggleConn:n=>e(r=>{const s=new Set(r.expandedConns);return s.has(n)?s.delete(n):s.add(n),{expandedConns:s}}),closeConn:n=>e(r=>{const s=new Set(r.expandedConns);s.delete(n);const prefix=n+":";return{expandedConns:s,expandedDbs:new Set([...r.expandedDbs].filter(x=>!x.startsWith(prefix))),expandedCategories:new Set([...r.expandedCategories].filter(x=>!x.startsWith(prefix))),expandedTables:new Set([...r.expandedTables].filter(x=>!x.startsWith(prefix))),expandedTableParts:new Set([...r.expandedTableParts].filter(x=>!x.startsWith(prefix))),selectedConnId:r.selectedConnId===n?null:r.selectedConnId,selectedDb:r.selectedConnId===n?null:r.selectedDb}}),toggleDb',
  ],
  [
    'function Zf({conn:e,onEdit:t,onRefresh:n,groups:r}){const{expandedConns:s,toggleConn:i,selectConn:a,selectedConnId:l,openTab:E}=it()',
    'function Zf({conn:e,onEdit:t,onRefresh:n,groups:r}){const{expandedConns:s,toggleConn:i,closeConn:closeConnFn,selectConn:a,selectedConnId:l,openTab:E}=it()',
  ],
  [
    '{label:T?"Collapse":"Open Connection",icon:o.jsx(qu,{size:12}),onClick:()=>{a(e.id),T||i(e.id)}}',
    '{label:T?"Close Connection / Cerrar conexión":"Open Connection / Abrir conexión",icon:o.jsx(qu,{size:12}),onClick:()=>{a(e.id),T?(closeConnFn(e.id),c.removeQueries({queryKey:["dbs",e.id]})):i(e.id)}}',
  ],
];

for (const [oldStr, newStr] of replacements) {
  if (!s.includes(oldStr)) {
    console.error('Pattern not found:', oldStr.slice(0, 80));
    process.exit(1);
  }
  s = s.replace(oldStr, newStr);
}

fs.writeFileSync(bundlePath, s);
console.log('Patched', bundlePath);
