export function initCaptureBulk(){
  const form=document.querySelector('[data-bulk-actions]');
  if(!form)return;
  const boxes=Array.from(document.querySelectorAll(`input[type="checkbox"][form="${CSS.escape(form.id)}"][name="capture_ids[]"]`));
  const count=form.querySelector('[data-bulk-count]');
  const open=form.querySelector('[data-open-bulk-delete]');
  const openLists=form.querySelector('[data-open-bulk-lists]');
  const dialog=document.querySelector('[data-bulk-delete-dialog]');
  const description=dialog?.querySelector('[data-bulk-delete-description]');
  const confirm=dialog?.querySelector('[data-confirm-bulk-delete]');
  const permanent=form.dataset.permanent==='true';
  const selected=()=>boxes.filter(box=>box.checked);
  const sync=()=>{const total=selected().length;form.hidden=total===0;if(count)count.textContent=`${total} ${total===1?'capture':'captures'} selected`;if(total===0&&dialog?.open)dialog.close();};
  boxes.forEach(box=>box.addEventListener('change',sync));
  open?.addEventListener('click',()=>{const total=selected().length;if(!total)return;const message=permanent?`${total===1?'This capture and its attachments will':'These '+total+' captures and their attachments will'} be permanently deleted. This action cannot be undone.`:`${total===1?'This capture will':'These '+total+' captures will'} stay in Trash for 30 days and can be restored.`;if(description)description.textContent=message;if(typeof dialog?.showModal==='function')dialog.showModal();else if(window.confirm(message))form.requestSubmit();});
  openLists?.addEventListener('click',()=>{const ids=selected().map(box=>box.value);if(!ids.length)return;window.Catch?.openListDialog?.({captureIds:ids,assignedListIds:[],mode:'bulk'});});
  form.addEventListener('submit',()=>{if(confirm)confirm.disabled=true;if(open)open.disabled=true;});
  sync();
}
