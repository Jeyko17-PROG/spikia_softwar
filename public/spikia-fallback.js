(function () {
  if (window.__SPIKIA_MASTER__ || window.__SPIKIA_LISTENER__) return;

  const has = (v) => v !== null && v !== undefined;
  const clean = (t) => String(t || '').replace(/^\uFEFF+/, '').replace(/[\uFEFF\u200B\u200C\u200D]/g, '').trim();
  const repeat = (t) => clean(t).replace(/\s+/g, ' ').replace(/\b(\w+)(?:\s+\1\b)+/gi, '$1');
  const base = (l) => String(l || '').split('-')[0].toLowerCase();
  const label = (l, map) => (map && map[l]) || String(l || '').replace('-', ' ').toUpperCase();

  async function readJSON(res, msg) {
    const text = clean(await res.text());
    const ct = res.headers.get('content-type') || '';
    if (ct.includes('application/json') || text.startsWith('{') || text.startsWith('[')) {
      try { return JSON.parse(text); } catch (e) {
        const s = text.search(/[\[{]/), eidx = Math.max(text.lastIndexOf('}'), text.lastIndexOf(']'));
        if (s !== -1 && eidx !== -1 && eidx > s) return JSON.parse(text.slice(s, eidx + 1));
      }
    }
    throw new Error(msg || text || 'Invalid JSON');
  }

  const ready = (fn) => document.readyState === 'loading'
    ? document.addEventListener('DOMContentLoaded', fn, { once: true })
    : fn();

  function initMaster() {
    const c = window.__SPIKIA_MASTER__;
    if (!c) return;

    const btn = document.getElementById('master-live-btn');
    const box = document.getElementById('transcription-box');
    const timer = document.getElementById('session-timer');
    const langLabel = document.getElementById('selected-language-label');
    const dot = document.getElementById('status-dot');
    const status = document.getElementById('status-text');
    const bg = document.getElementById('btn-bg-active');
    const bars = document.querySelectorAll('.bar');
    const mode = document.getElementById('save-mode');
    const langBtns = document.querySelectorAll('.language-btn');
    const voiceBtns = document.querySelectorAll('.voice-gender-btn');
    if (!btn || !box || !timer) return;

    if ('speechSynthesis' in window) window.speechSynthesis.cancel();
    const targets = Array.isArray(c.targetLanguages) && c.targetLanguages.length
      ? Array.from(new Set(c.targetLanguages.filter(Boolean)))
      : Array.isArray(c.sessionLanguages) && c.sessionLanguages.length
        ? Array.from(new Set(c.sessionLanguages.filter(Boolean)))
        : Array.isArray(c.defaultTargets) && c.defaultTargets.length ? c.defaultTargets : ['en', 'pt', 'it', 'fr'];

    const st = { live:false, start:null, lang:'es-ES', langBase:'es', langName:'Espa\u00f1ol Espa\u00f1a', gender:'male', save: mode ? mode.value : 'resumen', last:'', lastAt:0, cool:0 };
    let rec = null, tm = null, vis = null, liveDraft = null;

    const setLabel = (l) => { if (langLabel) langLabel.textContent = `${label(l, c.languageLabels)} · ${(l || 'es-ES').toUpperCase()}`; };
    const hi = (l) => langBtns.forEach((b) => b.classList.toggle('border-indigo-600', b.getAttribute('data-lang-id') === l));
    const ensureDraft = () => {
      if (!liveDraft) {
        liveDraft = document.createElement('div');
        liveDraft.className = 'p-6 mb-4 bg-indigo-600/10 border border-indigo-400/20 rounded-[2rem]';
        liveDraft.innerHTML = '<span class="text-indigo-300 font-black text-[10px] block mb-2 uppercase tracking-[0.2em]">Escuchando en vivo</span><p class="text-2xl font-light leading-relaxed text-white/80 italic">...</p>';
        box.appendChild(liveDraft);
      }
      return liveDraft;
    };
    const updateDraft = (text) => {
      const draft = ensureDraft();
      draft.querySelector('p').textContent = repeat(text || '...');
      box.scrollTo({ top: box.scrollHeight, behavior: 'smooth' });
    };
    const clearDraft = () => {
      if (liveDraft) {
        liveDraft.remove();
        liveDraft = null;
      }
    };
    const show = (t) => {
      const ph = document.getElementById('placeholder-text'); if (ph) ph.remove();
      const d = document.createElement('div');
      d.className = 'p-6 mb-4 bg-zinc-900/50 border border-white/10 rounded-[2rem]';
      d.innerHTML = `<span class="text-indigo-500 font-black text-[10px] block mb-2 uppercase tracking-[0.2em]">Master</span><p class="text-2xl font-light leading-relaxed text-white">${repeat(t)}</p>`;
      box.appendChild(d); box.scrollTo({ top: box.scrollHeight, behavior: 'smooth' });
    };

    const postRelay = (texto, idioma, variante, tipo, id) => fetch(c.relayUrl, {
      method: 'POST', headers: {'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN': c.csrfToken},
      body: JSON.stringify({ texto, idioma, variante: variante || '', genero: st.gender, tipo: tipo || 'texto', id: id || '' })
    });
    const save = (texto, idioma) => fetch(c.transcripcionUrl, {
      method:'POST', headers:{'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN': c.csrfToken},
      body: JSON.stringify({ sesion_id:c.sesionId, slug:c.slug, texto, idioma, modo: st.save })
    });
    const translate = async (texto, idBase, source, availableAt) => {
      for (const t of targets) {
        if (!t || t === source || base(t) === idBase) continue;
        try {
          const res = await fetch('/traducciones', {
            method:'POST', headers:{'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN': c.csrfToken},
            body: JSON.stringify({ sesion_id:c.sesionId, texto, idioma:t, variante:'' })
          });
          if (!res.ok) continue;
          const payload = await readJSON(res, 'No se pudo leer la traduccion.');
          if (!payload || !payload.traduccion) continue;
          const msgId = crypto.randomUUID();
          await postRelay(payload.traduccion, base(t), t.includes('-') ? t : '', 'traduccion', msgId);
          await save(payload.traduccion, t);
        } catch (e) { console.error('Translation error:', e); }
      }
    };

    function initRec() {
      const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
      if (!SR) return;
      rec = new SR(); rec.continuous = true; rec.interimResults = true; rec.lang = st.lang;
      rec.onresult = (ev) => {
        let finalText = '';
        let interimText = '';
        for (let i = ev.resultIndex; i < ev.results.length; i++) {
          const piece = ev.results[i][0].transcript || '';
          if (ev.results[i].isFinal) {
            finalText += piece;
          } else {
            interimText += piece;
          }
        }

        const liveText = repeat(interimText || finalText);
        if (liveText.length > 1) {
          updateDraft(liveText);
        }

        const txt = repeat(finalText);
        if (!txt || txt.length <= 1 || txt === st.last) return;

        clearDraft();
        const now = Date.now();
        st.last = txt; st.lastAt = now; st.cool = now + 1500;
        const availableAt = now + 1000;
        show(txt);
        const id = crypto.randomUUID();
        postRelay(txt, st.langBase, st.lang, 'original', id);
        save(txt, st.lang);
        translate(txt, st.langBase, st.lang, availableAt);
      };
      rec.onend = () => {
        if (!st.live) return;
        const delay = Date.now() < st.cool ? Math.max(300, st.cool - Date.now()) : (Date.now() - st.lastAt < 350 ? 350 : 0);
        setTimeout(() => { try { rec.start(); } catch (e) {} }, delay);
      };
      rec.onerror = (ev) => { if (ev.error === 'no-speech') return; if (st.live && ev.error !== 'not-allowed') setTimeout(() => { try { rec.start(); } catch (e) {} }, 200); };
    }

      btn.addEventListener('click', () => {
      st.live = !st.live;
      if (st.live) {
        st.start = new Date(); st.last = ''; st.lastAt = 0; setLabel(st.lang);
        if (rec) { rec.lang = st.lang; try { rec.start(); } catch (e) {} }
        tm = setInterval(() => {
          const ms = new Date() - st.start, h = String(Math.floor(ms/3600000)).padStart(2,'0'), m = String(Math.floor(ms%3600000/60000)).padStart(2,'0'), s = String(Math.floor(ms%60000/1000)).padStart(2,'0');
          timer.innerText = `${h}:${m}:${s}`;
        }, 1000);
        if (dot) dot.className = 'relative inline-flex rounded-full h-3 w-3 bg-cyan-400 animate-pulse shadow-[0_0_10px_#22d3ee]';
        if (status) status.innerText = 'LIVE RUNNING';
        if (bg) bg.style.opacity = '1';
        vis = setInterval(() => bars.forEach((b) => b.style.height = `${Math.random()*60 + 20}%`), 150);
      } else {
        if (rec) try { rec.stop(); } catch (e) {}
        clearDraft();
        clearInterval(tm); clearInterval(vis);
        if (dot) dot.className = 'relative inline-flex rounded-full h-3 w-3 bg-zinc-700';
        if (status) status.innerText = 'SYSTEM STANDBY';
        if (bg) bg.style.opacity = '0';
        bars.forEach((b) => b.style.height = '15%');
      }
    });

    langBtns.forEach((b) => b.addEventListener('click', function () {
      const l = this.getAttribute('data-lang-id');
      st.lang = l; st.langBase = this.getAttribute('data-lang-base') || base(l); st.langName = this.getAttribute('data-lang-name') || l;
      hi(l); setLabel(l);
      if (rec && st.live) { try { rec.stop(); } catch (e) {} setTimeout(() => { try { rec.lang = this.getAttribute('data-speech-lang') || l; rec.start(); } catch (e) {} }, 200); }
    }));
    voiceBtns.forEach((b) => b.addEventListener('click', function () {
      st.gender = this.getAttribute('data-gender') || 'male';
      voiceBtns.forEach((x) => x.classList.remove('bg-indigo-600', 'text-white'));
      this.classList.add('bg-indigo-600', 'text-white');
    }));

    initRec(); hi(st.lang); setLabel(st.lang);
  }

  function initListener() {
    const c = window.__SPIKIA_LISTENER__;
    if (!c) return;
    const box = document.getElementById('subtitles-container');
    const list = document.getElementById('timeline-list');
    const meta = document.getElementById('timeline-meta');
    const pend = document.getElementById('pending-count');
    const labelEl = document.getElementById('selected-language-label');
    const langBtns = document.querySelectorAll('.mobile-lang-btn');
    const dot = document.getElementById('status-dot');
    const status = document.getElementById('status-text');
    const audioBtn = document.getElementById('toggle-audio-btn');
    const audioBg = document.getElementById('audio-btn-bg');
    const on = document.getElementById('icon-audio-on');
    const off = document.getElementById('icon-audio-off');
    if (!box || !audioBtn || !dot || !status) return;

    const map = {
      en:{lang:'en',base:'en',speech:'en-US',name:'English'},
      'es-ES':{lang:'es-ES',base:'es',speech:'es-ES',name:'Espa\u00f1ol Espa\u00f1a'},
      'es-419':{lang:'es-419',base:'es',speech:'es-MX',name:'Espa\u00f1ol LatAm'},
      pt:{lang:'pt',base:'pt',speech:'pt-BR',name:'Portugu\u00e9s'},
      it:{lang:'it',base:'it',speech:'it-IT',name:'Italiano'},
      fr:{lang:'fr',base:'fr',speech:'fr-FR',name:'Franc\u00e9s'},
    };

    let current = localStorage.getItem('spikia_mobile_lang') || c.defaultLang || 'es-ES';
    const storedAudioSetting = localStorage.getItem('spikia_audio_enabled');
    let audio = storedAudioSetting === null ? !!c.audioDefaultEnabled : storedAudioSetting === 'true';
    const voiceProviderStorageKey = 'spikia_voice_provider';
    let currentVoiceAudio = null;
    let initialDone = false;
    let seen = new Set(), seenKey = new Set(), last = '', lastAt = 0, busy = false;
    const pageLoadedAt = Date.now();
    const pendingDisplayTimeouts = new Set();
    let languageSelectionVersion = 0;

    const clearUI = () => {
      pendingDisplayTimeouts.forEach((timeoutId) => window.clearTimeout(timeoutId));
      pendingDisplayTimeouts.clear();
      box.innerHTML = '<p id="placeholder" class="text-zinc-600 font-light italic text-lg animate-pulse tracking-wide">Selecciona tu idioma arriba...</p>';
      if (list) list.innerHTML = '<div class="rounded-2xl border border-dashed border-white/10 bg-white/5 px-4 py-3 text-sm text-zinc-500">Los mensajes traducidos apareceran aqui casi en tiempo real.</div>';
    };
    const audioUI = () => {
      if (on) on.classList.toggle('hidden', !audio);
      if (off) off.classList.toggle('hidden', audio);
      if (audioBg) audioBg.classList.toggle('opacity-100', audio);
    };
    const getVoiceProvider = () => {
      const stored = localStorage.getItem(voiceProviderStorageKey);
      return (stored || c.voiceProvider || 'browser').toLowerCase() === 'elevenlabs' ? 'elevenlabs' : 'browser';
    };
    const stopCurrentVoiceAudio = () => {
      if (!currentVoiceAudio) return;
      try {
        currentVoiceAudio.pause();
        currentVoiceAudio.currentTime = 0;
      } catch (e) {}
      if (currentVoiceAudio._spikiaObjectUrl) URL.revokeObjectURL(currentVoiceAudio._spikiaObjectUrl);
      currentVoiceAudio = null;
    };
    const speakWithBrowser = (text, lang, variante, gender) => {
      if (!('speechSynthesis' in window)) return false;
      window.speechSynthesis.cancel();
      const u = new SpeechSynthesisUtterance(text);
      const map = {
        en: 'en-US',
        es: variante === 'es-419' ? 'es-MX' : 'es-ES',
        'es-ES': 'es-ES',
        'es-419': 'es-MX',
        pt: 'pt-BR',
        it: 'it-IT',
        fr: 'fr-FR',
      };
      u.lang = map[variante || lang] || 'es-ES';
      u.rate = 0.96;
      u.pitch = String(gender || '').toLowerCase() === 'male' ? 0.92 : 1.08;
      const voices = window.speechSynthesis.getVoices();
      const matches = voices.filter(v => v.lang && v.lang.includes(u.lang));
      if (matches[0]) u.voice = matches[0];
      u.onend = () => audioState(false);
      u.onerror = () => audioState(false);
      audioState(true);
      window.speechSynthesis.speak(u);
      return true;
    };
    const speakWithElevenLabs = async (text, lang, variante, gender) => {
      if (getVoiceProvider() !== 'elevenlabs' || !c.voiceEndpoint) return false;
      try {
        const res = await fetch(c.voiceEndpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'audio/mpeg, audio/*;q=0.9', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '' },
          body: JSON.stringify({ text, lang, variante, gender }),
        });
        if (!res.ok) return false;
        const blob = await res.blob();
        if (!blob.size) return false;
        stopCurrentVoiceAudio();
        const objectUrl = URL.createObjectURL(blob);
        const audioEl = new Audio(objectUrl);
        audioEl._spikiaObjectUrl = objectUrl;
        currentVoiceAudio = audioEl;
        audioEl.onended = () => { if (currentVoiceAudio === audioEl) { stopCurrentVoiceAudio(); audioState(false); } };
        audioEl.onerror = () => { if (currentVoiceAudio === audioEl) { stopCurrentVoiceAudio(); audioState(false); } };
        audioState(true);
        await audioEl.play();
        return true;
      } catch (e) {
        stopCurrentVoiceAudio();
        audioState(false);
        return false;
      }
    };
    const playProvidedAudio = async (audioUrl) => {
      if (!audioUrl) return false;
      try {
        stopCurrentVoiceAudio();
        const audioEl = new Audio(audioUrl);
        currentVoiceAudio = audioEl;
        audioEl.onended = () => { if (currentVoiceAudio === audioEl) { stopCurrentVoiceAudio(); audioState(false); } };
        audioEl.onerror = () => { if (currentVoiceAudio === audioEl) { stopCurrentVoiceAudio(); audioState(false); } };
        audioState(true);
        await audioEl.play();
        return true;
      } catch (e) {
        stopCurrentVoiceAudio();
        audioState(false);
        return false;
      }
    };
    const setLive = () => { dot.className = 'w-2 h-2 rounded-full bg-cyan-400 shadow-[0_0_10px_#22d3ee]'; status.innerText = 'EN LINEA'; };
    const setOff = () => { dot.className = 'w-2 h-2 rounded-full bg-red-500 shadow-[0_0_10px_red]'; status.innerText = 'DESCONECTADO'; };
    const setLabel = (l) => { if (labelEl) labelEl.textContent = `${label(l, c.languageLabels)} · ${(l || 'es-ES').toUpperCase()}`; };
    const hi = (l) => langBtns.forEach((b) => {
      const active = b.dataset.lang === l;
      b.classList.toggle('active-lang', active);
      b.classList.toggle('lang-active', active);
    });
    const match = (m) => {
      const lang = m.idioma || 'es', v = m.variante || '';
      if (current === 'es-ES') return lang === 'es' && (!v || v === 'es-ES');
      if (current === 'es-419') return lang === 'es' && (v === 'es-419' || !v);
      return current === lang || base(current) === base(lang);
    };
    const show = (text) => {
      const ph = document.getElementById('placeholder'); if (ph) ph.remove();
      box.innerHTML = '';
      const p = document.createElement('p');
      p.className = 'text-3xl font-black text-white animate-subtitle-in uppercase italic mb-4';
      p.innerText = repeat(text);
      box.appendChild(p);
    };
    const add = (m) => {
      if (m.tipo === 'original') return;
      const n = {
        ...m,
        texto: repeat(m.texto || m.traduccion || ''),
        idioma: m.idioma || 'es',
        variante: m.variante || '',
        genero: m.genero || m.gender || '',
        id: m.id || `${m.idioma || 'es'}:${m.variante || ''}:${m.published_at || Date.now()}:${repeat(m.texto || m.traduccion || '')}`,
        dedupeKey: `${m.idioma || 'es'}|${m.variante || ''}|${m.available_at || m.published_at || 0}|${repeat(m.texto || m.traduccion || '')}`,
      };
      if (n.id && seen.has(n.id)) return;
      if (n.dedupeKey && seenKey.has(n.dedupeKey)) return;
      if (!match(n)) return;
      const published = Number(n.published_at || 0) * 1000;
      if (!initialDone && published && (Date.now() - published) > 15000) return;
      seen.add(n.id); seenKey.add(n.dedupeKey);
      const key = `${n.idioma}|${n.variante}|${n.texto}`;
      if (key === last && Date.now() - lastAt < 8000) return;
      const delay = Number(n.available_at || 0);
      const wait = delay > 0 ? Math.max(0, (delay < 1e11 ? delay * 1000 : delay) - Date.now()) : 0;
      const selectionVersionAtQueue = languageSelectionVersion;
      const timeoutId = window.setTimeout(() => {
        pendingDisplayTimeouts.delete(timeoutId);
        if (selectionVersionAtQueue !== languageSelectionVersion) return;
        if (!match(n)) return;

        last = key; lastAt = Date.now(); show(n.texto);
        if (list) {
          const item = document.createElement('article');
          item.className = 'rounded-2xl border border-white/10 bg-white/5 px-4 py-3 flex items-start justify-between gap-4';
          item.innerHTML = `<div class="min-w-0 flex-1"><div class="flex items-center gap-3 mb-2"><span class="inline-flex items-center rounded-full border border-neonBlue/30 bg-neonBlue/10 px-2 py-1 text-[9px] font-black uppercase tracking-[0.3em] text-neonBlue">${(n.variante || n.idioma || 'es').toString().toUpperCase()}</span><span class="text-[10px] font-black uppercase tracking-[0.25em] text-zinc-500">Liberado +1s</span></div><p class="text-sm text-white/90 leading-6 break-words">${n.texto}</p></div><div class="text-right shrink-0"><p class="text-[10px] font-black uppercase tracking-[0.25em] text-zinc-500">${new Date((n.published_at || Date.now()/1000) * 1000).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit',second:'2-digit'})}</p></div>`;
          list.prepend(item);
          while (list.children.length > 6) list.removeChild(list.lastElementChild);
        }
        if (audio) {
          const lang = n.variante || n.idioma;
          if (n.audio_url) {
            playProvidedAudio(n.audio_url).then((ok) => {
              if (!ok && (c.translationSettings?.translation_mode || 'voice_to_voice') === 'voice_to_voice') {
                const elevenFallback = getVoiceProvider() === 'elevenlabs' && c.voiceEndpoint;
                if (elevenFallback) {
                  speakWithElevenLabs(n.texto, lang, n.variante || '', n.genero || '').then((elevenOk) => {
                    if (!elevenOk) speakWithBrowser(n.texto, lang, n.variante || '', n.genero || '');
                  });
                } else {
                  speakWithBrowser(n.texto, lang, n.variante || '', n.genero || '');
                }
              }
            });
          } else if ((c.translationSettings?.translation_mode || 'voice_to_voice') === 'voice_to_voice') {
            const eleven = getVoiceProvider() === 'elevenlabs' && c.voiceEndpoint;
            if (eleven) {
              speakWithElevenLabs(n.texto, lang, n.variante || '', n.genero || '').then((ok) => {
                if (!ok) {
                  speakWithBrowser(n.texto, lang, n.variante || '', n.genero || '');
                }
              });
            } else {
              speakWithBrowser(n.texto, lang, n.variante || '', n.genero || '');
            }
          }
        }
      }, wait);
      pendingDisplayTimeouts.add(timeoutId);
    };
    const audioState = (a) => {
      if (a) { if (on) on.classList.remove('hidden'); if (off) off.classList.add('hidden'); if (audioBg) audioBg.classList.add('opacity-100'); }
      else if (!audio) { if (on) on.classList.add('hidden'); if (off) off.classList.remove('hidden'); if (audioBg) audioBg.classList.remove('opacity-100'); }
    };
    const poll = async (force = false) => {
      if (busy) return;
      busy = true;
      try {
        const res = await fetch(c.feedUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
        const p = await readJSON(res, 'No se pudieron leer los mensajes.');
        if (!res.ok) throw new Error(p.message || 'No se pudieron leer los mensajes.');
        setLive();
        if (pend) pend.textContent = `${p.pending_count ?? 0} pendientes`;
        if (meta) meta.textContent = p.next_available_in_seconds !== null ? `El siguiente mensaje se libera en ${p.next_available_in_seconds}s.` : 'Todo al dia. No hay mensajes en espera.';
        const msgs = Array.isArray(p.messages) ? p.messages : [];
        if (!initialDone) {
          const recent = msgs.map(m => ({
            ...m,
            texto: repeat(m.texto || m.traduccion || ''),
            idioma: m.idioma || 'es',
            variante: m.variante || '',
          })).filter(m => m.tipo !== 'original' && match(m) && ((Date.now() - Number(m.published_at || 0) * 1000) <= 30000)).pop();
          if (recent) { initialDone = true; add(recent); }
        }
        msgs.forEach(add);
      } catch (e) {
        console.error('Error consultando la transmision:', e);
        setOff();
      } finally { busy = false; }
    };

    clearUI(); audioUI(); hi(current); setLabel(current);
    langBtns.forEach((b) => b.addEventListener('click', () => { current = b.dataset.lang; languageSelectionVersion += 1; localStorage.setItem('spikia_mobile_lang', current); clearUI(); hi(current); setLabel(current); poll(true); }));
    audioBtn.addEventListener('click', () => { audio = !audio; localStorage.setItem('spikia_audio_enabled', String(audio)); audioUI(); if (audio && 'speechSynthesis' in window) try { window.speechSynthesis.speak(new SpeechSynthesisUtterance('')); } catch (e) {} else { stopCurrentVoiceAudio(); if ('speechSynthesis' in window) window.speechSynthesis.cancel(); } });
    poll(true); window.setInterval(() => poll(false), 200);
  }

  ready(() => { initMaster(); initListener(); });
})();
