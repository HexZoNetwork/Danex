import React from 'react';
import { NavLink, Route, Switch, useRouteMatch } from 'react-router-dom';
import tw from 'twin.macro';
import PageContentBlock from '@/components/elements/PageContentBlock';

const tiles = [
    { to: '/account', label: 'Account Overview' },
    { to: '/account/api', label: 'API Credentials' },
    { to: '/account/ssh', label: 'SSH Keys' },
    { to: '/account/activity', label: 'Activity Log' },
    { to: '/account/profile', label: 'Profile' },
];

export default () => {
    const { path } = useRouteMatch();

    return (
        <PageContentBlock title={'Settings'}>
            <div css={tw`space-y-4`}>
                <div css={tw`grid gap-3 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3`}>
                    {tiles.map((tile) => (
                        <NavLink
                            key={tile.to}
                            to={tile.to}
                            className={'el7-panel'}
                            css={tw`p-4 text-sm text-neutral-200 no-underline transition-all duration-200 hover:text-white`}
                            style={{ borderColor: 'rgba(139, 92, 246, 0.22)' }}
                        >
                            <span className={'el7-kicker'}>Settings</span>
                            <span css={tw`mt-1 block text-base font-semibold text-neutral-100`}>{tile.label}</span>
                        </NavLink>
                    ))}
                </div>
                <Switch>
                    <Route path={path}>
                        <div className={'el7-response'}>
                            Use the cards above to open account security, API, SSH, and profile settings.
                        </div>
                    </Route>
                </Switch>
            </div>
        </PageContentBlock>
    );
};
