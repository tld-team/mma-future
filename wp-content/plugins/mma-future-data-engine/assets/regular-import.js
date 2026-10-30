/* global MMAFImport, wp */
(() => {
    'use strict';
    const app = document.getElementById('mmaf-import-app');
    if (!app) return;
    const t = text => wp.i18n.__(text, 'mma-future-data-engine');
    const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
    const byId = id => document.getElementById(id);
    let current = null, uploading = false, busy = false, tab = 'new', lastAnnouncement = '', setup = null, explicitJob = null, capacityDirty = false;
    const labels = {uploading:'Upload in progress',queued:'Waiting for worker',running:'In progress',awaiting_approval:'Ready to review',paused:'Paused',needs_attention:'Needs attention',completed:'Completed',completed_with_review:'Completed with review items',rejected:'Package needs correction',discarded:'Discarded'};
    const phases = {upload:'Upload',assemble:'Preparing uploaded file',prepare:'Checking package contents',check_results:'Checking fighters, events and bouts',check_profiles:'Checking fighter profiles',check_review:'Checking review items',ready:'Review proposed changes',preflight:'Confirming the approved plan',backup:'Creating database backup',results:'Importing fighters, events and bouts',profiles:'Updating fighter profiles',review:'Saving review items',stored_career:'Preparing approved career histories',stored_career_verify:'Verifying shared career histories',check_career:'Checking career histories',career:'Importing career histories',stats:'Rebuilding stats',audit:'Verifying results',done:'Import finished'};
    const terminal = job => ['completed','completed_with_review','rejected','discarded'].includes(job.status);
    async function api(path, options = {}) {
        const headers = {'X-WP-Nonce':MMAFImport.nonce, ...(options.headers || {})};
        if (options.body && !(options.body instanceof Blob)) { headers['Content-Type']='application/json'; options.body=JSON.stringify(options.body); }
        const response = await fetch(MMAFImport.api + path, {...options, headers, credentials:'same-origin'});
        let data; try { data=await response.json(); } catch (_) { throw new Error(t('The server returned an unexpected response. Your saved progress is safe.')); }
        if (!response.ok) {const error=new Error(response.status === 401 || response.status === 403 ? t('Your admin session expired. Sign in again and return to this import.') : (data.message || t('The request failed.')));error.code=data.code;error.status=response.status;throw error;}
        return data;
    }
    function message(text, error=true) {const el=byId('mmaf-message');el.hidden=!text;el.className='mmaf-note '+(error?'error':'');el.textContent=text;}
    function showTab(name) {tab=name;for(const key of ['new','history','setup']){byId('mmaf-panel-'+key).hidden=key!==name;app.querySelector('[data-tab="'+key+'"]').classList.toggle('nav-tab-active',key===name);}if(name==='setup') loadSetup();}
    app.querySelectorAll('[data-tab]').forEach(link=>link.addEventListener('click',event=>{event.preventDefault();showTab(link.dataset.tab);}));
    function countsTable(counts) {
        const rows=['fighter','event','bout','profile',...['stored_career_profile','career_profile','career_bout'].filter(key=>counts?.[key])];
        return '<div class="mmaf-table-scroll"><table><thead><tr><th>'+t('Data')+'</th>'+['New','Updated','Unchanged','Review','Excluded'].map(x=>'<th>'+t(x)+'</th>').join('')+'</tr></thead><tbody>'+rows.map(key=>'<tr><td>'+escape(t({fighter:'Fighters',event:'Events',bout:'Bouts',profile:'Stored profiles',stored_career_profile:'Approved career histories',career_profile:'Career profiles',career_bout:'Career history fights'}[key]))+'</td>'+['created','updated','unchanged','review','excluded'].map(cat=>'<td>'+Number(counts?.[key]?.[cat]||0).toLocaleString()+'</td>').join('')+'</tr>').join('')+'</tbody></table></div>';
    }
    function ufcPreviewHtml(job) {
        const runs=job.source_runs||{}, snapshot=job.ufc_snapshot||{};
        if(!runs.events&&!runs.profiles&&!runs.ufc&&!snapshot.warning)return '';
        let html='<div class="mmaf-note"><strong>'+t('Import destination and source runs')+'</strong><p>'+t('Site')+': '+escape(job.site_url||'—')+' · '+t('Database')+': '+escape(job.database||'—')+'</p><p>'+t('Events')+': '+escape(runs.events||'—')+' · '+t('Profiles')+': '+escape(runs.profiles||'—')+' · UFC: '+escape(runs.ufc||'—')+'</p>';
        if(snapshot.scraped_at)html+='<p>'+t('UFC snapshot fetched')+': '+escape(snapshot.scraped_at)+'</p>';
        if(snapshot.warning)html+='<p><strong>'+t('UFC warning')+':</strong> '+escape(snapshot.warning)+'</p>';
        html+='</div>';
        const preview=job.ufc_preview;
        if(!preview)return html;
        html+='<div class="mmaf-note"><strong>'+t('UFC comparison preview')+'</strong><p>'+[['new','New candidates'],['updated','Updated candidates'],['conflicts','Identity conflicts'],['preserved_rejections','Preserved rejections'],['already_confirmed','Already confirmed'],['unmatched','Unmatched source entries']].map(([key,label])=>t(label)+': '+Number(preview[key]||0).toLocaleString()).join(' · ')+'</p><p>'+t('Every match needs a manual UFC decision. This import does not automatically exclude fighters.')+'</p>';
        const sample=preview.sample_candidates||[];
        if(sample.length)html+='<details><summary>'+t('Sample UFC candidates')+'</summary><ul>'+sample.map(row=>'<li>'+escape(row.source_name||row.source_key)+' → #'+escape(row.fighter_id)+' · '+escape(row.reason||'—')+(row.conflicts?.length?' · '+escape(row.conflicts.map(item=>item.type).join(', ')):'')+'</li>').join('')+'</ul></details>';
        return html+'</div>';
    }
    function readinessHtml(readiness) {
        if(!readiness)return '';
        const coverage=readiness.event_coverage||{};
        const coverageHtml="<div class=\"mmaf-note\"><strong>Event coverage</strong><p>"+(coverage.complete===true?"Confirmed complete":"Partial or legacy coverage; review required")+"</p><p>"+Object.entries(coverage.counts||{}).map(([status,count])=>escape(status)+": "+Number(count)).join(" · ")+"</p></div>";
        const deltaProfiles=readiness.full_snapshot_readiness?readiness.profiles_total:null;
        readiness=readiness.full_snapshot_readiness||readiness;
        const p=readiness.profiles||{};
        const actions=readiness.enrichment_actions||{};
        const batch=readiness.last_batch||{};
        const batchHtml=typeof batch.batch_total==='number'?'<p>'+t('Last profile batch')+': '+t('Attempted')+' '+batch.batch_total+' · '+t('Succeeded')+' '+Number(batch.batch_success||0)+' · '+t('Failed')+' '+Number(batch.batch_failed||0)+'</p>':'';
        const groups=Object.entries(readiness.enrichment_reasons||{}).map(([reason,count])=>'<li>'+escape(t(reason.replaceAll('_',' ')))+': '+Number(count).toLocaleString()+'</li>').join('');
        return coverageHtml+'<div class="mmaf-note"><strong>'+t('Source evidence checked before export')+'</strong>'+(deltaProfiles!==null?'<p>'+t('Changed profiles in this delta')+': '+Number(deltaProfiles).toLocaleString()+' · '+t('Evidence summary covers the full source snapshot.')+'</p>':'')+'<p>'+t('Profile facts candidates')+': '+Number(p.facts_ready||0).toLocaleString()+' · '+t('Full detailed career history candidates')+': '+Number(p.career_ready_candidates||0).toLocaleString()+' · '+t('Full detailed career history gaps')+': '+Number(p.careers_needing_enrichment||0).toLocaleString()+'</p>'+('period_ready_candidates' in p?'<p>'+t('Scoring period from 9 May 2026')+': '+Number(p.period_ready_candidates||0).toLocaleString()+' '+t('ready source candidates')+' · '+Number(p.period_needing_enrichment||0).toLocaleString()+' '+t('need refresh or review')+' · '+Number(p.history_outside_period_gaps||0).toLocaleString()+' '+t('older history gaps (separate; do not block a verified period)')+'</p>':'')+'<p>'+t('Targeted source refresh')+': '+Number(actions.refresh_source_evidence||0).toLocaleString()+' · '+t('Human conflict review')+': '+Number(actions.review_conflict||0).toLocaleString()+' · '+t('Recorded failed latest attempts')+': '+Number(p.last_attempt_failed||0).toLocaleString()+' · '+t('Latest attempt not recorded')+': '+Number(p.last_attempt_unknown||0).toLocaleString()+'</p>'+batchHtml+'<p>'+t('Source candidates are verified against this site during import. Missing evidence stays available for targeted enrichment.')+'</p>'+(groups?'<details><summary>'+t('Missing evidence groups')+'</summary><ul>'+groups+'</ul></details>':'')+'</div>';
    }
    function updatePreparation(status) {
        const el=byId('mmaf-preparation-notice');if(!el||!status)return;
        const counts=Object.fromEntries((status.queue||[]).map(r=>[r.status,Number(r.total)]));
        const state=status.calculation||{};
        let text=t('Stored profile preparation')+': '+t('Pending')+' '+(counts.pending||0)+', '+t('Completed')+' '+(counts.completed||0)+', '+t('Need review')+' '+(counts.review||0)+', '+t('Failed')+' '+(counts.failed||0)+'. ';
        if(state.error)text+=t('Calculation needs attention')+': '+state.error;
        else if(state.pending)text+=t('One inactive ranking draft is queued.');
        else if(state.draft?.ranking_run_id)text+=t('Prepared inactive draft')+' #'+state.draft.ranking_run_id+'.';
        el.querySelector('p').textContent=text+' '+t('Ranking activation remains a separate action.');
    }
    async function startUpload(file) {
        for(let attempt=0;;attempt++){
            try{return await api('import-uploads',{method:'POST',body:{filename:file.name,bytes:file.size}});}
            catch(error){if(error.code!=='busy'||attempt>=3)throw error;await new Promise(resolve=>setTimeout(resolve,1000*(attempt+1)));}
        }
    }
    function render(job) {
        current=job;
        updatePreparation(job?.preparation);
        byId('mmaf-upload-card').hidden=Boolean(job && !terminal(job) && job.status!=='uploading');
        if(!job){byId('mmaf-job').innerHTML='';return;}
        const focus=document.activeElement?.id;const detailsOpen=byId('mmaf-technical')?.open;
        const step=job.status==='discarded'?0:(terminal(job)&&job.phase==='done'?3:(job.writes_started||['preflight','backup'].includes(job.phase)?2:(job.status==='uploading'?0:1)));
        app.querySelectorAll('[data-step]').forEach(el=>{const n=Number(el.dataset.step);el.classList.toggle('current',n===step);el.classList.toggle('done',n<step);if(n===step)el.setAttribute('aria-current','step');else el.removeAttribute('aria-current');});
        const needs=['needs_attention','rejected'].includes(job.status),done=job.phase==='done',ready=job.status==='awaiting_approval';
        const phaseProgress=job.phase_progress||{};
        const total=phaseProgress.total??job.progress.total,processed=phaseProgress.processed??(step<2?job.progress.checked:job.progress.completed);
        const percent=total?Math.min(100,Math.floor(processed/total*100)):0;
        const heading=job.status==='discarded'?t('Package discarded'):t(phases[job.phase]||job.phase);
        let html='<div class="mmaf-card"><div class="mmaf-job-top"><div><h2>'+escape(heading)+'</h2><div class="mmaf-meta">'+escape(job.filename)+' · '+t('Import')+' #'+job.id+'</div></div><span class="mmaf-badge '+(needs?'error':done?'good':'warning')+'">'+escape(t(labels[job.status]||job.status))+'</span></div>';
        if(job.package.date_from)html+='<p>'+t('Event dates')+': '+escape(job.package.date_from)+' — '+escape(job.package.date_to)+'</p>';
        html+=ufcPreviewHtml(job);
        if(job.notice)html+='<div class="mmaf-note">'+escape(job.notice)+'</div>';
        if(job.error)html+='<div class="mmaf-note '+(needs?'error':'warning')+'"><strong>'+escape(job.error.message)+'</strong>'+(job.retry_at?'<br>'+t('Automatic retry scheduled')+': '+escape(job.retry_at)+' UTC':'')+'</div>';
        if(job.status==='discarded')html+='<div class="mmaf-note">'+t('This package was discarded. Choose another ZIP above when you are ready.')+'</div>';
        if(!ready&&!done&&job.status!=='uploading'&&!needs&&!terminal(job)){html+='<p>'+t('You can close this page. Processing continues on the server.')+'</p>';if(total){html+='<label for="mmaf-job-progress">'+t(phaseProgress.unit||'Batches')+': '+processed+' / '+total+'</label><progress id="mmaf-job-progress" max="100" value="'+percent+'"></progress>';}html+='<div class="mmaf-meta">'+t('Last activity')+': '+escape(job.last_activity||t('Waiting for the next worker check'))+'</div>';}
        if(job.status==='uploading')html+='<div class="mmaf-note">'+t('Select the same ZIP above to resume the upload, or discard it to choose a different package.')+'</div>';
        const counts=ready?job.plan:job.actual;
        if(ready||done||job.writes_started){
            const sum=key=>['fighter','event','bout','profile','stored_career_profile','career_profile','career_bout'].reduce((n,k)=>n+Number(counts?.[k]?.[key]||0),0);
            html+='<div class="mmaf-metrics">'+[['created','New records'],['updated','Updated records'],['unchanged','Unchanged'],['review','Need review']].map(([key,label])=>'<div class="mmaf-metric"><strong>'+sum(key).toLocaleString()+'</strong><span>'+t(label)+'</span></div>').join('')+'</div>'+countsTable(counts);
        }
        html+=readinessHtml(job.readiness);
        if(Object.keys(job.profile_changes||{}).length){const c=job.profile_changes;html+='<p>'+t('New stored snapshots')+': '+Number(c.snapshot_new||0).toLocaleString()+' · '+t('Changed stored snapshots')+': '+Number(c.snapshot_changed||0).toLocaleString()+' · '+t('Profiles with applied facts')+': '+Number(c.facts_profiles||0).toLocaleString()+' · '+t('Applied fact fields')+': '+Number(c.facts_fields||0).toLocaleString()+'</p>';}
        if(ready)html+='<div class="mmaf-note">'+t('Starting creates a private database backup, stores source profiles, imports safe changes and approved careers, rebuilds stats and verifies the result. A ranking draft is then queued for separate activation. Items requiring review are kept for your attention.')+'</div>';
        if(job.backup||job.stats||done){html+='<ul class="mmaf-checklist"><li>'+t('Database backup')+': <strong>'+escape(job.backup?t('Created')+' · '+job.backup.created_at:t('Pending'))+'</strong></li><li>'+t('Stats rebuild')+': <strong>'+escape(job.stats?(job.stats.reused?t('Already current'):t('Completed')):t('Pending'))+'</strong></li><li>'+t('Final verification')+': <strong>'+escape(job.integrity?t('Completed'):t('Pending'))+'</strong></li></ul>';}
        html+='<div class="mmaf-actions">';
        if(ready)html+='<button type="button" id="mmaf-approve" class="button button-primary" data-operation="approve">'+t('Start import')+'</button>';
        if(['queued','running'].includes(job.status))html+='<button type="button" id="mmaf-pause" class="button" data-operation="pause" '+(job.pause_requested?'disabled':'')+'>'+t(job.pause_requested?'Pausing after this batch…':'Pause')+'</button>';
        if(job.status==='paused'||job.status==='needs_attention')html+='<button type="button" id="mmaf-resume" class="button button-primary" data-operation="resume">'+t(job.status==='needs_attention'&&!job.error?.retryable?'Retry after fixing':'Resume import')+'</button>';
        if(!job.writes_started&&['uploading','awaiting_approval','rejected'].includes(job.status))html+='<button type="button" id="mmaf-discard" class="button" data-operation="discard">'+t('Discard package')+'</button>';
        if(job.writes_started||done)html+='<a class="button" href="'+escape(job.review_url)+'">'+t('Open review items')+'</a>';
        if(job.backup&&!job.artifact_expired)html+='<a class="button" href="'+escape(job.backup_url)+'">'+t('Download backup')+'</a>';
        if(done&&!job.artifact_expired)html+='<a class="button" href="'+escape(job.report_url)+'">'+t('Download report')+'</a>';
        if(job.readiness&&!job.artifact_expired)html+='<a class="button" href="'+escape(job.source_enrichment_url)+'">'+t('Download source enrichment queue')+'</a>';
        if(done)html+='<button type="button" id="mmaf-new" class="button" data-new>'+t('New import')+'</button>';
        html+='</div><details id="mmaf-technical" '+(detailsOpen?'open':'')+'><summary>'+t('Technical details')+'</summary><pre>'+escape(JSON.stringify({job_id:job.id,status:job.status,phase:job.phase,bundle_hash:job.bundle_hash,attempts:job.attempts,last_activity:job.last_activity,progress:job.progress,peak_memory_mb:job.peak_memory_mb,performance:job.performance,error:job.error},null,2))+'</pre></details></div>';
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
        try{const session=await startUpload(file);explicitJob=session.id;
            for(let offset=0,index=0;offset<file.size;offset+=session.chunk_size,index++){
                const blob=file.slice(offset,offset+session.chunk_size);let error;
                for(let attempt=0;attempt<3;attempt++){try{await api('import-uploads/'+session.id+'/chunks/'+index,{method:'PUT',body:blob,headers:{'Content-Type':'application/octet-stream'}});error=null;break;}catch(e){error=e;if(attempt<2)await new Promise(resolve=>setTimeout(resolve,1000*(attempt+1)));}}
                if(error)throw error;const percent=Math.round(Math.min(file.size,offset+session.chunk_size)/file.size*100);byId('mmaf-upload-bar').value=percent;byId('mmaf-upload-label').textContent=t('Uploading')+' '+percent+'%';
            }
            render(await api('import-uploads/'+session.id+'/finish',{method:'POST'}));byId('mmaf-file').value='';
        }catch(e){message(e.message);}finally{uploading=false;byId('mmaf-file').value='';byId('mmaf-file').disabled=false;byId('mmaf-upload-progress').hidden=true;refresh();}
    }
    byId('mmaf-file').addEventListener('change',event=>upload(event.target.files[0]));
    const drop=byId('mmaf-dropzone');for(const name of ['dragover','dragenter'])drop.addEventListener(name,event=>{event.preventDefault();drop.classList.add('drag');});for(const name of ['dragleave','drop'])drop.addEventListener(name,event=>{event.preventDefault();drop.classList.remove('drag');});drop.addEventListener('drop',event=>upload(event.dataTransfer.files[0]));
    function capacityForm() {
        return '<details><summary>'+t('Update hosting account capacity')+'</summary><p>'+t('Copy current account usage and limits from cPanel. Filesystem free space alone cannot confirm the account quota.')+'</p><form id="mmaf-capacity-form">'+
            ['bytes','inodes'].map(kind=>'<fieldset><legend>'+t(kind==='bytes'?'Disk space (GiB)':'Files and directories (inodes)')+'</legend><label>'+t('Used')+' <input name="used_'+kind+'" type="number" min="0" step="'+(kind==='bytes'?'0.01':'1')+'" required></label> <label>'+t('Limit')+' <input name="limit_'+kind+'" type="number" min="'+(kind==='bytes'?'0.01':'1')+'" step="'+(kind==='bytes'?'0.01':'1')+'"></label> <label><input name="'+kind+'_unlimited" type="checkbox"> '+t('Account reports unlimited')+'</label></fieldset>').join('')+'<button class="button" type="submit">'+t('Save observed capacity')+'</button></form></details>';
    }
    function wireCapacityForm() {
        const form=byId('mmaf-capacity-form');if(!form)return;
        form.addEventListener('input',()=>{capacityDirty=true;});
        form.addEventListener('submit',async event=>{event.preventDefault();const data={};
            for(const kind of ['bytes','inodes']){data[kind+'_unlimited']=form.elements[kind+'_unlimited'].checked;for(const key of ['used','limit']){const raw=form.elements[key+'_'+kind].value;data[key+'_'+kind]=raw===''?null:Math.ceil(Number(raw)*(kind==='bytes'?1073741824:1));}}
            const button=form.querySelector('button');button.disabled=true;
            try{await api('import-capacity',{method:'POST',body:data});capacityDirty=false;button.focus();await loadSetup(true);message(t('Account capacity observation saved.'),false);}catch(e){message(e.message);}finally{if(button.isConnected)button.disabled=false;}
        });
    }
    async function loadSetup(force=false){
        if(!force&&(capacityDirty||byId('mmaf-capacity-form')?.contains(document.activeElement)))return;
        try{
            setup=await api('import-setup');
            byId('mmaf-worker-state').textContent=setup.ready?t('Background worker ready'):t('Setup needs attention');
            const p=setup.preflight||{},r=p.restore_verification||{};
            const worker=setup.managed_supervisor?'<p>'+t('The existing server supervisor runs this site. Worker sessions stop at a time or memory limit and resume their saved progress.')+'</p>':'<p>'+t('Ask the administrator to run this script every minute with the WordPress root, PHP CLI path and WP-CLI path as its three arguments.')+'</p><pre>'+escape(setup.runner_script)+'\n'+escape(setup.wordpress_root)+'</pre>';
            byId('mmaf-setup').innerHTML='<span class="mmaf-badge '+(setup.ready?'good':'warning')+'">'+t(setup.ready?'Ready':'Needs attention')+'</span><ul class="mmaf-checklist">'+setup.problems.map(problem=>'<li>'+escape(problem)+'</li>').join('')+'</ul><p>'+t('Worker last seen')+': '+escape(setup.worker_seen||t('Not yet'))+'</p><p>'+t('Estimated backup working space')+': '+(Number(p.backup_estimated_bytes||0)/1073741824).toFixed(2)+' GiB · '+t('Media files')+': '+Number(p.media_files||0).toLocaleString()+'</p><p>'+t('Last full restore verification')+': '+escape(r.ok?r.checked_at:t('Not recorded'))+(p.restore_verification_due?' · '+t('Full restore verification is due under the administrator policy.'):'')+'</p><p>'+t('Private storage')+': <code>'+escape(setup.storage||'—')+'</code></p>'+worker+capacityForm()+'<p>'+t('Backups include the database and locally managed media. Completed import artifacts are retained for 30 days and at least the last three imports; unfinished imports are retained.')+'</p>';
            wireCapacityForm();
        }catch(error){message(error.message);}
    }
    async function refresh(){if(uploading||busy)return;try{const data=await api('import-jobs');if(explicitJob){render(await api('import-jobs/'+explicitJob));}else if(data.active){render(data.active);}byId('mmaf-history').innerHTML=data.history.length?'<div class="mmaf-table-scroll"><table><thead><tr>'+['Import','Package','Started','Status',''].map(x=>'<th>'+t(x)+'</th>').join('')+'</tr></thead><tbody>'+data.history.map(job=>'<tr><td>#'+job.id+'</td><td>'+escape(job.filename)+'</td><td>'+escape(job.created_at)+' UTC</td><td>'+escape(t(labels[job.status]||job.status))+'</td><td><button class="button" data-open="'+job.id+'">'+t('View')+'</button></td></tr>').join('')+'</tbody></table></div>':'<p>'+t('Your imports will appear here.')+'</p>';byId('mmaf-history').querySelectorAll('[data-open]').forEach(button=>button.addEventListener('click',async()=>{explicitJob=Number(button.dataset.open);showTab('new');render(await api('import-jobs/'+explicitJob));}));}catch(e){message(e.message);}}
    loadSetup();refresh();setInterval(refresh,3000);setInterval(loadSetup,60000);
})();
