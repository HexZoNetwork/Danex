import http from '@/api/http';
import axios from 'axios';
import { AxiosError } from 'axios';
import { History } from 'history';

export const setupInterceptors = (history: History) => {
    http.interceptors.response.use(
        (resp) => resp,
        async (error: AxiosError) => {
            const status = error.response?.status || 0;
            const original = (error.config || {}) as any;
            const data = (error.response?.data || {}) as Record<string, any>;

            if (status === 403 && data?.error === 'session_binding_mismatch' && typeof data?.challenge_url === 'string') {
                const challengeUrl = data.challenge_url.trim();
                if (challengeUrl.length > 0) {
                    window.location.assign(challengeUrl);
                }
            }

            if (status === 419 && !original?._csrfRetried) {
                try {
                    original._csrfRetried = true;
                    await axios.get('/sanctum/csrf-cookie', {
                        withCredentials: true,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            Accept: 'application/json',
                        },
                    });

                    return await http.request(original);
                } catch {
                    // fall through to existing error flow
                }
            }

            if (error.response?.status === 400) {
                if (
                    (error.response?.data as Record<string, any>).errors?.[0].code === 'TwoFactorAuthRequiredException'
                ) {
                    if (!window.location.pathname.startsWith('/account')) {
                        history.replace('/account', { twoFactorRedirect: true });
                    }
                }
            }
            throw error;
        }
    );
};
