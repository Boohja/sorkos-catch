export function initRequestProgress(){
  const indicator=document.querySelector('[data-request-progress]');
  if(!indicator||window.__catchFetchProgress)return;
  window.__catchFetchProgress=true;
  const nativeFetch=window.fetch.bind(window);let active=0,hideTimer=0;
  const sync=()=>{clearTimeout(hideTimer);if(active>0){indicator.hidden=false;requestAnimationFrame(()=>indicator.classList.add('is-active'));return;}hideTimer=window.setTimeout(()=>{indicator.classList.remove('is-active');window.setTimeout(()=>{if(active===0)indicator.hidden=true},180)},180)};
  window.fetch=async(...args)=>{active++;sync();try{return await nativeFetch(...args)}finally{active=Math.max(0,active-1);sync()}};
}
