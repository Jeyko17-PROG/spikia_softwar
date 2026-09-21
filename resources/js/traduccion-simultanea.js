document.addEventListener('DOMContentLoaded', () => {
    const cfg = document.getElementById('ts-config');
    const csrfToken = cfg.dataset.csrf;
    const storeUrl = cfg.dataset.storeUrl;

    const form = document.getElementById('ts-form');
    const submitBtn = document.getElementById('submit-btn');
    const recordBtn = document.getElementById('record-btn');
    const recordLabel = document.getElementById('record-label');
    const recordIcon = document.getElementById('record-icon');
    const recordTimer = document.getElementById('record-timer');
    const audioStatus = document.getElementById('audio-status');
    const audioInput = document.getElementById('audio-input');

    const resultBox = document.getElementById('ts-result');
    const loadingBox = document.getElementById('ts-loading');
    const errorBox = document.getElementById('ts-error');
    const loadingStep = document.getElementById('loading-step');
    const errorMsg = document.getElementById('error-message');

    const transcriptEl = document.getElementById('transcript-text');
    const translatedEl = document.getElementById('translated-text');
    const resultAudio = document.getElementById('result-audio');
    const outputAudio = document.getElementById('output-audio');

    const ttsOptions = document.getElementById('tts-options');
    const speakersContainer = document.getElementById('speakers-container');
    const sttModel = document.getElementById('stt-model');
    const speakersList = document.getElementById('speakers-list');
    const addSpeakerBtn = document.getElementById('add-speaker-btn');

    let mediaRecorder = null;
    let audioChunks = [];
    let timerInterval = null;
    let seconds = 0;

    function selectedValue(name) {
        return form.querySelector(`input[name="${name}"]:checked`)?.value ?? null;
    }

    function setSpeakerInputsDisabled(disabled) {
        speakersList.querySelectorAll('input').forEach(input => {
            input.disabled = disabled;
        });
    }

    function syncFormState() {
        const translationMode = selectedValue('translation_mode');
        const speakerMode = selectedValue('speaker_mode');
        const isVoiceToVoice = translationMode === 'voice_to_voice';
        const isMultiple = speakerMode === 'multiple';

        ttsOptions.classList.toggle('hidden', !isVoiceToVoice);
        speakersContainer.classList.toggle('hidden', !isMultiple);
        setSpeakerInputsDisabled(!isMultiple);

        if (isMultiple) {
            const diarizeOption = sttModel.querySelector('option[value="gpt-4o-transcribe-diarize"]');
            if (diarizeOption) {
                sttModel.value = 'gpt-4o-transcribe-diarize';
            }
        } else {
            const defaultOption = sttModel.querySelector('option[value="gpt-4o-mini-transcribe"]');
            if (defaultOption) {
                sttModel.value = 'gpt-4o-mini-transcribe';
            }
        }
    }

    document.querySelectorAll('input[name="translation_mode"], input[name="speaker_mode"]').forEach(input => {
        input.addEventListener('change', syncFormState);
    });

    addSpeakerBtn.addEventListener('click', () => {
        const rows = speakersList.querySelectorAll('.speaker-row');
        const idx = rows.length;
        const row = document.createElement('div');
        row.className = 'flex gap-3 items-center speaker-row';
        row.innerHTML = `
            <input type="hidden" name="speakers[${idx}][speaker_id]" value="speaker_${idx + 1}">
            <input type="text" name="speakers[${idx}][name]" placeholder="Nombre persona ${idx + 1}"
                class="flex-1 rounded-xl bg-white/5 border border-white/10 px-4 py-2 text-sm text-white placeholder-zinc-500 focus:border-purple-500 focus:outline-none">
            <button type="button" class="text-xs text-red-400 hover:text-red-300 remove-speaker">x</button>
        `;
        speakersList.appendChild(row);
        row.querySelector('.remove-speaker').addEventListener('click', () => row.remove());
        syncFormState();
    });

    if (!window.MediaRecorder || !navigator.mediaDevices?.getUserMedia) {
        recordBtn.disabled = true;
        showError('Tu navegador no soporta grabacion de audio con MediaRecorder.');
        return;
    }

    recordBtn.addEventListener('click', async () => {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            stopRecording();
            return;
        }

        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            startRecording(stream);
        } catch {
            showError('No se pudo acceder al microfono. Verifica los permisos del navegador.');
        }
    });

    function startRecording(stream) {
        audioChunks = [];
        mediaRecorder = new MediaRecorder(stream);

        mediaRecorder.ondataavailable = event => {
            if (event.data.size > 0) {
                audioChunks.push(event.data);
            }
        };

        mediaRecorder.onstop = () => {
            const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
            const file = new File([audioBlob], 'grabacion.webm', { type: 'audio/webm' });
            const dt = new DataTransfer();
            dt.items.add(file);
            audioInput.files = dt.files;
            audioStatus.classList.remove('hidden');
            audioStatus.textContent = 'Audio listo para enviar';
            stream.getTracks().forEach(track => track.stop());
        };

        mediaRecorder.start();
        seconds = 0;
        recordLabel.textContent = 'Detener';
        recordIcon.classList.replace('bg-red-500', 'bg-white');
        recordTimer.classList.remove('hidden');
        recordTimer.textContent = '00:00';

        timerInterval = setInterval(() => {
            seconds += 1;
            const minutes = String(Math.floor(seconds / 60)).padStart(2, '0');
            const secs = String(seconds % 60).padStart(2, '0');
            recordTimer.textContent = `${minutes}:${secs}`;
        }, 1000);
    }

    function stopRecording() {
        mediaRecorder.stop();
        clearInterval(timerInterval);
        recordLabel.textContent = 'Grabar';
        recordIcon.classList.replace('bg-white', 'bg-red-500');
        recordTimer.classList.add('hidden');
    }

    form.addEventListener('submit', async event => {
        event.preventDefault();

        if (!audioInput.files.length) {
            showError('Primero graba un audio antes de traducir.');
            return;
        }

        hideAll();
        loadingBox.classList.remove('hidden');
        loadingStep.textContent = 'Procesando audio con OpenAI...';
        submitBtn.disabled = true;

        const formData = new FormData(form);
        formData.set('_token', csrfToken);

        try {
            const response = await fetch(storeUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: formData,
                credentials: 'same-origin',
            });

            const contentType = response.headers.get('content-type') ?? '';
            const payload = contentType.includes('application/json')
                ? await response.json()
                : { success: false, message: 'El servidor devolvio una respuesta no valida.' };

            if (!response.ok || !payload.success) {
                const validationErrors = payload.errors
                    ? Object.values(payload.errors).flat().join(' ')
                    : null;
                throw new Error(validationErrors || payload.message || 'Error al procesar la traduccion.');
            }

            showResult(payload);
        } catch (error) {
            showError(error.message ?? 'Error inesperado. Intenta de nuevo.');
        } finally {
            loadingBox.classList.add('hidden');
            submitBtn.disabled = false;
        }
    });

    function showResult(data) {
        transcriptEl.textContent = data.original_transcript ?? '';
        translatedEl.textContent = data.translated_text ?? '';

        if (data.translation_mode === 'voice_to_voice' && data.output_audio_url) {
            outputAudio.src = data.output_audio_url;
            resultAudio.classList.remove('hidden');
        } else {
            outputAudio.removeAttribute('src');
            resultAudio.classList.add('hidden');
        }

        resultBox.classList.remove('hidden');
        resultBox.scrollIntoView({ behavior: 'smooth' });
    }

    function showError(message) {
        hideAll();
        errorMsg.textContent = message;
        errorBox.classList.remove('hidden');
    }

    function hideAll() {
        resultBox.classList.add('hidden');
        loadingBox.classList.add('hidden');
        errorBox.classList.add('hidden');
    }

    syncFormState();
});
