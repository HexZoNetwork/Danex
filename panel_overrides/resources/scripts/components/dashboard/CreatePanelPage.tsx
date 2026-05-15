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
            <PageContentBlock title={'Create Panel'} showFlashKey={'dashboard'}>
                <div css={tw`rounded-lg border p-4 text-neutral-300`} style={panelStyle}>
                    Fitur ini hanya tersedia untuk akun dengan lastname <strong>madeinweb</strong>.
                </div>
            </PageContentBlock>
        );
    }

    return (
        <PageContentBlock title={'Create Panel'} showFlashKey={'dashboard'}>
            <div css={tw`mx-auto max-w-3xl space-y-4`}>
                <div css={tw`rounded-xl border p-4`} style={panelStyle}>
                    <h2 css={tw`text-lg font-semibold text-neutral-100`}>Panel Generator</h2>
                    <p css={tw`text-sm text-neutral-400 mt-1`}>
                        Khusus akun madeinweb. Hanya bisa dipakai sekali.
                    </p>
                </div>

                <div css={tw`rounded-xl border p-4 space-y-4`} style={panelStyle}>
                    {loading ? (
                        <p css={tw`text-neutral-300`}>Memuat opsi...</p>
                    ) : created ? (
                        <p css={tw`text-green-300`}>Create Panel sudah dipakai untuk akun ini.</p>
                    ) : (
                        <>
                            <div>
                                <label htmlFor={'panel-name'} css={tw`text-sm text-neutral-300`}>
                                    Nama Server
                                </label>
                                <input
                                    id={'panel-name'}
                                    value={name}
                                    onChange={(e) => setName(e.currentTarget.value)}
                                    css={tw`mt-1 w-full bg-neutral-900 border border-neutral-600 rounded-lg px-3 py-2 text-neutral-100`}
                                    placeholder={'Bebas, contoh: My First Panel'}
                                />
                            </div>

                            <div>
                                <label htmlFor={'panel-egg'} css={tw`text-sm text-neutral-300`}>
                                    Egg
                                </label>
                                <select
                                    id={'panel-egg'}
                                    value={eggId ?? ''}
                                    onChange={(e) => setEggId(Number(e.currentTarget.value))}
                                    css={tw`mt-1 w-full bg-neutral-900 border border-neutral-600 rounded-lg px-3 py-2 text-neutral-100`}
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
                                    css={tw`mt-1 w-full bg-neutral-900 border border-neutral-600 rounded-lg px-3 py-2 text-neutral-100`}
                                >
                                    {ramOptions.map((value) => (
                                        <option key={value} value={value}>
                                            {value} MB
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div css={tw`rounded-lg border px-3 py-2 text-sm text-neutral-300`} style={insetPanelStyle}>
                                CPU fixed: <strong>{cpuFixed}%</strong> | Threads pin: <strong>{threadsFixed}</strong>
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
                                        setSuccess('Server berhasil dibuat. Kamu bisa lihat di Dashboard.');
                                        updateUserData({ madeinwebPanelCreatedAt: new Date().toISOString() });
                                    } catch (e) {
                                        setError(httpErrorToHuman(e));
                                    } finally {
                                        setSubmitting(false);
                                    }
                                }}
                            >
                                Create Panel
                            </Button>
                        </>
                    )}

                    {error && <p css={tw`text-red-300 text-sm`}>{error}</p>}
                    {success && <p css={tw`text-green-300 text-sm`}>{success}</p>}
                </div>
            </div>
        </PageContentBlock>
    );
};
