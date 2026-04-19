import React, { lazy, useEffect } from 'react';
import { Route, Switch, useRouteMatch } from 'react-router-dom';
import { NotFound } from '@/components/elements/ScreenBlock';
import { useHistory, useLocation } from 'react-router';
import Spinner from '@/components/elements/Spinner';

const LoginContainer = lazy(() => import('@/components/auth/LoginContainer'));
const ForgotPasswordContainer = lazy(() => import('@/components/auth/ForgotPasswordContainer'));
const ResetPasswordContainer = lazy(() => import('@/components/auth/ResetPasswordContainer'));
const LoginCheckpointContainer = lazy(() => import('@/components/auth/LoginCheckpointContainer'));
const RegisterContainer = lazy(() => import('@/components/auth/RegisterContainer'));

export default () => {
    const history = useHistory();
    const location = useLocation();
    const { path } = useRouteMatch();

    useEffect(() => {
        const preload = document.createElement('link');
        preload.rel = 'preload';
        preload.as = 'image';
        preload.href = '/assets/svgs/pterodactyl.svg';
        document.head.appendChild(preload);

        return () => {
            document.head.removeChild(preload);
        };
    }, []);

    return (
        <div className={'pt-8 xl:pt-32'}>
            <React.Suspense fallback={<Spinner centered />}>
                <Switch location={location}>
                    <Route path={`${path}/login`} component={LoginContainer} exact />
                    <Route path={`${path}/register`} component={RegisterContainer} exact />
                    <Route path={`${path}/login/checkpoint`} component={LoginCheckpointContainer} />
                    <Route path={`${path}/password`} component={ForgotPasswordContainer} exact />
                    <Route path={`${path}/password/reset/:token`} component={ResetPasswordContainer} />
                    <Route path={`${path}/checkpoint`} />
                    <Route path={'*'}>
                        <NotFound onBack={() => history.push('/auth/login')} />
                    </Route>
                </Switch>
            </React.Suspense>
        </div>
    );
};
