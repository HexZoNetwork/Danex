import React from 'react';
import tw from 'twin.macro';
import Icon from '@/components/elements/Icon';
import { faExclamationTriangle } from '@fortawesome/free-solid-svg-icons';

interface State {
    hasError: boolean;
}

// eslint-disable-next-line @typescript-eslint/ban-types
class ErrorBoundary extends React.Component<{}, State> {
    state: State = {
        hasError: false,
    };

    static getDerivedStateFromError() {
        return { hasError: true };
    }

    componentDidCatch(error: Error) {
        console.error(error);

        const message = String(error?.message || error || '');
        if (/ChunkLoadError|Loading chunk .* failed/i.test(message) && typeof window !== 'undefined') {
            const key = 'pterodactyl:chunk-recovery';
            const last = Number(window.sessionStorage.getItem(key) || 0);
            const now = Date.now();

            if (!last || now - last > 30000) {
                window.sessionStorage.setItem(key, String(now));
                const url = new URL(window.location.href);
                url.searchParams.set('_asset_retry', String(now));
                window.location.replace(url.toString());
            }
        }
    }

    render() {
        return this.state.hasError ? (
            <div css={tw`flex items-center justify-center w-full my-4`}>
                <div css={tw`flex items-center bg-neutral-900 rounded p-3 text-red-500`}>
                    <Icon icon={faExclamationTriangle} css={tw`h-4 w-auto mr-2`} />
                    <p css={tw`text-sm text-neutral-100`}>
                        An error was encountered by the application while rendering this view. Try refreshing the page.
                    </p>
                </div>
            </div>
        ) : (
            this.props.children
        );
    }
}

export default ErrorBoundary;
