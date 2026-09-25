// Estado global del Control Room. Se eligio Zustand en vez de Context porque varias
// secciones (nivel de audio, canales de traduccion, estilos de subtitulos) cambian con
// frecuencia independiente unas de otras - con Context, cualquier cambio (ej. mover el
// slider de cancelacion de ruido) re-renderiza TODO el arbol que consume el contexto. Con
// Zustand, cada componente se suscribe solo al slice que realmente usa (via selectores), asi
// que ese mismo cambio solo re-renderiza el panel de audio.

import { create } from 'zustand';
import type {
    AvatarConnectionStatus,
    MicDevice,
    OutputDisplay,
    PerformanceProfile,
    SubtitleBackground,
    SubtitleLineCount,
    SubtitleOutlineStyle,
    SubtitleTextColor,
    TranslationChannel,
    TranslationService,
} from '../types';

// Datos de arranque (dispositivos, pantallas) - en la app real estos vienen de la capa
// nativa de Electron/Tauri (navigator.mediaDevices.enumerateDevices() para microfonos,
// y el modulo de pantallas del shell nativo para displays fisicos). Se dejan como mocks
// realistas aca para que el Control Room sea usable/demostrable de forma standalone.
const MOCK_MICROPHONES: MicDevice[] = [
    { id: 'default', label: 'Micrófono predeterminado del sistema' },
    { id: 'mic-shure-sm7b', label: 'Shure SM7B (USB)' },
    { id: 'mic-rode-nt1', label: 'Rode NT1-A (Interfaz XLR)' },
    { id: 'mic-headset', label: 'Auriculares con micrófono' },
];

const MOCK_DISPLAYS: OutputDisplay[] = [
    { id: 'display-1', label: 'Monitor principal', resolution: '1920×1080' },
    { id: 'display-2', label: 'Pantalla LED del escenario', resolution: '3840×1080' },
    { id: 'display-3', label: 'Monitor de confianza (backstage)', resolution: '1920×1080' },
];

const DEFAULT_CHANNELS: TranslationChannel[] = [
    { id: 'ch-es', languageCode: 'es-ES', languageLabel: 'Español (España)', isActive: true, liveTranscription: true, recording: true, saveFolder: 'C:/Spikia/Sesiones/es' },
    { id: 'ch-en', languageCode: 'en-US', languageLabel: 'Inglés (EE. UU.)', isActive: true, liveTranscription: true, recording: false, saveFolder: 'C:/Spikia/Sesiones/en' },
    { id: 'ch-pt', languageCode: 'pt-BR', languageLabel: 'Portugués (Brasil)', isActive: false, liveTranscription: false, recording: false, saveFolder: 'C:/Spikia/Sesiones/pt' },
    { id: 'ch-fr', languageCode: 'fr-FR', languageLabel: 'Francés', isActive: false, liveTranscription: false, recording: false, saveFolder: 'C:/Spikia/Sesiones/fr' },
];

interface ControlRoomState {
    // --- Configuracion general ---
    translationService: TranslationService;
    performanceProfile: PerformanceProfile;
    setTranslationService: (service: TranslationService) => void;
    setPerformanceProfile: (profile: PerformanceProfile) => void;

    // --- Audio de entrada ---
    availableMicrophones: MicDevice[];
    primaryMicId: string;
    interpreterMicId: string | null;
    noiseCancellationLevel: number; // 0-100
    setPrimaryMic: (id: string) => void;
    setInterpreterMic: (id: string | null) => void;
    setNoiseCancellationLevel: (level: number) => void;

    // --- Transmision en vivo / QR ---
    isLiveStreamEnabled: boolean;
    sessionCode: string;
    sessionUrl: string;
    listenerCount: number;
    toggleLiveStream: () => void;

    // --- Canales de traduccion ---
    channels: TranslationChannel[];
    toggleChannelActive: (id: string) => void;
    toggleChannelTranscription: (id: string) => void;
    toggleChannelRecording: (id: string) => void;
    setChannelSaveFolder: (id: string, folder: string) => void;

    // --- Subtitulos: preview + estilo ---
    subtitlePreviewText: string;
    setSubtitlePreviewText: (text: string) => void;
    subtitleBackground: SubtitleBackground;
    subtitleTextColor: SubtitleTextColor;
    subtitleOutlineStyle: SubtitleOutlineStyle;
    subtitleFontSize: number; // px, 24-72
    subtitleLineCount: SubtitleLineCount;
    availableDisplays: OutputDisplay[];
    outputDisplayId: string;
    setSubtitleBackground: (bg: SubtitleBackground) => void;
    setSubtitleTextColor: (color: SubtitleTextColor) => void;
    setSubtitleOutlineStyle: (style: SubtitleOutlineStyle) => void;
    setSubtitleFontSize: (size: number) => void;
    setSubtitleLineCount: (lines: SubtitleLineCount) => void;
    setOutputDisplay: (id: string) => void;

    // --- Avatar 3D / Lengua de señas ---
    isAvatarEnabled: boolean;
    avatarConnectionStatus: AvatarConnectionStatus;
    currentGloss: string | null;
    toggleAvatarEnabled: () => void;
}

export const useControlRoomStore = create<ControlRoomState>((set) => ({
    translationService: 'openai',
    performanceProfile: 'equilibrado',
    setTranslationService: (service) => set({ translationService: service }),
    setPerformanceProfile: (profile) => set({ performanceProfile: profile }),

    availableMicrophones: MOCK_MICROPHONES,
    primaryMicId: MOCK_MICROPHONES[0].id,
    interpreterMicId: null,
    noiseCancellationLevel: 60,
    setPrimaryMic: (id) => set({ primaryMicId: id }),
    setInterpreterMic: (id) => set({ interpreterMicId: id }),
    setNoiseCancellationLevel: (level) => set({ noiseCancellationLevel: Math.min(100, Math.max(0, level)) }),

    isLiveStreamEnabled: false,
    sessionCode: 'SPK-7K9-QX2',
    sessionUrl: 'https://spikia.app/s/7K9QX2',
    listenerCount: 0,
    toggleLiveStream: () =>
        set((state) => ({
            isLiveStreamEnabled: !state.isLiveStreamEnabled,
            // Al apagar la transmision, se resetea el contador - no tiene sentido mostrar
            // "3 oyentes" de una sesion que ya termino cuando se vuelva a prender otra.
            listenerCount: state.isLiveStreamEnabled ? 0 : state.listenerCount,
        })),

    channels: DEFAULT_CHANNELS,
    toggleChannelActive: (id) =>
        set((state) => ({
            channels: state.channels.map((ch) => (ch.id === id ? { ...ch, isActive: !ch.isActive } : ch)),
        })),
    toggleChannelTranscription: (id) =>
        set((state) => ({
            channels: state.channels.map((ch) => (ch.id === id ? { ...ch, liveTranscription: !ch.liveTranscription } : ch)),
        })),
    toggleChannelRecording: (id) =>
        set((state) => ({
            channels: state.channels.map((ch) => (ch.id === id ? { ...ch, recording: !ch.recording } : ch)),
        })),
    setChannelSaveFolder: (id, folder) =>
        set((state) => ({
            channels: state.channels.map((ch) => (ch.id === id ? { ...ch, saveFolder: folder } : ch)),
        })),

    subtitlePreviewText: 'Bienvenidos a la conferencia anual de Spikia. En unos minutos comenzamos.',
    setSubtitlePreviewText: (text) => set({ subtitlePreviewText: text }),
    subtitleBackground: 'negro-solido',
    subtitleTextColor: 'blanco',
    subtitleOutlineStyle: 'contorno',
    subtitleFontSize: 40,
    subtitleLineCount: 2,
    availableDisplays: MOCK_DISPLAYS,
    outputDisplayId: MOCK_DISPLAYS[0].id,
    setSubtitleBackground: (bg) => set({ subtitleBackground: bg }),
    setSubtitleTextColor: (color) => set({ subtitleTextColor: color }),
    setSubtitleOutlineStyle: (style) => set({ subtitleOutlineStyle: style }),
    setSubtitleFontSize: (size) => set({ subtitleFontSize: Math.min(72, Math.max(24, size)) }),
    setSubtitleLineCount: (lines) => set({ subtitleLineCount: lines }),
    setOutputDisplay: (id) => set({ outputDisplayId: id }),

    isAvatarEnabled: true,
    avatarConnectionStatus: 'desconectado',
    currentGloss: null,
    toggleAvatarEnabled: () =>
        set((state) => ({
            isAvatarEnabled: !state.isAvatarEnabled,
            avatarConnectionStatus: !state.isAvatarEnabled ? 'conectando' : 'desconectado',
        })),
}));
