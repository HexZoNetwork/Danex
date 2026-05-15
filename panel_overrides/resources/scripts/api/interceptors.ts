import http from '@/api/http';
import axios from 'axios';
import { AxiosError } from 'axios';
import { History } from 'history';

const CLEARANCE_OVERLAY_ID = 'danex-clearance-reset-overlay';

const showClearanceResetOverlay = (resetUrl: string, challengeUrl: string, reason?: string) => {
    if (typeof document === 'undefined') {
        window.location.assign(resetUrl || challengeUrl);
        return;
    }

    document.getElementById(CLEARANCE_OVERLAY_ID)?.remove();

    const overlay = document.createElement('div');
    overlay.id = CLEARANCE_OVERLAY_ID;
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', 'DANEX clearance recovery');
    overlay.style.cssText = [
        'position:fixed',
        'inset:0',
        'z-index:2147483647',
        'display:grid',
        'place-items:center',
        'padding:22px',
        'background:rgba(3,3,7,.76)',
        'backdrop-filter:blur(18px)',
    ].join(';');

    overlay.innerHTML = `
        <div style="width:min(520px,100%);border:1px solid rgba(139,92,246,.46);border-radius:18px;background:linear-gradient(180deg,rgba(17,17,23,.98),rgba(8,8,13,.98));box-shadow:0 24px 80px rgba(0,0,0,.56),0 0 54px rgba(139,92,246,.22);overflow:hidden;color:#f7f7fb;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
            <div style="display:flex;align-items:center;gap:12px;padding:18px 20px;border-bottom:1px solid rgba(139,92,246,.22);background:rgba(255,255,255,.025);">
                <div style="width:40px;height:40px;border-radius:12px;border:1px solid rgba(239,68,68,.48);background:#0b0b10;box-shadow:inset 0 0 18px rgba(239,68,68,.12),0 0 24px rgba(239,68,68,.18);display:grid;place-items:center;">
                    <div style="width:10px;height:10px;border-radius:999px;background:#ef4444;box-shadow:0 0 18px rgba(239,68,68,.8);"></div>
                </div>
                <div>
                    <div style="font-size:15px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;">I got clearance error</div>
                    <div style="margin-top:2px;color:#a6a6b8;font-size:12px;letter-spacing:.12em;text-transform:uppercase;">DANEX X EL7 Session Recovery</div>
                </div>
            </div>
            <div style="padding:22px 20px 20px;">
                <p style="margin:0 0 12px;color:#ddddef;line-height:1.55;">The same IP hit the clearance check three times in under 30 minutes. Reset clearance will remove the stale session binding and start a clean challenge.</p>
                <p data-reason style="margin:0;color:#a6a6b8;font-size:12px;line-height:1.5;"></p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:20px;">
                    <button data-action="reset" type="button" style="min-height:46px;border:1px solid rgba(255,255,255,.16);border-radius:12px;background:#8b5cf6;color:#fff;font-weight:900;letter-spacing:.05em;text-transform:uppercase;cursor:pointer;box-shadow:0 0 28px rgba(139,92,246,.34);">Reset Clearance</button>
                    <button data-action="challenge" type="button" style="min-height:46px;border:1px solid rgba(139,92,246,.34);border-radius:12px;background:#0b0b10;color:#f7f7fb;font-weight:900;letter-spacing:.05em;text-transform:uppercase;cursor:pointer;">Open Challenge</button>
                </div>
            </div>
        </div>
    `;

    const reasonNode = overlay.querySelector<HTMLElement>('[data-reason]');
    if (reasonNode && reason) {
        reasonNode.textContent = `Reason: ${reason}`;
    }

    overlay.querySelector<HTMLButtonElement>('[data-action="reset"]')?.addEventListener('click', () => {
        window.location.assign(resetUrl || challengeUrl);
    });
    overlay.querySelector<HTMLButtonElement>('[data-action="challenge"]')?.addEventListener('click', () => {
        window.location.assign(challengeUrl || resetUrl);
    });

    document.body.appendChild(overlay);
};

export const setupInterceptors = (history: History) => {
    http.interceptors.response.use(
        (resp) => resp,
        async (error: AxiosError) => {
            const status = error.response?.status || 0;
            const original = (error.config || {}) as any;
            const data = (error.response?.data || {}) as Record<string, any>;

            if (status === 403 && data?.error === 'session_binding_mismatch' && typeof data?.challenge_url === 'string') {
                const challengeUrl = data.challenge_url.trim();
                const resetUrl = typeof data.clearance_reset_url === 'string' ? data.clearance_reset_url.trim() : '';
                const reason = typeof data.reason === 'string' ? data.reason : undefined;
                if (data.show_clearance_reset && resetUrl.length > 0) {
                    showClearanceResetOverlay(resetUrl, challengeUrl, reason);
                    return new Promise(() => undefined);
                }

                if (challengeUrl.length > 0) {
                    window.location.assign(challengeUrl);
                    return new Promise(() => undefined);
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
