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
    qrTarget.innerHTML=qr.createSvgTag({cellSize:5,margin:0,scalable:true,alt:'QR code for the Catch Setup shortcut'});
  }

  const pairing=document.querySelector('[data-device-status-url][data-polling="true"]');
  if(pairing){
    let stopped=false;
    let expiring=false;
    let countdownTimer;
    const requestStatus=async()=>{const response=await fetch(pairing.dataset.deviceStatusUrl,{headers:{Accept:'application/json'},credentials:'same-origin'});if(!response.ok)return null;return response.json();};
    const check=async()=>{if(stopped||document.hidden)return;try{const status=await requestStatus();if(status&&(status.status==='connected'||Number(status.pairing_code_active)===0)){stopped=true;location.reload();}}catch{} };
    const timer=setInterval(check,7000);
    const expiresAt=Date.parse(pairing.dataset.pairingCodeExpiresAt||'');
    const countdown=pairing.querySelector('[data-pairing-countdown]');
    const expire=async()=>{if(expiring)return;expiring=true;stopped=true;clearInterval(timer);clearInterval(countdownTimer);try{await requestStatus();}catch{}finally{location.reload();}};
    const updateCountdown=()=>{const seconds=Math.max(0,Math.ceil((expiresAt-Date.now())/1000));const minutes=Math.floor(seconds/60);const remainder=String(seconds%60).padStart(2,'0');if(countdown)countdown.textContent=`${minutes}:${remainder}`;if(seconds===0)void expire();};
    if(Number.isFinite(expiresAt)){updateCountdown();countdownTimer=setInterval(updateCountdown,250);}
    check();
    window.addEventListener('pagehide',()=>{clearInterval(timer);clearInterval(countdownTimer);},{once:true});
  }
}
