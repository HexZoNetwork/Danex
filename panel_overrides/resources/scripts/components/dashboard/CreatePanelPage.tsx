import React, { useEffect, useMemo, useState } from 'react';
import tw from 'twin.macro';
import PageContentBlock from '@/components/elements/PageContentBlock';
import Button from '@/components/elements/Button';
import { createMadeinwebPanel, getCreatePanelOptions } from '@/api/createPanel';
import { httpErrorToHuman } from '@/api/http';
import { useStoreActions, useStoreState } from 'easy-peasy';
import { ApplicationStore } from '@/state';

const panelStyle: React.CSSProperties = {
    background: '#0b0b10',
    borderColor: 'rgba(139, 92, 246, 0.24)',
    boxShadow: '0 18px 48px rgba(0, 0, 0, 0.5)',
};

const insetPanelStyle: React.CSSProperties = {
    background: '#111117',
    borderColor: 'rgba(139, 92, 246, 0.18)',
};

export default () => {
    const user = useStoreState((state: ApplicationStore) => state.user.data);
    const updateUserData = useStoreActions((actions: any) => actions.user.updateUserData);
    const [loading, setLoading] = useState(true);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState('');
    const [success, setSuccess] = useState('');
    const [created, setCreated] = useState(false);
    const [eggs, setEggs] = useState<Array<{ id: number; name: string; description: string }>>([]);
    const [ramOptions, setRamOptions] = useState<number[]>([1024, 2048, 4096, 8192]);
    const [cpuFixed, setCpuFixed] = useState(100);
    const [threadsFixed, setThreadsFixed] = useState('1');
    const [name, setName] = useState('');
    const [eggId, setEggId] = useState<number | null>(null);
    const [ram, setRam] = useState<number>(1024);

    const allowed = useMemo(() => String(user?.lastName || '').toLowerCase() === 'madeinweb', [user?.lastName]);

    useEffect(() => {
        let cancelled = false;
        (async () => {
            setLoading(true);
            setError('');
            try {
                const response = await getCreatePanelOptions();
                if (cancelled) return;
                setCreated(Boolean(response.created));
                setEggs(Array.isArray(response.eggs) ? response.eggs : []);
                setRamOptions(Array.isArray(response.ram_options) && response.ram_options.length > 0 ? response.ram_options : [1024]);
                setCpuFixed(Number(response.fixed?.cpu || 100));
                setThreadsFixed(String(response.fixed?.threads || '1'));
                const firstEgg = Array.isArray(response.eggs) && response.eggs.length > 0 ? Number(response.eggs[0].id) : null;
                setEggId(firstEgg);
                const firstRam =
                    Array.isArray(response.ram_options) && response.ram_options.length > 0 ? Number(response.ram_options[0]) : 1024;
                setRam(firstRam);
            } catch (e) {
                if (!cancelled) {
                    setError(httpErrorToHuman(e));
                }
            } finally {
                if (!cancelled) setLoading(false);
            }
        })();

        return () => {
            cancelled = true;
        };
    }, []);

    if (!allowed) {
        return (
            <PageContentBlock title={'Create Server Panel'} showFlashKey={'dashboard'}>
                <div css={tw`rounded-lg border p-4 text-neutral-300`} style={panelStyle}>
                    Your account is not eligible for one-time server panel creation yet.
                </div>
            </PageContentBlock>
        );
    }

    return (
        <PageContentBlock title={'Create Server Panel'} showFlashKey={'dashboard'}>
            <div css={tw`mx-auto max-w-3xl space-y-4`}>
                <div className={'el7-panel'} css={tw`p-4 sm:p-5`} style={panelStyle}>
                    <p className={'el7-kicker'}>One-time provisioning</p>
                    <h2 css={tw`mt-1 text-xl font-semibold text-neutral-100`}>Create Server Panel</h2>
                    <p className={'el7-helper'} css={tw`mt-2`}>
                        Your account is eligible for one free server panel. This action can only be used once.
                    </p>
                </div>

                <div className={'el7-form-panel'} css={tw`p-4 sm:p-5 space-y-4`} style={panelStyle}>
                    {loading ? (
                        <p className={'el7-response'}>Memuat opsi...</p>
                    ) : created ? (
                        <p className={'el7-response el7-response-success'}>Create Panel sudah dipakai untuk akun ini.</p>
                    ) : (
                        <>
                            <div css={tw`grid gap-4 sm:grid-cols-2`}>
                                <div css={tw`sm:col-span-2`}>
                                    <label htmlFor={'panel-name'} css={tw`text-sm text-neutral-300`}>
                                        Nama Server
                                    </label>
                                    <input
                                        id={'panel-name'}
                                        value={name}
                                        onChange={(e) => setName(e.currentTarget.value)}
                                        css={tw`mt-1 w-full px-3 py-2 text-neutral-100`}
                                        placeholder={'Bebas, contoh: My First Panel'}
                                    />
                                    <p className={'el7-helper'} css={tw`mt-1`}>Use a clear name so it is easy to find on the Servers page.</p>
                                </div>

                                <div>
                                    <label htmlFor={'panel-egg'} css={tw`text-sm text-neutral-300`}>
                                        Egg
                                    </label>
                                    <select
                                        id={'panel-egg'}
                                        value={eggId ?? ''}
                                        onChange={(e) => setEggId(Number(e.currentTarget.value))}
                                        css={tw`mt-1 w-full px-3 py-2 text-neutral-100`}
                                    >
                                        {eggs.map((egg) => (
                                            <option key={egg.id} value={egg.id}>
                                                {egg.name}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div>
                                    <label htmlFor={'panel-ram'} css={tw`text-sm text-neutral-300`}>
                                        RAM
                                    </label>
                                    <select
                                        id={'panel-ram'}
                                        value={ram}
                                        onChange={(e) => setRam(Number(e.currentTarget.value))}
                                        css={tw`mt-1 w-full px-3 py-2 text-neutral-100`}
                                    >
                                        {ramOptions.map((value) => (
                                            <option key={value} value={value}>
                                                {value} MB
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            </div>

                            <div css={tw`rounded-lg border px-3 py-2 text-sm text-neutral-300`} style={insetPanelStyle}>
                                CPU fixed: <strong>{cpuFixed}%</strong> | Threads pin: <strong>{threadsFixed}</strong>
                                <br />
                                Review the server name, egg, and RAM before creating. The server will appear on the Servers page.
                            </div>

                            <Button
                                type={'button'}
                                disabled={submitting || !name.trim() || !eggId}
                                isLoading={submitting}
                                onClick={async () => {
                                    setSubmitting(true);
                                    setError('');
                                    setSuccess('');
                                    try {
                                        await createMadeinwebPanel({
                                            name: name.trim(),
                                            egg_id: Number(eggId),
                                            ram: Number(ram),
                                        });
                                        setCreated(true);
                                        setSuccess('Server created. Open Servers to manage it.');
                                        updateUserData({ madeinwebPanelCreatedAt: new Date().toISOString() });
                                    } catch (e) {
                                        setError(httpErrorToHuman(e));
                                    } finally {
                                        setSubmitting(false);
                                    }
                                }}
                            >
                                Create Server Panel
                            </Button>
                        </>
                    )}

                    {error && <p className={'el7-response el7-response-error'}>{error}</p>}
                    {success && <p className={'el7-response el7-response-success'}>{success}</p>}
                </div>
            </div>
        </PageContentBlock>
    );
};
