import { Card } from '../ui/Card';
import { Select } from '../ui/Select';
import { SegmentedControl } from '../ui/SegmentedControl';
import { useControlRoomStore } from '../../store/useControlRoomStore';
import type { PerformanceProfile, TranslationService } from '../../types';

const TRANSLATION_SERVICES: { value: TranslationService; label: string }[] = [
    { value: 'openai', label: 'OpenAI (GPT-4o + Whisper)' },
    { value: 'deepgram', label: 'Deepgram (Nova-2, con diarización)' },
    { value: 'azure', label: 'Azure Speech Translator' },
    { value: 'google', label: 'Google Cloud Translation' },
];

const PERFORMANCE_PROFILES: { value: PerformanceProfile; label: string }[] = [
    { value: 'rapido', label: 'Rápido' },
    { value: 'equilibrado', label: 'Equilibrado' },
    { value: 'estable', label: 'Estable' },
];

const PROFILE_DESCRIPTIONS: Record<PerformanceProfile, string> = {
    rapido: 'Menor latencia posible. Prioriza velocidad sobre precisión — ideal para preguntas y respuestas en vivo.',
    equilibrado: 'Balance entre velocidad y precisión. Recomendado para la mayoría de los eventos.',
    estable: 'Máxima precisión y menor consumo de red. Agrega algo de demora — pensado para conferencias grabadas.',
};

export function GeneralConfigPanel() {
    const translationService = useControlRoomStore((state) => state.translationService);
    const setTranslationService = useControlRoomStore((state) => state.setTranslationService);
    const performanceProfile = useControlRoomStore((state) => state.performanceProfile);
    const setPerformanceProfile = useControlRoomStore((state) => state.setPerformanceProfile);

    return (
        <Card
            title="Configuración general"
            icon={
                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
            }
        >
            <div className="space-y-5">
                <Select
                    label="Servicio de traducción"
                    value={translationService}
                    options={TRANSLATION_SERVICES}
                    onChange={(value) => setTranslationService(value as TranslationService)}
                />

                <div>
                    <span className="mb-2 block text-[10px] font-bold uppercase tracking-[0.2em] text-zinc-400">Perfil de rendimiento</span>
                    <SegmentedControl options={PERFORMANCE_PROFILES} value={performanceProfile} onChange={setPerformanceProfile} />
                    <p className="mt-2.5 text-[10px] leading-relaxed text-zinc-500">{PROFILE_DESCRIPTIONS[performanceProfile]}</p>
                </div>
            </div>
        </Card>
    );
}
