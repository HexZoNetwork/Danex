import React, { useState } from 'react';
import http from '@/api/http';
import { ServerContext } from '@/state/server';
import useFileManagerSwr from '@/plugins/useFileManagerSwr';
import { useFlashKey } from '@/plugins/useFlash';
import Modal from '@/components/elements/Modal';
import { Button } from '@/components/elements/button/index';
import tw from 'twin.macro';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faFolderPlus } from '@fortawesome/free-solid-svg-icons';
import style from './style.module.css';

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const directory = ServerContext.useStoreState((state) => state.files.directory);
    const { mutate } = useFileManagerSwr();
    const { clearAndAddHttpError } = useFlashKey('files');
    const [visible, setVisible] = useState(false);
    const [name, setName] = useState('');
    const [loading, setLoading] = useState(false);

    const submit = () => {
        const trimmed = name.trim();
        if (!trimmed || loading) return;

        setLoading(true);
        clearAndAddHttpError();
        http.post(`/api/client/servers/${uuid}/files/create-folder`, { root: directory, name: trimmed })
            .then(() => mutate())
            .then(() => {
                setVisible(false);
                setName('');
            })
            .catch((error) => clearAndAddHttpError(error))
            .finally(() => setLoading(false));
    };

    return (
        <>
            <Modal visible={visible} onDismissed={() => setVisible(false)} showSpinnerOverlay={loading}>
                <form
                    className={'m-0'}
                    onSubmit={(e) => {
                        e.preventDefault();
                        submit();
                    }}
                >
                    <h2 css={tw`mb-4 text-lg font-semibold text-neutral-50`}>Create Directory</h2>
                    <label htmlFor={'directory_name'} css={tw`mb-2 block text-sm font-semibold text-neutral-300`}>
                        Directory Name
                    </label>
                    <input
                        id={'directory_name'}
                        name={'directory_name'}
                        autoFocus
                        value={name}
                        onChange={(e) => setName(e.currentTarget.value)}
                        css={tw`w-full rounded-lg border px-3 py-2 text-neutral-100`}
                    />
                    <div css={tw`mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end`}>
                        <Button.Text type={'button'} onClick={() => setVisible(false)}>Cancel</Button.Text>
                        <Button type={'submit'} disabled={!name.trim() || loading}>Create Directory</Button>
                    </div>
                </form>
            </Modal>
            <Button type={'button'} title={'Create directory'} aria-label={'Create directory'} onClick={() => setVisible(true)}>
                <FontAwesomeIcon icon={faFolderPlus} className={style.action_icon} />
                <span className={style.action_label}>Create Directory</span>
            </Button>
        </>
    );
};
