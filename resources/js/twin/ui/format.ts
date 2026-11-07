export function clock(t: number, utcOffsetHours: number): string {
    return new Date(t + utcOffsetHours * 3_600_000).toISOString().slice(11, 16);
}

/** Month, day and time at the site, for charts spanning days. */
export function dateTime(t: number, utcOffsetHours: number): string {
    return new Date(t + utcOffsetHours * 3_600_000)
        .toISOString()
        .slice(5, 16)
        .replace('T', ' ');
}

export function duration(ms: number): string {
    const minutes = Math.round(ms / 60_000);
    if (minutes < 60) return `${minutes} min`;
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;
    return rest ? `${hours} h ${rest} min` : `${hours} h`;
}

export const kw = (v: number | undefined) =>
    v === undefined ? '—' : `${Math.round(v).toLocaleString('en-AU')} kW`;
