<?php
$metadata=is_array($capture['metadata']??null)?$capture['metadata']:[];
$utc=static fn(?string $value): string=>$value?str_replace(' ','T',substr($value,0,19)).'Z':'';
$safeHttp=static fn(mixed $value): ?string=>is_string($value)&&preg_match('~^https?://~i',$value)?$value:null;
$sourceUrl=$safeHttp($metadata['source_url']??null)??$safeHttp($metadata['referring_page_url']??null)??$safeHttp($capture['url']??null);
$sourceTitle=trim((string)($metadata['source_title']??$metadata['referring_page_title']??$metadata['page_title']??''));
if($sourceTitle===''&&$sourceUrl&&$sourceUrl===$capture['url'])$sourceTitle=trim((string)($capture['title']??''));
$sourceDomain=trim((string)($metadata['source_domain']??''));
if($sourceDomain===''&&$sourceUrl)$sourceDomain=(string)(parse_url($sourceUrl,PHP_URL_HOST)??'');
$linkedUrl=$safeHttp($metadata['linked_url']??null);
$context=(string)($metadata['browser_context']??'');
$method=(string)($metadata['capture_method']??'');
if($method==='')$method=$capture['source']==='browser-extension'?(str_contains($context,'context-menu')?'browser-extension-context-menu':'browser-extension'):(string)$capture['source'];
$methodLabel=match($method){
  'browser-extension-context-menu'=>'Browser Extension, Context Menu',
  'browser-extension'=>'Browser Extension',
  'ios-shortcut'=>'iOS Shortcut',
  'web'=>'Catch Web',
  default=>ucwords(str_replace(['-','_'],' ',$method)),
};
$primaryImage=null;
if($capture['type']==='image')foreach($capture['attachments'] as $attachment){if(str_starts_with((string)$attachment['mime_type'],'image/')){$primaryImage=$attachment;break;}}
$textMatchesTitle=$capture['text']&&trim((string)$capture['text'])===trim((string)$capture['title']);
$urlIsPrimary=!$primaryImage&&!empty($capture['url'])&&($capture['type']==='url'||empty($capture['text'])||$textMatchesTitle);
$deviceLabel=trim((string)($capture['device_name']??''))?:match((string)$capture['source']){'web'=>'Catch Web','browser-extension'=>'Browser Extension','ios-shortcut'=>'iOS Shortcut',default=>ucwords(str_replace(['-','_'],' ',(string)$capture['source']))};
$heading='#'.(int)$capture['catch_number'].(!empty($capture['title'])?' - '.$capture['title']:'');
$remainingAttachments=array_values(array_filter($capture['attachments'],static fn(array $attachment): bool=>!$primaryImage||$attachment['id']!==$primaryImage['id']));
?>
<a class="back-link" href="/inbox">&larr; Back to inbox</a>
<article class="detail">
  <header>
    <h1><?=htmlspecialchars($heading)?></h1>
    <p><time datetime="<?=htmlspecialchars($utc($capture['created_at']))?>" data-local-time data-date-style="medium" data-time-style="short">UTC <?=htmlspecialchars($capture['created_at'])?></time></p>
  </header>
  <section class="capture-primary"><h2>Content</h2>
    <?php if($primaryImage): ?><?php $primaryImageUrl='/attachments/'.urlencode($primaryImage['id']); ?>
      <?php if(!empty($primaryImage['available'])): ?><figure class="primary-image"><a href="<?=htmlspecialchars($primaryImageUrl)?>" target="_blank"><img src="<?=htmlspecialchars($primaryImageUrl)?>" alt="<?=htmlspecialchars($primaryImage['original_name'])?>"></a></figure>
      <?php else: ?><div class="missing-attachment" role="status"><strong><?=htmlspecialchars($primaryImage['original_name'])?></strong><span>The stored image file is unavailable.</span></div><?php endif; ?>
    <?php elseif($urlIsPrimary): ?><a class="url-card" href="<?=htmlspecialchars($capture['url'])?>" target="_blank" rel="noopener noreferrer"><span><?=htmlspecialchars($capture['title']?:$capture['url'])?><small><?=htmlspecialchars($capture['url'])?></small></span><i class="glyph glyph-link" aria-hidden="true"></i></a>
    <?php elseif($capture['text']): ?><div class="prose"><?=nl2br(htmlspecialchars($capture['text']))?></div>
    <?php elseif($capture['url']): ?><a class="url-card" href="<?=htmlspecialchars($capture['url'])?>" target="_blank" rel="noopener noreferrer"><span><?=htmlspecialchars($capture['title']?:$capture['url'])?><small><?=htmlspecialchars($capture['url'])?></small></span><i class="glyph glyph-link" aria-hidden="true"></i></a>
    <?php else: ?><p class="muted">No preview is available.</p><?php endif; ?>
  </section>
  <?php if($capture['extracted_text']): ?><details><summary>Extracted text</summary><div class="prose"><?=nl2br(htmlspecialchars($capture['extracted_text']))?></div></details><?php endif; ?>
  <?php if($remainingAttachments): ?><section><h2>Attachments</h2><div class="attachment-list"><?php foreach($remainingAttachments as $attachment): ?><?php $attachmentUrl='/attachments/'.urlencode($attachment['id']); ?>
    <?php if(str_starts_with($attachment['mime_type'],'image/')&&!empty($attachment['available'])): ?><figure class="image-attachment"><a href="<?=htmlspecialchars($attachmentUrl)?>" target="_blank"><img src="<?=htmlspecialchars($attachmentUrl)?>" alt="<?=htmlspecialchars($attachment['original_name'])?>" loading="lazy"></a><figcaption><strong><?=htmlspecialchars($attachment['original_name'])?></strong><span><?=htmlspecialchars($attachment['mime_type'])?> &middot; <?=number_format($attachment['size_bytes']/1024,1,'.',',')?> KB<?php if($attachment['width']&&$attachment['height']): ?> &middot; <?=(int)$attachment['width']?> &times; <?=(int)$attachment['height']?><?php endif; ?></span></figcaption></figure>
    <?php elseif(str_starts_with($attachment['mime_type'],'image/')): ?><div class="missing-attachment" role="status"><strong><?=htmlspecialchars($attachment['original_name'])?></strong><span>The stored image file is unavailable.</span></div>
    <?php else: ?><a class="file-attachment" href="<?=htmlspecialchars($attachmentUrl)?>"><strong><?=htmlspecialchars($attachment['original_name'])?></strong><span><?=htmlspecialchars($attachment['mime_type'])?> &middot; <?=number_format($attachment['size_bytes']/1024,1,'.',',')?> KB</span></a><?php endif; ?>
  <?php endforeach;?></div></section><?php endif; ?>
  <section class="capture-metadata"><h2>Captured from</h2><dl>
    <div><dt>Device</dt><dd><?php if(!empty($capture['device_id'])): ?><a href="/devices/<?=urlencode($capture['device_id'])?>"><?=htmlspecialchars($deviceLabel)?></a><?php else: ?><?=htmlspecialchars($deviceLabel)?><?php endif; ?></dd></div>
    <?php if($sourceUrl): ?><div><dt>Web</dt><dd><a href="<?=htmlspecialchars($sourceUrl)?>" target="_blank" rel="noopener noreferrer"><?=htmlspecialchars($sourceTitle?:$sourceDomain?:$sourceUrl)?></a><?php if($linkedUrl): ?><small>Wrapping link: <a href="<?=htmlspecialchars($linkedUrl)?>" target="_blank" rel="noopener noreferrer"><?=htmlspecialchars($linkedUrl)?></a></small><?php endif; ?></dd></div><?php endif; ?>
    <div><dt>Method</dt><dd><?=htmlspecialchars($methodLabel)?></dd></div>
  </dl></section>
  <footer class="detail-actions"><form method="post" action="/captures/<?=urlencode($capture['id'])?>/archive"><input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>"><button class="button button-secondary">Archive</button></form><form method="post" action="/captures/<?=urlencode($capture['id'])?>/delete" onsubmit="return confirm('Delete this capture?')"><input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>"><button class="button button-danger">Delete</button></form></footer>
</article>
