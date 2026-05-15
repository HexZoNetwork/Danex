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
                            css={tw`rounded-lg border p-4 text-sm text-neutral-200 no-underline transition-all duration-200 hover:text-white`}
                            style={{ background: '#0b0b10', borderColor: 'rgba(139, 92, 246, 0.22)' }}
                        >
                            {tile.label}
                        </NavLink>
                    ))}
                </div>
                <Switch>
                    <Route path={path}>
                        <div css={tw`rounded-lg border p-4 text-sm text-neutral-300`} style={{ background: '#0b0b10', borderColor: 'rgba(139, 92, 246, 0.22)' }}>
                            Use the cards above to open account security, API, SSH, and profile settings.
                        </div>
                    </Route>
                </Switch>
            </div>
        </PageContentBlock>
    );
};
