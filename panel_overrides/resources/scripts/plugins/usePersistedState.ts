import { Dispatch, SetStateAction, useEffect, useState } from 'react';

export function usePersistedState<S = undefined>(
    key: string,
    defaultValue: S
): [S | undefined, Dispatch<SetStateAction<S | undefined>>] {
    const [state, setState] = useState(() => {
        try {
            const item = localStorage.getItem(key);
            if (item === null || item === undefined || item === '') {
                return defaultValue;
            }

            return JSON.parse(item) as S;
        } catch (e) {
            console.warn('Failed to retrieve persisted value from store.', e);
            try {
                localStorage.removeItem(key);
            } catch {
                // ignore quota or storage access errors
            }

            return defaultValue;
        }
    });

    useEffect(() => {
        localStorage.setItem(key, JSON.stringify(state));
    }, [key, state]);

    return [state, setState];
}
