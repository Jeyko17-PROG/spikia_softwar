import { Card } from '../ui/Card';
import { Select } from '../ui/Select';
import { Slider } from '../ui/Slider';
import { useControlRoomStore } from '../../store/useControlRoomStore';

export function AudioInputPanel() {
    const microphones = useControlRoomStore((state) => state.availableMicrophones);
    const primaryMicId = useControlRoomStore((state) => state.primaryMicId);
    const setPrimaryMic = useControlRoomStore((state) => state.setPrimaryMic);
    const interpreterMicId = useControlRoomStore((state) => state.interpreterMicId);
    const setInterpreterMic = useControlRoomStore((state) => state.setInterpreterMic);
    const noiseCancellationLevel = useControlRoomStore((state) => state.noiseCancellationLevel);
    const setNoiseCancellationLevel = useControlRoomStore((state) => state.setNoiseCancellationLevel);

    const interpreterMicOptions = [
        { value: '', label: 'Ninguno (usar solo micrófono principal)' },
        ...microphones.map((mic) => ({ value: mic.id, label: mic.label })),
    ];

    return (
        <Card
            title="Audio de entrada"
            icon={
                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 18.75a6 6 0 006-6v-1.5m-6 7.5a6 6 0 01-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 01-3-3V4.5a3 3 0 116 0v8.25a3 3 0 01-3 3z" />
                </svg>
            }
        >
            <div className="space-y-5">
                <Select
                    label="Micrófono principal (presentador)"
                    value={primaryMicId}
                    options={microphones.map((mic) => ({ value: mic.id, label: mic.label }))}
                    onChange={setPrimaryMic}
                />

                <Slider label="Cancelación de ruido" value={noiseCancellationLevel} min={0} max={100} unit="%" onChange={setNoiseCancellationLevel} />

                <div className="h-px bg-white/5" />

                <Select
                    label="Micrófono del intérprete (opcional)"
                    value={interpreterMicId ?? ''}
                    options={interpreterMicOptions}
                    onChange={(value) => setInterpreterMic(value === '' ? null : value)}
                />
                <p className="text-[10px] leading-relaxed text-zinc-500">
                    Usar un segundo micrófono dedicado cuando un intérprete humano acompaña al avatar (modo "Intérprete en vivo") evita que su voz se mezcle con la del presentador.
                </p>
            </div>
        </Card>
    );
}
