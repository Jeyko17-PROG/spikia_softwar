const form = document.getElementById('supportChatForm');
const chatBox = document.getElementById('chatBox');
const messageInput = document.getElementById('message');
const micButton = document.getElementById('supportMicBtn');
const micStatus = document.getElementById('supportMicStatus');

if (form && chatBox && messageInput) {
    const csrfToken = form.dataset.csrf || '';
    const chatUrl = form.dataset.chatUrl || '';
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    let recognition = null;
    let micActive = false;
    let lastTranscript = '';
    let selectedQuickButton = null;

    const appendMessage = (role, text) => {
        const wrapper = document.createElement('div');
        wrapper.className = role === 'user' ? 'flex justify-end' : 'flex justify-start';

        const bubble = document.createElement('div');
        bubble.className = role === 'user'
            ? 'max-w-[85%] rounded-2xl border border-[#00d2ff]/20 bg-[#00d2ff]/15 px-4 py-3 text-sm leading-6 text-white'
            : 'max-w-[85%] rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm leading-6 text-zinc-200';
        bubble.textContent = text;

        wrapper.appendChild(bubble);
        chatBox.appendChild(wrapper);
        chatBox.scrollTop = chatBox.scrollHeight;
    };

    const setMicState = (active, label) => {
        micActive = active;
        if (micButton) {
            micButton.classList.toggle('support-mic-active', active);
            micButton.setAttribute('aria-pressed', active ? 'true' : 'false');
        }
        if (micStatus) {
            micStatus.textContent = label || (active ? 'Escuchando' : 'Microfono');
        }
    };

    const setSelectedQuickQuestion = (button) => {
        if (selectedQuickButton) {
            selectedQuickButton.classList.remove('border-[#00d2ff]/50', 'bg-[#00d2ff]/15', 'text-white');
        }

        selectedQuickButton = button;
        if (selectedQuickButton) {
            selectedQuickButton.classList.add('border-[#00d2ff]/50', 'bg-[#00d2ff]/15', 'text-white');
        }
    };

    const sendQuestion = async (text) => {
        const cleanText = String(text || '').trim();

        if (!cleanText) {
            return;
        }

        appendMessage('user', cleanText);
        const pending = document.createElement('div');
        pending.className = 'flex justify-start';
        pending.innerHTML = '<div class="max-w-[85%] rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm leading-6 text-zinc-400">Escribiendo respuesta...</div>';
        chatBox.appendChild(pending);
        chatBox.scrollTop = chatBox.scrollHeight;

        try {
            const response = await fetch(chatUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({ message: cleanText }),
            });

            const payload = await response.json();
            pending.remove();
            appendMessage('assistant', payload.answer || 'No pude generar una respuesta.');
        } catch (error) {
            pending.remove();
            appendMessage('assistant', 'No pude conectar con el asistente en este momento.');
        }
    };

    document.querySelectorAll('[data-message]').forEach((button) => {
        button.addEventListener('click', async () => {
            const question = button.dataset.message || '';
            messageInput.value = question;
            messageInput.focus();
            setSelectedQuickQuestion(button);
            messageInput.value = '';
            await sendQuestion(question);
        });
    });

    messageInput.addEventListener('input', () => {
        if (messageInput.value.trim() === '') {
            setSelectedQuickQuestion(null);
        }
    });

    if (micButton && SpeechRecognition) {
        recognition = new SpeechRecognition();
        recognition.lang = 'es-ES';
        recognition.continuous = false;
        recognition.interimResults = true;
        recognition.maxAlternatives = 1;

        recognition.onstart = () => {
            setMicState(true, 'Escuchando');
        };

        recognition.onresult = (event) => {
            let transcript = '';

            for (let i = event.resultIndex; i < event.results.length; i += 1) {
                transcript += event.results[i][0].transcript;
            }

            const trimmed = transcript.trim();
            messageInput.value = trimmed;
            setSelectedQuickQuestion(null);

            if (event.results[event.results.length - 1].isFinal && trimmed && trimmed !== lastTranscript) {
                lastTranscript = trimmed;
                sendQuestion(trimmed);
            }
        };

        recognition.onerror = () => {
            setMicState(false, 'Microfono');
            appendMessage('assistant', 'No pude usar el microfono. Verifica permisos del navegador.');
        };

        recognition.onend = () => {
            setMicState(false, 'Microfono');
        };

        micButton.addEventListener('click', () => {
            if (micActive) {
                recognition.stop();
                setMicState(false, 'Microfono');
                return;
            }

            lastTranscript = '';
            recognition.start();
        });
    } else if (micButton) {
        micButton.disabled = true;
        micButton.classList.add('opacity-50', 'cursor-not-allowed');
        if (micStatus) {
            micStatus.textContent = 'No disponible';
        }
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const text = messageInput.value.trim();
        if (!text) {
            return;
        }

        messageInput.value = '';
        setSelectedQuickQuestion(null);
        await sendQuestion(text);
    });
}
