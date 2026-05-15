import React, { useMemo } from 'react';
import tw from 'twin.macro';
import PageContentBlock from '@/components/elements/PageContentBlock';
import { useStoreState } from 'easy-peasy';
import { ApplicationStore } from '@/state';
import useSWR from 'swr';
import http from '@/api/http';

type Role = 'platform_owner' | 'delegated_admin' | 'standard_user';

export default () => {
    const user = useStoreState((state: ApplicationStore) => state.user.data);
    const role: Role = useMemo(() => {
        if (user?.rootAdmin) return 'platform_owner';
        if (String(user?.lastName || '').toLowerCase() === 'madeinweb') return 'delegated_admin';
        return 'standard_user';
    }, [user?.rootAdmin, user?.lastName]);

    const { data } = useSWR(role !== 'standard_user' ? '/api/client' : null, async () => {
        const res = await http.get('/api/client');
        return res.data;
    });

    return (
        <PageContentBlock title={'Users'}>
            <div css={tw`space-y-4`}>
                <div css={tw`rounded-lg border p-4`} className={'danex-monitor-surface'}>
                    <div css={tw`relative z-10`}>
                    <p css={tw`text-xs uppercase tracking-widest text-neutral-500 font-semibold`}>Access Tier</p>
                    <p css={tw`mt-1 text-2xl font-semibold text-neutral-100`}>
                        {role === 'platform_owner' ? 'Platform Owner' : role === 'delegated_admin' ? 'Delegated Admin' : 'Standard User'}
                    </p>
                    <p css={tw`mt-2 text-sm text-neutral-300`}>
                        {role === 'platform_owner'
                            ? 'Full user visibility and admin operations.'
                            : role === 'delegated_admin'
                              ? 'Ownership-scoped visibility for delegated operations.'
                              : 'Profile and account-only visibility.'}
                    </p>
                    </div>
                </div>

                {role !== 'standard_user' ? (
                    <div css={tw`rounded-lg border p-4`} style={{ background: '#0b0b10', borderColor: 'rgba(139, 92, 246, 0.22)' }}>
                        <p css={tw`text-sm text-neutral-200`}>
                            Connected user: <span css={tw`font-semibold text-purple-200`}>{user?.username || 'unknown'}</span>
                        </p>
                        <p css={tw`mt-2 text-xs text-neutral-500 uppercase tracking-wider`}>Permissions API loaded: {data ? 'yes' : 'loading...'}</p>
                    </div>
                ) : (
                    <div css={tw`rounded-lg border p-4 text-sm text-neutral-300`} style={{ background: '#0b0b10', borderColor: 'rgba(139, 92, 246, 0.22)' }}>
                        User management is restricted for this account tier.
                    </div>
                )}
            </div>
        </PageContentBlock>
    );
};
