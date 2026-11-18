(function(){
  'use strict';
  var cfg=window.mmafNotifications||{};var session=null;
  // A GET on purpose: admin-ajax POSTs whose action starts with "mmaf_" wait for the canonical write lock, which a running import holds.
  function sessionInfo(){if(session)return Promise.resolve(session);var url=String(cfg.ajaxUrl||'');url+=(url.indexOf('?')<0?'?':'&')+'action=mmaf_notification_session';return fetch(url,{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.json();}).then(function(r){session=r.data||{loggedIn:false};return session;});}
  function api(path,options){options=options||{};return sessionInfo().then(function(s){options.credentials='same-origin';options.headers=Object.assign({'Content-Type':'application/json','X-WP-Nonce':s.nonce||''},options.headers||{});return fetch(cfg.restUrl+path,options).then(function(r){return r.json().then(function(data){if(!r.ok)throw new Error(data.message||'Request failed.');return data;});});});}
  function buttons(){return Array.prototype.slice.call(document.querySelectorAll('[data-mmaf-follow]'));}
  function apply(button,on){button.setAttribute('aria-pressed',on?'true':'false');button.classList.toggle('is-following',on);var label=button.querySelector('[data-mmaf-follow-label]');if(label)label.textContent=on?'Following':(button.getAttribute('data-mmaf-label-off')||'Follow fighter');button.disabled=false;}
  function loadState(){var all=buttons(),ids=all.map(function(b){return b.getAttribute('data-mmaf-follow');}).filter(Boolean);if(!ids.length)return;sessionInfo().then(function(s){if(!s.loggedIn){all.forEach(function(b){apply(b,false);});return;}api('state?fighter_ids='+encodeURIComponent(ids.join(','))).then(function(data){all.forEach(function(b){apply(b,!!data.following[b.getAttribute('data-mmaf-follow')]);});});});}
  // Sign in, register and "forgot password" share one form shape. Answers are never assumed to be JSON: backups answer writes with a plain-text 503.
  var AUTH_ROUTES={login:'auth/login',register:'auth/register',forgot:'auth/password/forgot'};
  function authError(f,field,message){var note=f.querySelector('[data-mmaf-auth-error="'+field+'"]'),input=f.querySelector('[name="'+field+'"]');if(note){note.textContent=message||'';note.hidden=!message;}if(input){if(message){input.setAttribute('aria-invalid','true');}else{input.removeAttribute('aria-invalid');}}}
  function submitAuth(f){
    var mode=f.getAttribute('data-mmaf-auth'),route=AUTH_ROUTES[mode],d=new FormData(f),out=f.querySelector('[data-mmaf-auth-message]'),sb=f.querySelector('button[type="submit"]');if(!route)return;
    var email=String(d.get('email')||'').trim(),password=String(d.get('password')||'');authError(f,'email','');authError(f,'password','');out.textContent='';
    if(!email){authError(f,'email','Enter your email address.');f.querySelector('[name="email"]').focus();return;}
    if(mode!=='forgot'&&!password){authError(f,'password','Enter your password.');f.querySelector('[name="password"]').focus();return;}
    if(sb){sb.disabled=true;}out.textContent=mode==='login'?'Signing in…':'Sending…';
    fetch(cfg.restUrl+route,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({email:email,password:password,fighter_id:Number(d.get('fighter_id')||0),return_to:d.get('return_to')})})
      .then(function(r){return r.text().then(function(text){var data=null;try{data=JSON.parse(text);}catch(err){data=null;}return{ok:r.ok,status:r.status,data:data||{}};});})
      .then(function(r){var v=r.data;
        if(r.ok&&v.redirect){out.textContent='Signed in. Taking you back…';window.location=v.redirect;return;}
        if(r.ok){out.textContent=v.message||'Check your email.';var pw=f.querySelector('[name="password"]');if(pw){pw.value='';}return;}
        out.textContent='';var field=v.data&&v.data.field;
        if(field&&f.querySelector('[name="'+field+'"]')){authError(f,field,v.message);f.querySelector('[name="'+field+'"]').focus();}
        else{out.textContent=v.message||(r.status===503?'The site is paused for a moment. Try again in a minute.':'Something went wrong. Try again.');}})
      .catch(function(){out.textContent='Please try again in a moment.';})
      .then(function(){if(sb){sb.disabled=false;}});
  }
  document.addEventListener('click',function(e){
    var toggle=e.target.closest('[data-mmaf-password-toggle]');
    if(toggle){var input=document.getElementById(toggle.getAttribute('aria-controls'));if(input){var show=input.type==='password';input.type=show?'text':'password';toggle.setAttribute('aria-pressed',show?'true':'false');toggle.textContent=show?'Hide':'Show';}return;}
    var link=e.target.closest('[data-mmaf-password-link]');
    if(link){var note=document.querySelector('[data-mmaf-password-message]');link.disabled=true;if(note){note.textContent='Sending…';}api('password-link',{method:'POST'}).then(function(v){if(note){note.textContent=v.message||'Check your email.';}}).catch(function(err){if(note){note.textContent=err.message;}}).then(function(){link.disabled=false;});}
  });
  document.addEventListener('click',function(e){var b=e.target.closest('[data-mmaf-follow]');if(b){e.preventDefault();var id=b.getAttribute('data-mmaf-follow');b.disabled=true;sessionInfo().then(function(s){if(!s.loggedIn){window.location=cfg.loginUrl+'?fighter_id='+encodeURIComponent(id)+'&return_to='+encodeURIComponent(window.location.href);return;}var on=b.getAttribute('aria-pressed')==='true';api('subscriptions'+(on?'/'+id:''),{method:on?'DELETE':'POST',body:on?undefined:JSON.stringify({fighter_id:Number(id)})}).then(function(d){buttons().filter(function(x){return x.getAttribute('data-mmaf-follow')===id;}).forEach(function(x){apply(x,!!d.following);});}).catch(function(err){b.disabled=false;b.title=err.message;var label=b.querySelector('[data-mmaf-follow-label]');if(label)label.textContent='Try again';});});return;}var r=e.target.closest('[data-mmaf-reminder]');if(r){r.disabled=true;sessionInfo().then(function(s){if(!s.loggedIn){window.location=cfg.loginUrl+'?return_to='+encodeURIComponent(window.location.href);return;}api('reminders',{method:'POST',body:JSON.stringify({bout_id:Number(r.getAttribute('data-mmaf-reminder'))})}).then(function(){r.textContent='Reminder set';}).catch(function(err){r.title=err.message;if(err instanceof TypeError){r.disabled=false;r.textContent='Try again';}else{r.textContent='Reminder unavailable';}});});return;}var c=e.target.closest('[data-mmaf-reminder-cancel]');if(c){c.disabled=true;api('reminders/'+c.getAttribute('data-mmaf-reminder-cancel'),{method:'DELETE'}).then(function(){c.closest('li').remove();}).catch(function(err){c.disabled=false;c.title=err.message;});}});
  document.addEventListener('submit',function(e){var f=e.target;if(f.matches('[data-mmaf-auth]')){e.preventDefault();submitAuth(f);}if(f.matches('[data-mmaf-settings]')){e.preventDefault();var x=new FormData(f),out=f.querySelector('[data-mmaf-settings-message]');api('settings',{method:'POST',body:JSON.stringify({timezone:x.get('timezone'),enabled:x.get('enabled')==='on'})}).then(function(){out.textContent='Settings saved.';}).catch(function(err){out.textContent=err.message;});}});
  document.addEventListener('DOMContentLoaded',function(){loadState();var timer;new MutationObserver(function(records){var relevant=records.some(function(r){return Array.prototype.some.call(r.addedNodes,function(n){return n.nodeType===1&&(n.matches('[data-mmaf-follow]')||n.querySelector('[data-mmaf-follow]'));});});if(!relevant)return;clearTimeout(timer);timer=setTimeout(loadState,80);}).observe(document.body,{childList:true,subtree:true});});document.addEventListener('mmaf:content-updated',loadState);
})();
