export function initCaptureLists(){
  const root=document.querySelector('[data-capture-lists]');if(!root)return;
  const dialog=root.querySelector('[data-list-dialog]'),open=root.querySelector('[data-open-list-dialog]'),close=root.querySelector('[data-close-list-dialog]'),form=root.querySelector('[data-list-form]'),assigned=root.querySelector('[data-assigned-lists]'),status=root.querySelector('[data-list-status]'),archiveAction=root.querySelector('[data-archive-action]');
  const setStatus=(text,error=false)=>{if(status){status.textContent=text;status.classList.toggle('is-error',error)}};
  const setCaptureStatus=value=>{if(archiveAction)archiveAction.hidden=value!=='inbox'};
  const renderLists=lists=>{if(!assigned)return;assigned.replaceChildren(...lists.map(list=>{const link=document.createElement('a');link.dataset.listId=list.id;link.href=list.url;link.textContent=list.title;return link}))};
  open?.addEventListener('click',()=>{if(typeof dialog?.showModal==='function')dialog.showModal()});
  close?.addEventListener('click',()=>dialog?.close());
  dialog?.addEventListener('click',event=>{if(event.target===dialog)dialog.close()});
  form?.addEventListener('submit',async event=>{
    event.preventDefault();const submit=form.querySelector('[type=submit]');if(submit)submit.disabled=true;setStatus('Saving lists…');
    try{const response=await fetch(form.action,{method:'POST',headers:{Accept:'application/json'},body:new FormData(form)}),json=await response.json().catch(()=>({}));if(!response.ok)throw new Error(json.error||'The list changes could not be saved.');renderLists(json.lists||[]);setCaptureStatus(json.capture_status);setStatus(json.capture_status==='inbox'?'Lists updated. Returned to Inbox.':'Lists updated. Moved to Archived.');dialog?.close();}
    catch(error){setStatus(error.message,true)}finally{if(submit)submit.disabled=false}
  });
}
