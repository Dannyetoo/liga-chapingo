'use strict';
if('serviceWorker' in navigator)navigator.serviceWorker.register('./sw.js').catch(()=>{});
let invitacion;
const instalar=document.createElement('button');
instalar.type='button';instalar.textContent='Instalar app';instalar.hidden=true;
document.querySelector('header').append(instalar);
window.addEventListener('beforeinstallprompt',e=>{e.preventDefault();invitacion=e;instalar.hidden=false;});
instalar.onclick=async()=>{if(!invitacion)return;await invitacion.prompt();await invitacion.userChoice;invitacion=null;instalar.hidden=true;};
window.addEventListener('appinstalled',()=>{invitacion=null;instalar.hidden=true;});
