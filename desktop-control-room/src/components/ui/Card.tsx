import type { PropsWithChildren, ReactNode } from 'react';

interface CardProps {
    title: string;
    icon?: ReactNode;
    action?: ReactNode;
    className?: string;
}

// Contenedor base reusado por todos los paneles - mismo lenguaje visual que el resto de
// Spikia (bordes sutiles, fondo zinc-900 semitransparente, esquinas bien redondeadas).
export function Card({ title, icon, action, className = '', children }: PropsWithChildren<CardProps>) {
    return (
        <section className={`rounded-[1.75rem] border border-white/10 bg-zinc-900/40 backdrop-blur-sm ${className}`}>
            <header className="flex items-center justify-between gap-3 border-b border-white/5 px-5 py-4">
                <div className="flex items-center gap-2.5 min-w-0">
                    {icon && <span className="text-neonBlue shrink-0">{icon}</span>}
                    <h2 className="truncate text-[11px] font-black uppercase tracking-[0.25em] text-zinc-300">{title}</h2>
                </div>
                {action && <div className="shrink-0">{action}</div>}
            </header>
            <div className="p-5">{children}</div>
        </section>
    );
}
