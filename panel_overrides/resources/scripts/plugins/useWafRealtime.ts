import { useEffect, useRef, useState } from 'react';

export type WafRealtimeEvent = {
    type: string;
    payload?: Record<string, unknown>;
};

type UseWafRealtimeArgs = {
    enabled: boolean;
    onEvent: (event: WafRealtimeEvent) => void;
    maxRetries?: number;
};

export default ({ enabled, onEvent, maxRetries = 2 }: UseWafRealtimeArgs) => {
    const [isConnected, setConnected] = useState(false);
    const retryRef = useRef(0);
    const timerRef = useRef<number | null>(null);

    useEffect(() => {
        if (!enabled) return;

        let closed = false;
        let socket: WebSocket | null = null;

        const clearTimer = () => {
            if (timerRef.current) {
                window.clearTimeout(timerRef.current);
                timerRef.current = null;
            }
        };

        const connect = () => {
            if (retryRef.current > maxRetries) return;

            const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
            const url = `${protocol}//${window.location.host}/waf/live`;

            try {
                socket = new WebSocket(url);
            } catch {
                scheduleReconnect();
                return;
            }

            socket.onopen = () => {
                retryRef.current = 0;
                setConnected(true);
            };

            socket.onmessage = (message) => {
                try {
                    const data = JSON.parse(message.data) as WafRealtimeEvent;
                    if (data && typeof data.type === 'string') {
                        onEvent(data);
                    }
                } catch {
                    // ignore malformed events
                }
            };

            socket.onerror = () => {
                setConnected(false);
            };

            socket.onclose = () => {
                setConnected(false);
                if (!closed) scheduleReconnect();
            };
        };

        const scheduleReconnect = () => {
            clearTimer();
            retryRef.current += 1;
            if (retryRef.current > maxRetries) return;

            const delay = Math.min(30000, 1000 * Math.pow(2, Math.min(5, retryRef.current)));
            timerRef.current = window.setTimeout(connect, delay);
        };

        connect();

        return () => {
            closed = true;
            clearTimer();
            if (socket && socket.readyState < 2) socket.close();
        };
    }, [enabled, maxRetries, onEvent]);

    return { isConnected };
};
