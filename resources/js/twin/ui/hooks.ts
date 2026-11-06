import { useCallback, useRef, useState } from 'react';

/**
 * Tracks an element's width, for charts drawn in real pixels. A callback ref,
 * because the element may mount long after the component first renders.
 */
export function useWidth<T extends HTMLElement>(): [
    (node: T | null) => void,
    number,
] {
    const observer = useRef<ResizeObserver | null>(null);
    const [width, setWidth] = useState(0);
    const ref = useCallback((node: T | null) => {
        observer.current?.disconnect();
        observer.current = null;
        if (!node) return;
        observer.current = new ResizeObserver(([entry]) =>
            setWidth(entry!.contentRect.width),
        );
        observer.current.observe(node);
    }, []);
    return [ref, width];
}
