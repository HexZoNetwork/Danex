import * as React from 'react';
import { lazy, useEffect, useRef, useState } from 'react';
import { Link, NavLink } from 'react-router-dom';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faBell, faCogs, faLayerGroup, faSignOutAlt } from '@fortawesome/free-solid-svg-icons';
import { useStoreState } from 'easy-peasy';
import { ApplicationStore } from '@/state';
import tw, { theme } from 'twin.macro';
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
        ${tw`flex items-center h-full no-underline text-neutral-300 px-2 sm:px-6 cursor-pointer transition-all duration-150`};

        &:active,
        &:hover {
            ${tw`text-neutral-100 bg-black`};
        }

        &:active,
        &:hover,
        &.active {
            box-shadow: inset 0 -2px ${theme`colors.cyan.600`.toString()};
        }
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
        <div className={'w-full bg-neutral-900 shadow-md overflow-x-hidden'}>
            <SpinnerOverlay visible={isLoggingOut} />
            <div className={'mx-auto w-full flex items-center h-[3.5rem] max-w-[1200px] min-w-0'}>
                <div id={'logo'} className={'flex-1 min-w-0'}>
                    <Link
                        to={'/'}
                        className={
                            'block truncate text-lg sm:text-2xl font-header font-medium px-3 sm:px-4 no-underline text-neutral-200 hover:text-neutral-100 transition-colors duration-150'
                        }
                    >
                        {name}
                    </Link>
                </div>
                <RightNavigation className={'flex h-full items-center justify-center'}>
                    <React.Suspense fallback={null}>
                        <SearchContainer />
                    </React.Suspense>
                    <Tooltip placement={'bottom'} content={'Dashboard'}>
                        <NavLink to={'/'} exact>
                            <FontAwesomeIcon icon={faLayerGroup} />
                        </NavLink>
                    </Tooltip>
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
        </div>
    );
};
