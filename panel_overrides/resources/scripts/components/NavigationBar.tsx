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
import { getChatNotifications } from '@/api/chat/publicChat';
import SpinnerOverlay from '@/components/elements/SpinnerOverlay';
import Tooltip from '@/components/elements/tooltip/Tooltip';
import Avatar from '@/components/Avatar';

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

    &::after {
        content: '';
        display: block;
        height: 1px;
        background: linear-gradient(90deg, transparent, rgba(139, 92, 246, 0.7), transparent);
        opacity: 0.72;
        transform-origin: center;
        animation: nav-scan-line 3.6s ease-in-out infinite;
    }

    @keyframes nav-scan-line {
        0%, 100% { transform: scaleX(0.18); opacity: 0.28; }
        50% { transform: scaleX(0.92); opacity: 0.86; }
    }
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

const NotificationBadge = styled.span`
    ${tw`absolute flex items-center justify-center text-[10px] font-bold text-white rounded-full`};
    top: 0.68rem;
    right: 0.46rem;
    min-width: 1.1rem;
    height: 1.1rem;
    padding: 0 0.28rem;
    background: #ef4444;
    border: 1px solid rgba(255, 255, 255, 0.22);
    box-shadow: 0 0 18px rgba(239, 68, 68, 0.48);
    pointer-events: none;
`;

export default () => {
    const name = useStoreState((state: ApplicationStore) => state.settings.data!.name);
    const rootAdmin = useStoreState((state: ApplicationStore) => state.user.data!.rootAdmin);
    const [isLoggingOut, setIsLoggingOut] = useState(false);
    const [unreadNotifications, setUnreadNotifications] = useState(0);
    const isMounted = useRef(true);

    const onTriggerLogout = () => {
        setIsLoggingOut(true);
        http.post('/auth/logout').finally(() => {
            // @ts-expect-error this is valid
            window.location = '/';
        });
    };

    useEffect(() => {
        isMounted.current = true;

        const refreshNotifications = () => {
            getChatNotifications(undefined, 1)
                .then((result) => {
                    if (isMounted.current) {
                        setUnreadNotifications(Math.max(0, result.unreadCount || 0));
                    }
                })
                .catch(() => {
                    // Keep the nav usable if notifications fail.
                });
        };

        refreshNotifications();
        const timer = window.setInterval(refreshNotifications, 30000);
        const onFocus = () => refreshNotifications();
        window.addEventListener('focus', onFocus);

        return () => {
            isMounted.current = false;
            window.clearInterval(timer);
            window.removeEventListener('focus', onFocus);
        };
    }, []);

    return (
        <Shell>
            <SpinnerOverlay visible={isLoggingOut} />
            <div className={'mx-auto w-full grid grid-cols-[1fr_auto_1fr] items-center h-[3.55rem] sm:h-[3.75rem] max-w-[1220px] min-w-0'}>
                <div className={'min-w-0 flex items-center'}>
                    <React.Suspense fallback={null}>
                        <SearchContainer />
                    </React.Suspense>
                </div>
                <div id={'logo'} className={'min-w-0 text-center'}>
                    <Brand to={'/'}>
                        DANEX <span>X EL7</span>
                        <span className={'sr-only'}>{name}</span>
                    </Brand>
                </div>
                <RightNavigation className={'flex h-full items-center justify-end'}>
                    {rootAdmin && (
                        <Tooltip placement={'bottom'} content={'Admin'}>
                            <a href={'/admin'} rel={'noreferrer'} aria-label={'Admin'}>
                                <FontAwesomeIcon icon={faCogs} />
                            </a>
                        </Tooltip>
                    )}
                    <Tooltip placement={'bottom'} content={'Notifications'}>
                        <NavLink to={'/notifications'} exact className={'relative'} aria-label={'Notifications'}>
                            <FontAwesomeIcon icon={faBell} />
                            {unreadNotifications > 0 && (
                                <NotificationBadge aria-live={'polite'} aria-label={`${unreadNotifications} unread notifications`}>
                                    {unreadNotifications > 99 ? '99+' : unreadNotifications}
                                </NotificationBadge>
                            )}
                        </NavLink>
                    </Tooltip>
                    <Tooltip placement={'bottom'} content={'Account Settings'}>
                        <NavLink to={'/account'} aria-label={'Account Settings'}>
                            <span className={'flex items-center w-5 h-5'}>
                                <Avatar.User />
                            </span>
                        </NavLink>
                    </Tooltip>
                    <Tooltip placement={'bottom'} content={'Sign Out'}>
                        <button onClick={onTriggerLogout} aria-label={'Sign Out'}>
                            <FontAwesomeIcon icon={faSignOutAlt} />
                        </button>
                    </Tooltip>
                </RightNavigation>
            </div>
        </Shell>
    );
};
