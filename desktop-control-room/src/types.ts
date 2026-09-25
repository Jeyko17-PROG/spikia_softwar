// Tipos compartidos del Control Room. Centralizados aca (en vez de repetidos por
// componente) porque el store de Zustand y varios paneles necesitan las mismas formas.

export type PerformanceProfile = 'rapido' | 'equilibrado' | 'estable';

export type TranslationService = 'openai' | 'deepgram' | 'azure' | 'google';

export interface MicDevice {
    id: string;
    label: string;
}

export type SubtitleBackground = 'transparente' | 'negro-solido' | 'blanco-solido' | 'degradado';

export type SubtitleTextColor = 'blanco' | 'amarillo' | 'cian';

export type SubtitleOutlineStyle = 'contorno' | 'sombra' | 'ninguno';

export type SubtitleLineCount = 1 | 2 | 3;

export interface OutputDisplay {
    id: string;
    label: string;
    resolution: string;
}

export interface TranslationChannel {
    id: string;
    languageCode: string;
    languageLabel: string;
    isActive: boolean;
    liveTranscription: boolean;
    recording: boolean;
    saveFolder: string;
}

export type AvatarConnectionStatus = 'desconectado' | 'conectando' | 'conectado';
