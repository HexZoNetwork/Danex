import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faFileAlt, faFileArchive, faFileImport, faFolder } from '@fortawesome/free-solid-svg-icons';
import { encodePathSegments } from '@/helpers';
import { differenceInHours, format, formatDistanceToNow } from 'date-fns';
import React, { memo } from 'react';
import { FileObject } from '@/api/server/files/loadDirectory';
import FileDropdownMenu from '@/components/server/files/FileDropdownMenu';
import { ServerContext } from '@/state/server';
import { NavLink, useRouteMatch } from 'react-router-dom';
import isEqual from 'react-fast-compare';
import SelectFileCheckbox from '@/components/server/files/SelectFileCheckbox';
import { usePermissions } from '@/plugins/usePermissions';
import { join } from 'pathe';
import { bytesToString } from '@/lib/formatters';
import styles from './style.module.css';

const Clickable: React.FC<{ file: FileObject }> = memo(({ file, children }) => {
    const [canRead] = usePermissions(['file.read']);
    const [canReadContents] = usePermissions(['file.read-content']);
    const directory = ServerContext.useStoreState((state) => state.files.directory);

    const match = useRouteMatch();

    return (file.isFile && (!file.isEditable() || !canReadContents)) || (!file.isFile && !canRead) ? (
        <div className={styles.details}>{children}</div>
    ) : (
        <NavLink
            className={styles.details}
            to={`${match.url}${file.isFile ? '/edit' : ''}#${encodePathSegments(join(directory, file.name))}`}
        >
            {children}
        </NavLink>
    );
}, isEqual);

const formattedModifiedAt = (date: Date) =>
    Math.abs(differenceInHours(date, new Date())) > 48
        ? format(date, 'MMM do, yyyy h:mma')
        : formatDistanceToNow(date, { addSuffix: true });

const FileObjectRow = ({ file }: { file: FileObject }) => {
    const modifiedAt = formattedModifiedAt(file.modifiedAt);
    const sizeLabel = file.isFile ? bytesToString(file.size) : 'Folder';
    const modifiedTitle = file.modifiedAt.toString();

    return (
        <div
            className={styles.file_row}
            key={file.name}
            onContextMenu={(e) => {
                e.preventDefault();
                e.stopPropagation();
                window.dispatchEvent(new CustomEvent(`pterodactyl:files:ctx:${file.key}`, { detail: { x: e.clientX, y: e.clientY } }));
            }}
        >
            <div className={styles.select_cell}>
                <SelectFileCheckbox name={file.name} />
            </div>
            <Clickable file={file}>
                <div
                    className={styles.icon_wrap}
                    data-file-type={file.isFile ? 'file' : 'folder'}
                    aria-hidden
                >
                    {file.isFile ? (
                        <FontAwesomeIcon
                            icon={file.isSymlink ? faFileImport : file.isArchiveType() ? faFileArchive : faFileAlt}
                        />
                    ) : (
                        <FontAwesomeIcon icon={faFolder} />
                    )}
                </div>
                <div className={styles.content}>
                    <div className={styles.name}>{file.name}</div>
                    <div className={styles.meta_mobile} title={modifiedTitle}>
                        <span>{sizeLabel}</span>
                        <span className={styles.meta_dot}>•</span>
                        <span>{modifiedAt}</span>
                    </div>
                </div>
                <div className={styles.size_desktop}>{file.isFile ? sizeLabel : null}</div>
                <div className={styles.date_desktop} title={modifiedTitle}>
                    {modifiedAt}
                </div>
            </Clickable>
            <div className={styles.actions}>
                <FileDropdownMenu file={file} />
            </div>
        </div>
    );
};

export default memo(FileObjectRow, (prevProps, nextProps) => {
    /* eslint-disable @typescript-eslint/no-unused-vars */
    const { isArchiveType, isEditable, ...prevFile } = prevProps.file;
    const { isArchiveType: nextIsArchiveType, isEditable: nextIsEditable, ...nextFile } = nextProps.file;
    /* eslint-enable @typescript-eslint/no-unused-vars */

    return isEqual(prevFile, nextFile);
});
