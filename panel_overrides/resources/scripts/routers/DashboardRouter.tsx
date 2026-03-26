import React, { useEffect, useRef, useState } from 'react';
import { NavLink, Route, Switch } from 'react-router-dom';
import NavigationBar from '@/components/NavigationBar';
import DashboardContainer from '@/components/dashboard/DashboardContainer';
import PublicChatPage from '@/components/dashboard/PublicChatPage';
import DanexCoinPage from '@/components/dashboard/DanexCoinPage';
import NotificationsPage from '@/components/dashboard/NotificationsPage';
import CreatePanelPage from '@/components/dashboard/CreatePanelPage';
import { NotFound } from '@/components/elements/ScreenBlock';
import TransitionRouter from '@/TransitionRouter';
import SubNavigation from '@/components/elements/SubNavigation';
import { useLocation } from 'react-router';
import Spinner from '@/components/elements/Spinner';
import routes from '@/routers/routes';
import AccountProfileContainer from '@/components/dashboard/AccountProfileContainer';
import { getChatNotifications } from '@/api/chat/publicChat';
import { useStoreState } from 'easy-peasy';
import { ApplicationStore } from '@/state';

export default () => {
    const location = useLocation();
    const user = useStoreState((state: ApplicationStore) => state.user.data);
    const canCreatePanel = String(user?.lastName || '').toLowerCase() === 'madeinweb';
    const notificationSinceRef = useRef(0);
    const notificationBootedRef = useRef(false);
    const shownRef = useRef<Set<number>>(new Set());

    useEffect(() => {
        let cancelled = false;
        const tick = async () => {
            try {
                const result = await getChatNotifications(notificationSinceRef.current || undefined, 80);
                if (cancelled) return;
                notificationSinceRef.current = Math.max(notificationSinceRef.current, result.lastNotificationId || 0);

                if (!notificationBootedRef.current) {
                    notificationBootedRef.current = true;
                    return;
                }
                for (const item of result.items) {
                    if (item.read || shownRef.current.has(item.id)) continue;
                    shownRef.current.add(item.id);
                    if (typeof window === 'undefined' || !('Notification' in window)) continue;
                    if (Notification.permission !== 'granted') continue;
                    try {
                        const n = new Notification(item.title || 'Notification', {
                            body: item.body || '',
                            icon: item.sourceType === 'system' ? undefined : item.avatarUrl || undefined,
                            tag: `chat-notif-${item.id}`,
                            renotify: false,
                        });
                        n.onclick = () => {
                            if (item.conversationId) {
                                window.location.href = `/chat?conversation=${item.conversationId}`;
                            } else {
                                window.location.href = '/notifications';
                            }
                        };
                        window.setTimeout(() => n.close(), 7000);
                    } catch {
                        // ignore browser notification errors
                    }
                }
            } catch {
                // silent
            }
        };

        if (typeof window !== 'undefined' && 'Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission().catch(() => {
                // ignore
            });
        }

        tick();
        const timer = window.setInterval(tick, 3500);

        return () => {
            cancelled = true;
            window.clearInterval(timer);
        };
    }, []);

    return (
        <>
            <NavigationBar />
            {!location.pathname.startsWith('/account') && (
                <SubNavigation>
                    <div>
                        <NavLink to={'/'} exact>
                            Dashboard
                        </NavLink>
                        <NavLink to={'/chat'} exact>
                            Public Chat
                        </NavLink>
                        <NavLink to={'/judi'} exact>
                            Judi
                        </NavLink>
                        {canCreatePanel && (
                            <NavLink to={'/create-panel'} exact>
                                Create Panel
                            </NavLink>
                        )}
                    </div>
                </SubNavigation>
            )}
            {location.pathname.startsWith('/account') && (
                <SubNavigation>
                    <div>
                        {routes.account
                            .filter((route) => !!route.name)
                            .map(({ path, name, exact = false }) => (
                                <NavLink key={path} to={`/account/${path}`.replace('//', '/')} exact={exact}>
                                    {name}
                                </NavLink>
                            ))}
                        <NavLink to={'/account/profile'} exact>
                            Profile
                        </NavLink>
                    </div>
                </SubNavigation>
            )}
            <TransitionRouter>
                <React.Suspense fallback={<Spinner centered />}>
                    <Switch location={location}>
                        <Route path={'/'} exact>
                            <DashboardContainer />
                        </Route>
                        <Route path={'/chat'} exact>
                            <PublicChatPage />
                        </Route>
                        <Route path={'/judi'} exact>
                            <DanexCoinPage />
                        </Route>
                        <Route path={'/create-panel'} exact>
                            <CreatePanelPage />
                        </Route>
                        <Route path={'/notifications'} exact>
                            <NotificationsPage />
                        </Route>
                        {routes.account.map(({ path, component: Component }) => (
                            <Route key={path} path={`/account/${path}`.replace('//', '/')} exact>
                                <Component />
                            </Route>
                        ))}
                        <Route path={'/account/profile'} exact>
                            <AccountProfileContainer />
                        </Route>
                        <Route path={'*'}>
                            <NotFound />
                        </Route>
                    </Switch>
                </React.Suspense>
            </TransitionRouter>
        </>
    );
};
