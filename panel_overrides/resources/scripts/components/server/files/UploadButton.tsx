import axios, { AxiosProgressEvent } from 'axios';
import getFileUploadUrl from '@/api/server/files/getFileUploadUrl';
import http from '@/api/http';
import tw from 'twin.macro';
import { Button } from '@/components/elements/button/index';
import React, { useEffect, useRef } from 'react';
import { ModalMask } from '@/components/elements/Modal';
import Fade from '@/components/elements/Fade';
import useEventListener from '@/plugins/useEventListener';
import { useFlashKey } from '@/plugins/useFlash';
import useFileManagerSwr from '@/plugins/useFileManagerSwr';
import { ServerContext } from '@/state/server';
import { WithClassname } from '@/components/types';
import Portal from '@/components/elements/Portal';
import { CloudUploadIcon } from '@heroicons/react/outline';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faUpload } from '@fortawesome/free-solid-svg-icons';
import style from './style.module.css';
import { useSignal } from '@preact/signals-react';

function isFileOrDirectory(event: DragEvent): boolean {
    if (!event.dataTransfer?.types) {
        return false;
    }

    return Array.from(event.dataTransfer.types).some((value) => value.toLowerCase() === 'files');
}

export default ({ className }: WithClassname) => {
    const fileUploadInput = useRef<HTMLInputElement>(null);

    const visible = useSignal(false);
    const timeouts = useSignal<NodeJS.Timeout[]>([]);

    const { mutate } = useFileManagerSwr();
    const { addError, clearAndAddHttpError } = useFlashKey('files');

    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const directory = ServerContext.useStoreState((state) => state.files.directory);
    const { clearFileUploads, removeFileUpload, pushFileUpload, setUploadProgress } = ServerContext.useStoreActions(
        (actions) => actions.files
    );

    useEventListener(
        'dragenter',
        (e) => {
            e.preventDefault();
            e.stopPropagation();
            if (isFileOrDirectory(e)) {
                visible.value = true;
            }
        },
        { capture: true }
    );

    useEventListener(
        'dragover',
        (e) => {
            if (!isFileOrDirectory(e)) return;
            e.preventDefault();
            e.stopPropagation();
            visible.value = true;
        },
        { capture: true }
    );

    useEventListener(
        'dragleave',
        (e) => {
            const event = e as DragEvent;
            if (
                event.clientX <= 0 ||
                event.clientY <= 0 ||
                event.clientX >= window.innerWidth ||
                event.clientY >= window.innerHeight
            ) {
                visible.value = false;
            }
        },
        { capture: true }
    );

    useEventListener(
        'drop',
        (e) => {
            if (!isFileOrDirectory(e)) return;
            e.preventDefault();
            e.stopPropagation();
            visible.value = false;
            if (e.dataTransfer?.files.length) {
                onFileSubmission(e.dataTransfer.files);
            }
        },
        { capture: true }
    );

    useEventListener('keydown', () => (visible.value = false));

    useEffect(() => {
        return () => timeouts.value.forEach(clearTimeout);
    }, []);

    const onUploadProgress = (data: AxiosProgressEvent, name: string) => {
        setUploadProgress({ name, loaded: data.loaded });
    };

    const onFileSubmission = (files: FileList) => {
        clearAndAddHttpError();
        const list = Array.from(files);
        if (list.some((file) => !file.type && (!file.size || file.size === 4096))) {
            return addError('Folder uploads are not supported.', 'Error');
        }

        const uploads = list.map((file) => {
            const controller = new AbortController();
            pushFileUpload({
                name: file.name,
                data: { abort: controller, loaded: 0, total: file.size },
            });

            const uploadOptions = {
                signal: controller.signal,
                headers: { 'Content-Type': 'multipart/form-data' },
                params: { directory },
                onUploadProgress: (data: AxiosProgressEvent) => onUploadProgress(data, file.name),
            };

            const form = new FormData();
            form.append('files', file, file.name);

            const uploadViaPanel = () => http.post(`/api/client/servers/${uuid}/files/upload`, form, uploadOptions);

            return () =>
                getFileUploadUrl(uuid)
                    .then((url) => axios.post(url, form, uploadOptions).catch(uploadViaPanel))
                    .catch(uploadViaPanel)
                    .then(() => timeouts.value.push(setTimeout(() => removeFileUpload(file.name), 500)));
        });

        Promise.all(uploads.map((fn) => fn()))
            .then(() => mutate())
            .catch((error) => {
                clearFileUploads();
                clearAndAddHttpError(error);
            });
    };

    return (
        <>
            <Portal>
                <Fade appear in={visible.value} timeout={75} key={'upload_modal_mask'} unmountOnExit>
                    <ModalMask
                        onClick={() => (visible.value = false)}
                        onDragOver={(e) => e.preventDefault()}
                        onDrop={(e) => {
                            e.preventDefault();
                            e.stopPropagation();

                            visible.value = false;
                            if (!e.dataTransfer?.files.length) return;

                            onFileSubmission(e.dataTransfer.files);
                        }}
                    >
                        <div className={'w-full flex items-center justify-center pointer-events-none'}>
                            <div
                                className={
                                    'flex items-center space-x-4 bg-black w-full ring-4 ring-blue-200 ring-opacity-60 rounded p-6 mx-10 max-w-sm'
                                }
                            >
                                <CloudUploadIcon className={'w-10 h-10 flex-shrink-0'} />
                                <p className={'font-header flex-1 text-lg text-neutral-100 text-center'}>
                                    Drag and drop files to upload.
                                </p>
                            </div>
                        </div>
                    </ModalMask>
                </Fade>
            </Portal>
            <input
                type={'file'}
                ref={fileUploadInput}
                css={tw`hidden`}
                onChange={(e) => {
                    if (!e.currentTarget.files) return;

                    onFileSubmission(e.currentTarget.files);
                    if (fileUploadInput.current) {
                        fileUploadInput.current.value = '';
                    }
                }}
                multiple
            />
            <Button className={className} title={'Upload files'} aria-label={'Upload files'} onClick={() => fileUploadInput.current && fileUploadInput.current.click()}>
                <FontAwesomeIcon icon={faUpload} className={style.action_icon} />
                <span className={style.action_label}>Upload</span>
            </Button>
        </>
    );
};
