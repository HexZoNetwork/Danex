import React, { lazy, useEffect, useRef, useState } from 'react';
import { NavLink, Redirect, Route, Switch } from 'react-router-dom';
import NavigationBar from '@/components/NavigationBar';
import DashboardContainer from '@/components/dashboard/DashboardContainer';
import { NotFound } from '@/components/elements/ScreenBlock';
import TransitionRouter from '@/TransitionRouter';
import SubNavigation from '@/components/elements/SubNavigation';
import { useLocation } from 'react-router';
import Spinner from '@/components/elements/Spinner';
import accountRoutes from '@/routers/accountRoutes';
import { getChatNotifications, readChatNotifications } from '@/api/chat/publicChat';
import { useStoreState } from 'easy-peasy';
import { ApplicationStore } from '@/state';

const PublicChatPage = lazy(() => import('@/components/dashboard/PublicChatPage'));
const DanexCoinPage = lazy(() => import('@/components/dashboard/DanexCoinPage'));
const DanexCPage = lazy(() => import('@/components/dashboard/DanexCPage'));
const NotificationsPage = lazy(() => import('@/components/dashboard/NotificationsPage'));
const CreatePanelPage = lazy(() => import('@/components/dashboard/CreatePanelPage'));
const AccountProfileContainer = lazy(() => import('@/components/dashboard/AccountProfileContainer'));
const AdsSurface = lazy(() => import('@/components/dashboard/AdsSurface'));
const UsersPage = lazy(() => import('@/components/dashboard/UsersPage'));
const SettingsHubPage = lazy(() => import('@/components/dashboard/SettingsHubPage'));

export default () => {
    const location = useLocation();
    const user = useStoreState((state: ApplicationStore) => state.user.data);
    const canCreatePanel = String(user?.lastName || '').toLowerCase() === 'madeinweb';
    const notificationSinceRef = useRef(0);
    const notificationBootedRef = useRef(false);
    const shownRef = useRef<Set<number>>(new Set());
    const [showAds, setShowAds] = useState(false);

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
                            const target = item.conversationId ? `/chat?conversation=${item.conversationId}` : '/notifications';
                            readChatNotifications([item.id])
                                .catch(() => {
                                    // notification click should still navigate if the read marker fails
                                })
                                .finally(() => {
                                    window.location.href = target;
                                });
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

        const scheduleTick = () => {
            if (document.visibilityState !== 'visible') return;
            void tick();
        };

        const firstTimer = window.setTimeout(scheduleTick, 4000);
        const timer = window.setInterval(scheduleTick, 30000);

        return () => {
            cancelled = true;
            window.clearTimeout(firstTimer);
            window.clearInterval(timer);
        };
    }, []);

    useEffect(() => {
        const enable = () => setShowAds(true);
        if ('requestIdleCallback' in window) {
            (window as any).requestIdleCallback(enable, { timeout: 10000 });
            return undefined;
        }
        const timer = globalThis.setTimeout(enable, 7000);
        return () => globalThis.clearTimeout(timer);
    }, []);

    const isAccount = location.pathname.startsWith('/account');

    return (
        <>
            <NavigationBar />
            {!isAccount && (
                <SubNavigation>
                    <div>
                        <NavLink to={'/dashboard'} exact>Danex Security</NavLink>
                        <NavLink to={'/servers'} exact>Servers</NavLink>
                        <NavLink to={'/chat'} exact>Chat</NavLink>
                        <NavLink to={'/danexcoin'} exact>DanexCoin</NavLink>
                        {canCreatePanel && <NavLink to={'/create-panel'} exact>Create Panel</NavLink>}
                    </div>
                </SubNavigation>
            )}
            {isAccount && (
                <SubNavigation>
                    <div>
                        {accountRoutes
                            .filter((route) => !!route.name)
                            .map(({ path, name, exact = false }) => (
                                <NavLink key={path} to={`/account/${path}`.replace('//', '/')} exact={exact}>
                                    {name}
                                </NavLink>
                            ))}
                        <NavLink to={'/account/profile'} exact>Profile</NavLink>
                    </div>
                </SubNavigation>
            )}
            {showAds && (
                <React.Suspense fallback={null}>
                    <AdsSurface />
                </React.Suspense>
            )}
            <TransitionRouter variant={isAccount ? 'default' : 'dashboard'}>
                <React.Suspense fallback={<Spinner centered />}>
                    <div className={'el7-route-shell'}>
                        <Switch location={location}>
                            <Route path={'/'} exact><DashboardContainer /></Route>
                            <Route path={'/dashboard'} exact><DanexCPage /></Route>
                            <Route path={'/servers'} exact><DashboardContainer /></Route>
                            <Route path={'/users'} exact><UsersPage /></Route>
                            <Route path={'/settings'} exact><SettingsHubPage /></Route>
                            <Route path={'/chat'} exact><PublicChatPage /></Route>
                            <Route path={'/danexcoin'} exact><DanexCoinPage /></Route>
                            <Route path={'/judi'} exact><Redirect to={'/danexcoin'} /></Route>
                            <Route path={'/danexc'} exact><Redirect to={'/dashboard'} /></Route>
                            <Route path={'/create-panel'} exact><CreatePanelPage /></Route>
                            <Route path={'/notifications'} exact><NotificationsPage /></Route>
                            {accountRoutes.map(({ path, component: Component }) => (
                                <Route key={path} path={`/account/${path}`.replace('//', '/')} exact>
                                    <Component />
                                </Route>
                            ))}
                            <Route path={'/account/profile'} exact><AccountProfileContainer /></Route>
                            <Route path={'*'}><NotFound /></Route>
                        </Switch>
                    </div>
                </React.Suspense>
            </TransitionRouter>
        </>
    );
};
