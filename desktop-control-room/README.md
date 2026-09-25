# Spikia Control Room

Master Panel de escritorio: control de audio/traducción, canales, subtítulos y avatar 3D en
una sola pantalla. React + TypeScript + Tailwind CSS + Zustand.

## Correr en desarrollo

```bash
npm install
npm run dev
```

## Compilar

```bash
npm run build
```

Genera `dist/` con rutas de assets relativas (`base: './'` en `vite.config.ts`), listo para
empaquetarse con Electron o Tauri sirviendo el `index.html` desde `file://` o un esquema
custom.

## Estructura

- `src/store/useControlRoomStore.ts` — estado global (Zustand): configuración general, audio
  de entrada, transmisión en vivo, canales de traducción, estilo de subtítulos y avatar.
- `src/components/MasterDashboard.tsx` — componente raíz, layout de dos columnas.
- `src/components/panels/` — un componente por sección de la UI.
- `src/components/ui/` — primitivas reusables (Card, Toggle, SegmentedControl, Slider, Select).

## Integración nativa (Electron/Tauri)

`TranslationChannelsPanel.tsx` espera un puente `window.spikiaNative.pickFolder()` para el
selector de carpeta nativo del sistema operativo (expuesto vía `contextBridge` en Electron o
`invoke()` en Tauri). Sin ese puente (ej. corriendo en un navegador normal con `npm run dev`),
la ruta de guardado sigue siendo editable a mano.

## Conexión con el resto de Spikia

Este panel es la capa de UI/estado — no incluye la lógica de red real (websockets, captura de
audio, etc.), pensada para conectarse a los servicios ya existentes en la raíz del proyecto:
`spikia-socket/` (mensajería en vivo) y el pipeline de avatar en `sign-nlp-service/` +
`sign-avatar-orchestrator/` + `unreal/SpikiaSignAvatar/`.
