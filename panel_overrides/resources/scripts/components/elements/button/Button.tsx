import React, { forwardRef } from 'react';
import classNames from 'classnames';
import { ButtonProps, Options } from '@/components/elements/button/types';
import styles from './style.module.css';

const Button = forwardRef<HTMLButtonElement, ButtonProps>(
    ({ children, shape, size, variant, color, className, ...rest }, ref) => {
        const isSecondary = variant === Options.Variant.Secondary || variant === 'secondary' || color === 'secondary' || color === 'grey';
        const isSmall = size === Options.Size.Small || size === 'small' || size === 'xsmall';
        const isLarge = size === Options.Size.Large || size === 'large' || size === 'xlarge';

        return (
            <button
                ref={ref}
                className={classNames(
                    styles.button,
                    styles.primary,
                    {
                        [styles.secondary]: isSecondary,
                        [styles.square]: shape === Options.Shape.IconSquare || shape === 'icon-square',
                        [styles.small]: isSmall,
                        [styles.large]: isLarge,
                    },
                    className
                )}
                {...rest}
            >
                {children}
            </button>
        );
    }
);

const TextButton = forwardRef<HTMLButtonElement, ButtonProps>(({ className, ...props }, ref) => (
    // @ts-expect-error not sure how to get this correct
    <Button ref={ref} className={classNames(styles.text, className)} {...props} />
));

const DangerButton = forwardRef<HTMLButtonElement, ButtonProps>(({ className, ...props }, ref) => (
    // @ts-expect-error not sure how to get this correct
    <Button ref={ref} className={classNames(styles.danger, className)} {...props} />
));

const _Button = Object.assign(Button, {
    Sizes: Options.Size,
    Shapes: Options.Shape,
    Variants: Options.Variant,
    Text: TextButton,
    Danger: DangerButton,
});

export default _Button;
