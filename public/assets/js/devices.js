export function initDevices(){
  const wizard=document.querySelector('[data-device-wizard]');
  if(wizard){
    const platformStep=wizard.querySelector('[data-platform-step]');
    const nameStep=wizard.querySelector('[data-name-step]');
    const sync=()=>{const kind=wizard.elements.kind?.value;const platform=wizard.elements.platform?.value;platformStep.hidden=kind!=='mobile';nameStep.hidden=!['ios','ipados'].includes(platform);};
    wizard.addEventListener('change',sync);sync();
  }

  const qrTarget=document.querySelector('[data-qr-code]');
  if(qrTarget&&typeof globalThis.qrcode==='function'){
    const qr=globalThis.qrcode(0,'M');qr.addData(qrTarget.dataset.qrValue);qr.make();
    qrTarget.innerHTML=qr.createSvgTag({cellSize:5,margin:0,scalable:true,alt:'QR-Code zum Catch Setup-Kurzbefehl'});
  }

  const pairing=document.querySelector('[data-device-status-url][data-polling="true"]');
  if(pairing){
    let stopped=false;
    const check=async()=>{if(stopped||document.hidden)return;try{const response=await fetch(pairing.dataset.deviceStatusUrl,{headers:{Accept:'application/json'},credentials:'same-origin'});if(!response.ok)return;const status=await response.json();if(status.status==='connected'){stopped=true;location.reload();}}catch{} };
    check();const timer=setInterval(check,7000);window.addEventListener('pagehide',()=>clearInterval(timer),{once:true});
  }
}
