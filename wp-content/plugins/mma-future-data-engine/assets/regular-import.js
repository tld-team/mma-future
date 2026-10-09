/* global MMAFImport, wp */
(() => {
    'use strict';
    const app = document.getElementById('mmaf-import-app');
    if (!app) return;
    const t = text => wp.i18n.__(text, 'mma-future-data-engine');
    const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
    const byId = id => document.getElementById(id);
    let current = null, uploading = false, busy = false, tab = 'new', lastAnnouncement = '', setup = null, explicitJob = null;
    const labels = {uploading:'Upload in progress',queued:'Waiting for worker',running:'In progress',awaiting_approval:'Ready to review',paused:'Paused',needs_attention:'Needs attention',completed:'Completed',completed_with_review:'Completed with review items',rejected:'Package needs correction',discarded:'Discarded'};
    const phases = {upload:'Upload',assemble:'Preparing uploaded file',prepare:'Checking package contents',check_results:'Checking fighters, events and bouts',check_profiles:'Checking fighter profiles',check_review:'Checking review items',ready:'Review proposed changes',preflight:'Confirming the approved plan',backup:'Creating database backup',results:'Importing fighters, events and bouts',profiles:'Updating fighter profiles',review:'Saving review items',stored_career:'Preparing approved career histories',stored_career_verify:'Verifying shared career histories',check_career:'Checking career histories',career:'Importing career histories',stats:'Rebuilding stats',audit:'Verifying results',done:'Import finished'};
    const terminal = job => ['completed','completed_with_review','rejected','discarded'].includes(job.status);
    async function api(path, options = {}) {
        const headers = {'X-WP-Nonce':MMAFImport.nonce, ...(options.headers || {})};
        if (options.body && !(options.body instanceof Blob)) { headers['Content-Type']='application/json'; options.body=JSON.stringify(options.body); }
        const response = await fetch(MMAFImport.api + path, {...options, headers, credentials:'same-origin'});
        let data; try { data=await response.json(); } catch (_) { throw new Error(t('The server returned an unexpected response. Your saved progress is safe.')); }
        if (!response.ok) throw new Error(response.status === 401 || response.status === 403 ? t('Your admin session expired. Sign in again and return to this import.') : (data.message || t('The request failed.')));
        return data;
    }
    function message(text, error=true) {const el=byId('mmaf-message');el.hidden=!text;el.className='mmaf-note '+(error?'error':'');el.textContent=text;}
    function showTab(name) {tab=name;for(const key of ['new','history','setup']){byId('mmaf-panel-'+key).hidden=key!==name;app.querySelector('[data-tab="'+key+'"]').classList.toggle('nav-tab-active',key===name);}if(name==='setup') loadSetup();}
    app.querySelectorAll('[data-tab]').forEach(link=>link.addEventListener('click',event=>{event.preventDefault();showTab(link.dataset.tab);}));
    function countsTable(counts) {
        const rows=['fighter','event','bout','profile',...['stored_career_profile','career_profile','career_bout'].filter(key=>counts?.[key])];
        return '<div class="mmaf-table-scroll"><table><thead><tr><th>'+t('Data')+'</th>'+['New','Updated','Unchanged','Review','Excluded'].map(x=>'<th>'+t(x)+'</th>').join('')+'</tr></thead><tbody>'+rows.map(key=>'<tr><td>'+escape(t({fighter:'Fighters',event:'Events',bout:'Bouts',profile:'Stored profiles',stored_career_profile:'Approved career histories',career_profile:'Career profiles',career_bout:'Career history fights'}[key]))+'</td>'+['created','updated','unchanged','review','excluded'].map(cat=>'<td>'+Number(counts?.[key]?.[cat]||0).toLocaleString()+'</td>').join('')+'</tr>').join('')+'</tbody></table></div>';
    }
    function render(job) {
        current=job;
        byId('mmaf-upload-card').hidden=Boolean(job && !terminal(job) && job.status!=='uploading');
        if(!job){byId('mmaf-job').innerHTML='';return;}
        const focus=document.activeElement?.id;const detailsOpen=byId('mmaf-technical')?.open;
        const step=job.status==='discarded'?0:(terminal(job)&&job.phase==='done'?3:(job.writes_started||['preflight','backup'].includes(job.phase)?2:(job.status==='uploading'?0:1)));
        app.querySelectorAll('[data-step]').forEach(el=>{const n=Number(el.dataset.step);el.classList.toggle('current',n===step);el.classList.toggle('done',n<step);if(n===step)el.setAttribute('aria-current','step');else el.removeAttribute('aria-current');});
        const needs=['needs_attention','rejected'].includes(job.status),done=job.phase==='done',ready=job.status==='awaiting_approval';
        const total=job.progress.total,processed=step<2?job.progress.checked:job.progress.completed;
        const percent=total?Math.min(100,Math.floor(processed/total*100)):0;
        const heading=job.status==='discarded'?t('Package discarded'):t(phases[job.phase]||job.phase);
        let html='<div class="mmaf-card"><div class="mmaf-job-top"><div><h2>'+escape(heading)+'</h2><div class="mmaf-meta">'+escape(job.filename)+' · '+t('Import')+' #'+job.id+'</div></div><span class="mmaf-badge '+(needs?'error':done?'good':'warning')+'">'+escape(t(labels[job.status]||job.status))+'</span></div>';
        if(job.package.date_from)html+='<p>'+t('Event dates')+': '+escape(job.package.date_from)+' — '+escape(job.package.date_to)+'</p>';
        if(job.notice)html+='<div class="mmaf-note">'+escape(job.notice)+'</div>';
        if(job.error)html+='<div class="mmaf-note '+(needs?'error':'warning')+'"><strong>'+escape(job.error.message)+'</strong>'+(job.retry_at?'<br>'+t('Automatic retry scheduled')+': '+escape(job.retry_at)+' UTC':'')+'</div>';
        if(job.status==='discarded')html+='<div class="mmaf-note">'+t('This package was discarded. Choose another ZIP above when you are ready.')+'</div>';
        if(!ready&&!done&&job.status!=='uploading'&&!needs&&!terminal(job)){html+='<p>'+t('You can close this page. Processing continues on the server.')+'</p>';if(total){html+='<label for="mmaf-job-progress">'+t('Batches')+': '+processed+' / '+total+'</label><progress id="mmaf-job-progress" max="100" value="'+percent+'"></progress>';}html+='<div class="mmaf-meta">'+t('Last activity')+': '+escape(job.last_activity||t('Waiting for the next worker check'))+'</div>';}
        if(job.status==='uploading')html+='<div class="mmaf-note">'+t('Select the same ZIP above to resume the upload, or discard it to choose a different package.')+'</div>';
        const counts=ready?job.plan:job.actual;
        if(ready||done||job.writes_started){
            const sum=key=>['fighter','event','bout','profile','stored_career_profile','career_profile','career_bout'].reduce((n,k)=>n+Number(counts?.[k]?.[key]||0),0);
            html+='<div class="mmaf-metrics">'+[['created','New records'],['updated','Updated records'],['unchanged','Unchanged'],['review','Need review']].map(([key,label])=>'<div class="mmaf-metric"><strong>'+sum(key).toLocaleString()+'</strong><span>'+t(label)+'</span></div>').join('')+'</div>'+countsTable(counts);
        }
        if(ready)html+='<div class="mmaf-note">'+t('Starting creates a private database backup, stores source profiles, imports safe changes and approved careers, rebuilds stats and verifies the result. A ranking draft is then queued for separate activation. Items requiring review are kept for your attention.')+'</div>';
        if(job.backup||job.stats||done){html+='<ul class="mmaf-checklist"><li>'+t('Database backup')+': <strong>'+escape(job.backup?t('Created')+' · '+job.backup.created_at:t('Pending'))+'</strong></li><li>'+t('Stats rebuild')+': <strong>'+escape(job.stats?t('Completed'):t('Pending'))+'</strong></li><li>'+t('Final verification')+': <strong>'+escape(job.integrity?t('Completed'):t('Pending'))+'</strong></li></ul>';}
        html+='<div class="mmaf-actions">';
        if(ready)html+='<button type="button" id="mmaf-approve" class="button button-primary" data-operation="approve">'+t('Start import')+'</button>';
        if(['queued','running'].includes(job.status))html+='<button type="button" id="mmaf-pause" class="button" data-operation="pause" '+(job.pause_requested?'disabled':'')+'>'+t(job.pause_requested?'Pausing after this batch…':'Pause')+'</button>';
        if(job.status==='paused'||job.status==='needs_attention')html+='<button type="button" id="mmaf-resume" class="button button-primary" data-operation="resume">'+t(job.status==='needs_attention'&&!job.error?.retryable?'Retry after fixing':'Resume import')+'</button>';
        if(!job.writes_started&&['uploading','awaiting_approval','rejected'].includes(job.status))html+='<button type="button" id="mmaf-discard" class="button" data-operation="discard">'+t('Discard package')+'</button>';
        if(job.writes_started||done)html+='<a class="button" href="'+escape(job.review_url)+'">'+t('Open review items')+'</a>';
        if(job.backup&&!job.artifact_expired)html+='<a class="button" href="'+escape(job.backup_url)+'">'+t('Download backup')+'</a>';
        if(done&&!job.artifact_expired)html+='<a class="button" href="'+escape(job.report_url)+'">'+t('Download report')+'</a>';
        if(done)html+='<button type="button" id="mmaf-new" class="button" data-new>'+t('New import')+'</button>';
        html+='</div><details id="mmaf-technical" '+(detailsOpen?'open':'')+'><summary>'+t('Technical details')+'</summary><pre>'+escape(JSON.stringify({job_id:job.id,status:job.status,phase:job.phase,bundle_hash:job.bundle_hash,attempts:job.attempts,last_activity:job.last_activity,progress:job.progress,peak_memory_mb:job.peak_memory_mb,error:job.error},null,2))+'</pre></details></div>';
        byId('mmaf-job').innerHTML=html;
        byId('mmaf-job').querySelectorAll('[data-operation]').forEach(button=>button.addEventListener('click',()=>action(button.dataset.operation)));
        byId('mmaf-new')?.addEventListener('click',()=>{explicitJob=null;current=null;byId('mmaf-job').innerHTML='';byId('mmaf-upload-card').hidden=false;byId('mmaf-file').focus();});
        if(focus&&byId(focus))byId(focus).focus({preventScroll:true});
        const announce=job.status+'|'+job.phase;if(announce!==lastAnnouncement){byId('mmaf-announcer').textContent=t(labels[job.status]||job.status)+'. '+t(phases[job.phase]||job.phase);lastAnnouncement=announce;}
    }
    async function action(operation){if(busy)return;busy=true;message('');const button=byId('mmaf-'+operation);if(button)button.disabled=true;try{render(await api('import-jobs/'+current.id+'/'+operation,{method:'POST',body:{approval_token:current.approval_token}}));await refresh();}catch(e){message(e.message);}finally{busy=false;if(button?.isConnected)button.disabled=false;}}
    async function upload(file){
        if(!file||uploading)return;if(!file.name.toLowerCase().endsWith('.zip')||file.size>134217728){message(t('Choose a ZIP file no larger than 128 MiB.'));return;}
        uploading=true;message('');byId('mmaf-file').disabled=true;byId('mmaf-upload-progress').hidden=false;
        try{const session=await api('import-uploads',{method:'POST',body:{filename:file.name,bytes:file.size}});explicitJob=session.id;
            for(let offset=0,index=0;offset<file.size;offset+=session.chunk_size,index++){
                const blob=file.slice(offset,offset+session.chunk_size);let error;
                for(let attempt=0;attempt<3;attempt++){try{await api('import-uploads/'+session.id+'/chunks/'+index,{method:'PUT',body:blob,headers:{'Content-Type':'application/octet-stream'}});error=null;break;}catch(e){error=e;if(attempt<2)await new Promise(resolve=>setTimeout(resolve,1000*(attempt+1)));}}
                if(error)throw error;const percent=Math.round(Math.min(file.size,offset+session.chunk_size)/file.size*100);byId('mmaf-upload-bar').value=percent;byId('mmaf-upload-label').textContent=t('Uploading')+' '+percent+'%';
            }
            render(await api('import-uploads/'+session.id+'/finish',{method:'POST'}));byId('mmaf-file').value='';
        }catch(e){message(e.message);}finally{uploading=false;byId('mmaf-file').disabled=false;byId('mmaf-upload-progress').hidden=true;refresh();}
    }
    byId('mmaf-file').addEventListener('change',event=>upload(event.target.files[0]));
    const drop=byId('mmaf-dropzone');for(const name of ['dragover','dragenter'])drop.addEventListener(name,event=>{event.preventDefault();drop.classList.add('drag');});for(const name of ['dragleave','drop'])drop.addEventListener(name,event=>{event.preventDefault();drop.classList.remove('drag');});drop.addEventListener('drop',event=>upload(event.dataTransfer.files[0]));
    async function loadSetup(){try{setup=await api('import-setup');byId('mmaf-worker-state').textContent=setup.ready?t('Background worker ready'):t('Setup needs attention');byId('mmaf-setup').innerHTML='<span class="mmaf-badge '+(setup.ready?'good':'warning')+'">'+t(setup.ready?'Ready':'Needs setup')+'</span><ul class="mmaf-checklist">'+setup.problems.map(p=>'<li>'+escape(p)+'</li>').join('')+'</ul><p>'+t('Worker last seen')+': '+escape(setup.worker_seen||t('Not yet'))+'</p><p>'+t('Private storage')+': <code>'+escape(setup.storage||'—')+'</code></p><p>'+t('Ask the administrator to run this script every minute with the WordPress root, PHP CLI path and WP-CLI path as its three arguments.')+'</p><pre>'+escape(setup.runner_script)+'\n'+escape(setup.wordpress_root)+'</pre><p>'+t('Backups contain the database. Continue using ManageWP for site files. Completed artifacts are kept for 30 days and at least the last three imports; unfinished imports are retained.')+'</p>';}catch(e){message(e.message);}}
    async function refresh(){if(uploading||busy)return;try{const data=await api('import-jobs');if(explicitJob){render(await api('import-jobs/'+explicitJob));}else if(data.active){render(data.active);}byId('mmaf-history').innerHTML=data.history.length?'<div class="mmaf-table-scroll"><table><thead><tr>'+['Import','Package','Started','Status',''].map(x=>'<th>'+t(x)+'</th>').join('')+'</tr></thead><tbody>'+data.history.map(job=>'<tr><td>#'+job.id+'</td><td>'+escape(job.filename)+'</td><td>'+escape(job.created_at)+' UTC</td><td>'+escape(t(labels[job.status]||job.status))+'</td><td><button class="button" data-open="'+job.id+'">'+t('View')+'</button></td></tr>').join('')+'</tbody></table></div>':'<p>'+t('Your imports will appear here.')+'</p>';byId('mmaf-history').querySelectorAll('[data-open]').forEach(button=>button.addEventListener('click',async()=>{explicitJob=Number(button.dataset.open);showTab('new');render(await api('import-jobs/'+explicitJob));}));}catch(e){message(e.message);}}
    loadSetup();refresh();setInterval(refresh,3000);setInterval(loadSetup,60000);
})();
