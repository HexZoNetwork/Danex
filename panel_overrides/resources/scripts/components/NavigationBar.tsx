import * as React from 'react';
import { lazy, useEffect, useRef, useState } from 'react';
import { Link, NavLink } from 'react-router-dom';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faBell, faCogs, faSignOutAlt } from '@fortawesome/free-solid-svg-icons';
import { useStoreState } from 'easy-peasy';
import { ApplicationStore } from '@/state';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
import http from '@/api/http';
import SpinnerOverlay from '@/components/elements/SpinnerOverlay';
import Tooltip from '@/components/elements/tooltip/Tooltip';
import Avatar from '@/components/Avatar';
import { getChatNotifications } from '@/api/chat/publicChat';

const SearchContainer = lazy(() => import('@/components/dashboard/search/SearchContainer'));

const RightNavigation = styled.div`
    & > a,
    & > button,
    & > .navigation-link {
        ${tw`flex items-center justify-center h-full no-underline text-neutral-300 px-3 sm:px-4 cursor-pointer transition-all duration-150`};
        border-left: 1px solid rgba(139, 92, 246, 0.08);
        min-width: 3rem;

        &:active,
        &:hover {
            ${tw`text-neutral-100`};
            background: rgba(139, 92, 246, 0.12);
        }

        &:active,
        &:hover,
        &.active {
            box-shadow: inset 0 -2px #8b5cf6, 0 0 22px rgba(139, 92, 246, 0.22);
        }
    }

    @media (max-width: 520px) {
        & > a,
        & > button,
        & > .navigation-link {
            min-width: 2.6rem;
            padding-left: 0.65rem;
            padding-right: 0.65rem;
        }
    }
`;

const Shell = styled.div`
    ${tw`w-full overflow-x-hidden`};
    background: rgba(11, 11, 16, 0.94);
    border-bottom: 1px solid rgba(139, 92, 246, 0.32);
    box-shadow: 0 14px 38px rgba(0, 0, 0, 0.5), 0 0 24px rgba(139, 92, 246, 0.12);
    backdrop-filter: blur(16px);
    animation: danex-fade-up 300ms var(--el7-ease) both;
`;

const Brand = styled(Link)`
    ${tw`block truncate text-base sm:text-xl font-header font-semibold px-3 sm:px-4 no-underline transition-colors duration-150`};
    color: #f7f3ff;
    letter-spacing: 0;
    text-shadow: 0 0 20px rgba(139, 92, 246, 0.38);

    &:hover {
        color: #ffffff;
    }

    span {
        color: #a78bfa;
    }

    @media (max-width: 420px) {
        font-size: 0.88rem;
        padding-left: 0.75rem;
        padding-right: 0.5rem;
    }
`;

export default () => {
    const name = useStoreState((state: ApplicationStore) => state.settings.data!.name);
    const rootAdmin = useStoreState((state: ApplicationStore) => state.user.data!.rootAdmin);
    const [isLoggingOut, setIsLoggingOut] = useState(false);
    const [unreadNotifications, setUnreadNotifications] = useState(0);
    const notificationSinceRef = useRef(0);

    useEffect(() => {
        let cancelled = false;
        const tick = async () => {
            if (document.visibilityState !== 'visible') return;
            try {
                const result = await getChatNotifications(notificationSinceRef.current || undefined, 20);
                if (cancelled) return;
                notificationSinceRef.current = Math.max(notificationSinceRef.current, result.lastNotificationId || 0);
                setUnreadNotifications(result.unreadCount || 0);
            } catch {
                // silent
            }
        };

        const firstTimer = window.setTimeout(() => {
            void tick();
        }, 3000);
        const timer = window.setInterval(tick, 45000);

        return () => {
            cancelled = true;
            window.clearTimeout(firstTimer);
            window.clearInterval(timer);
        };
    }, []);

    const onTriggerLogout = () => {
        setIsLoggingOut(true);
        http.post('/auth/logout').finally(() => {
            // @ts-expect-error this is valid
            window.location = '/';
        });
    };

    return (
        <Shell>
            <SpinnerOverlay visible={isLoggingOut} />
            <div className={'mx-auto w-full flex items-center h-[3.55rem] sm:h-[3.75rem] max-w-[1220px] min-w-0'}>
                <div id={'logo'} className={'flex-1 min-w-0'}>
                    <Brand to={'/'}>
                        DANEX <span>X EL7</span>
                        <span className={'sr-only'}>{name}</span>
                    </Brand>
                </div>
                <RightNavigation className={'flex h-full items-center justify-center'}>
                    <React.Suspense fallback={null}>
                        <SearchContainer />
                    </React.Suspense>
                    <Tooltip placement={'bottom'} content={'Notifications'}>
                        <NavLink to={'/notifications'} exact>
                            <span className={'relative inline-flex items-center justify-center'}>
                                <FontAwesomeIcon icon={faBell} />
                                {unreadNotifications > 0 && (
                                    <span
                                        className={
                                            'absolute -top-1 -right-1 min-w-[14px] h-[14px] px-1 rounded-full bg-red-500 text-white text-[9px] leading-[14px] text-center font-semibold ring-1 ring-neutral-900'
                                        }
                                    >
                                        {unreadNotifications > 99 ? '99+' : unreadNotifications}
                                    </span>
                                )}
                            </span>
                        </NavLink>
                    </Tooltip>
                    {rootAdmin && (
                        <Tooltip placement={'bottom'} content={'Admin'}>
                            <a href={'/admin'} rel={'noreferrer'}>
                                <FontAwesomeIcon icon={faCogs} />
                            </a>
                        </Tooltip>
                    )}
                    <Tooltip placement={'bottom'} content={'Account Settings'}>
                        <NavLink to={'/account'}>
                            <span className={'flex items-center w-5 h-5'}>
                                <Avatar.User />
                            </span>
                        </NavLink>
                    </Tooltip>
                    <Tooltip placement={'bottom'} content={'Sign Out'}>
                        <button onClick={onTriggerLogout}>
                            <FontAwesomeIcon icon={faSignOutAlt} />
                        </button>
                    </Tooltip>
                </RightNavigation>
            </div>
        </Shell>
    );
};
