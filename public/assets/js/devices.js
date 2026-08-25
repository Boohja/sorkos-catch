export function initDevices(){
  const flow=document.querySelector('[data-add-catch-flow]');
  if(flow){
    const platformStep=flow.querySelector('[data-platform-step]');
    const methodStep=flow.querySelector('[data-method-step]');
    const resultWrap=flow.querySelector('[data-setup-result]');
    const methodOptions=[...flow.querySelectorAll('[data-method-option]')];
    const resultSections=[...flow.querySelectorAll('[data-result]')];
    const value=(name)=>flow.querySelector(`input[name="${name}"]:checked`)?.value||'';
    const clear=(name)=>flow.querySelectorAll(`input[name="${name}"]`).forEach((input)=>{input.checked=false;});
    const showResult=(name,source,platform,method)=>{
      resultSections.forEach((section)=>{section.hidden=section.dataset.result!==name;});
      resultWrap.hidden=!name;
      if(!name)return;
      const shortcutPlatform=flow.querySelector('[data-shortcut-platform]');
      if(shortcutPlatform)shortcutPlatform.value=platform;
      const shortcutCopy=flow.querySelector('[data-shortcut-result-copy]');
      if(shortcutCopy){
        const action=method==='voice'?'voice capture':method==='image'?'image and screenshot capture':'sharing from other apps';
        shortcutCopy.textContent=`Create the connection for ${action}. Catch will then walk you through the setup shortcut and a temporary pairing code.`;
      }
      const shareNote=flow.querySelector('[data-share-target-note]');
      if(shareNote)shareNote.hidden=!(platform==='android'&&method==='share');
      const extensionTitle=flow.querySelector('[data-extension-title]');
      if(extensionTitle)extensionTitle.textContent=platform==='firefox'?'Install the Firefox extension':'Install the Chrome extension';
      resultWrap.scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',block:'nearest'});
    };
    const sync=()=>{
      const source=value('catch_source');
      const platform=value('catch_platform');
      const needsPlatform=['phone','computer'].includes(source);
      platformStep.hidden=!needsPlatform;
      flow.querySelector('[data-phone-platforms]').hidden=source!=='phone';
      flow.querySelector('[data-computer-platforms]').hidden=source!=='computer';
      flow.querySelector('[data-platform-question]').textContent=source==='computer'?'Where on the computer?':'Which platform?';
      const canChooseMethod=source==='automation'||(needsPlatform&&platform);
      methodOptions.forEach((option)=>{
        const sourceMatches=option.dataset.sources.split(' ').includes(source);
        const platforms=option.dataset.platforms.split(' ');
        option.hidden=!sourceMatches||(!platforms.includes('any')&&!platforms.includes(platform));
        if(option.hidden)option.querySelector('input').checked=false;
      });
      const visibleMethods=methodOptions.filter((option)=>!option.hidden);
      if(canChooseMethod&&visibleMethods.length===1){
        visibleMethods[0].querySelector('input').checked=true;
        methodStep.hidden=true;
      }else methodStep.hidden=!canChooseMethod;
      let result='';
      const selectedMethod=value('catch_method');
      if(source==='email')result='email';
      else if(source==='phone'&&platform==='ios'&&['share','voice','image'].includes(selectedMethod))result='shortcut';
      else if(source==='phone'&&platform&&(selectedMethod==='manual'||platform==='android'))result='pwa';
      else if(source==='computer'&&selectedMethod==='browser')result='extension';
      else if(source==='computer'&&selectedMethod==='manual'&&platform==='web')result='pwa';
      else if(source==='computer'&&selectedMethod==='manual')result='web';
      else if(selectedMethod==='cli')result='cli';
      else if(selectedMethod==='api')result='api';
      showResult(result,source,platform,selectedMethod);
    };
    flow.addEventListener('change',(event)=>{
      if(event.target.name==='catch_source'){
        clear('catch_platform');
        clear('catch_method');
      }else if(event.target.name==='catch_platform')clear('catch_method');
      sync();
    });

    const ua=navigator.userAgent;
    const isIPad=/iPad/.test(ua)||(navigator.platform==='MacIntel'&&navigator.maxTouchPoints>1);
    const isIPhone=/iPhone/.test(ua);
    const isAndroid=/Android/.test(ua);
    const detectedSource=isIPad||isIPhone||isAndroid?'phone':'computer';
    const detectedPlatform=isIPad||isIPhone?'ios':isAndroid?'android':/Firefox\//.test(ua)?'firefox':/(Chrome|Chromium|Edg|OPR)\//.test(ua)?'chromium':/Windows/.test(ua)?'terminal-windows':/Linux/.test(ua)?'terminal-linux':'web';
    const detectedLabel={ios:'iPhone / iPad',android:'Android device',firefox:'Firefox',chromium:'Chrome or Chromium browser','terminal-windows':'Windows computer','terminal-linux':'Linux computer',web:'web browser'}[detectedPlatform];
    const detectedIcon={ios:'brand-apple',android:'brand-android',firefox:'brand-firefox',chromium:'brand-chrome','terminal-windows':'brand-windows','terminal-linux':'brand-linux',web:'app'}[detectedPlatform];
    const sourceInput=flow.querySelector(`input[name="catch_source"][value="${detectedSource}"]`);
    const platformInput=flow.querySelector(`input[name="catch_platform"][value="${detectedPlatform}"]`);
    if(sourceInput&&platformInput){
      sourceInput.checked=true;
      platformInput.checked=true;
      const detected=flow.querySelector('[data-detected-device]');
      detected.hidden=false;
      detected.querySelector('[data-detected-icon]').className=`detected-glyph glyph glyph-${detectedIcon}`;
      detected.querySelector('[data-detected-title]').textContent=`Detected: ${detectedLabel}`;
      detected.querySelector('[data-detected-copy]').textContent='Preselected below. Choose another option to set up something else.';
    }
    sync();

    let installPrompt;
    const installButton=flow.querySelector('[data-install-pwa]');
    window.addEventListener('beforeinstallprompt',(event)=>{
      event.preventDefault();
      installPrompt=event;
      if(installButton)installButton.hidden=false;
    });
    installButton?.addEventListener('click',async()=>{
      if(!installPrompt)return;
      await installPrompt.prompt();
      await installPrompt.userChoice;
      installPrompt=null;
      installButton.hidden=true;
    });
    flow.querySelector('[data-extension-store]')?.addEventListener('click',(event)=>{
      event.preventDefault();
      window.Catch?.notify?.('The extension store listing is not published yet.');
    });
  }

  const qrTarget=document.querySelector('[data-qr-code]');
  if(qrTarget&&typeof globalThis.qrcode==='function'){
    const value=qrTarget.dataset.qrBase64?atob(qrTarget.dataset.qrBase64):qrTarget.dataset.qrValue;
    const alt=qrTarget.dataset.qrAlt||qrTarget.getAttribute('aria-label')||'QR code';
    const qr=globalThis.qrcode(0,'M');qr.addData(value);qr.make();
    qrTarget.innerHTML=qr.createSvgTag({cellSize:5,margin:0,scalable:true,alt});
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
