import React from 'react';
import Icon from '@/components/elements/Icon';
import { IconDefinition } from '@fortawesome/free-solid-svg-icons';
import classNames from 'classnames';
import styles from './style.module.css';
import CopyOnClick from '@/components/elements/CopyOnClick';

interface StatBlockProps {
    title: string;
    copyOnClick?: string;
    color?: string | undefined;
    icon: IconDefinition;
    children: React.ReactNode;
    className?: string;
}

export default ({ title, copyOnClick, icon, color, className, children }: StatBlockProps) => (
    <CopyOnClick text={copyOnClick}>
        <div className={classNames(styles.stat_block, 'bg-gray-600', className)}>
            <div className={classNames(styles.status_bar, color || 'bg-gray-700')} />
            <div className={classNames(styles.icon, color || 'bg-gray-700')}>
                <Icon
                    icon={icon}
                    className={classNames({
                        'text-gray-100': !color || color === 'bg-gray-700',
                        'text-gray-50': color && color !== 'bg-gray-700',
                    })}
                />
            </div>
            <div className={'flex flex-col justify-center overflow-hidden w-full'}>
                <p className={'font-header font-medium leading-tight text-xs md:text-sm text-gray-200'}>{title}</p>
                <div className={'h-[1.75rem] w-full font-semibold text-gray-50 truncate text-lg md:text-xl leading-7'}>
                    {children}
                </div>
            </div>
        </div>
    </CopyOnClick>
);
