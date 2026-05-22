enum Shape {
    Default,
    IconSquare,
}

enum Size {
    Default,
    Small,
    Large,
}

enum Variant {
    Primary,
    Secondary,
}

export const Options = { Shape, Size, Variant };

export type ButtonProps = JSX.IntrinsicElements['button'] & {
    shape?: Shape | 'icon-square';
    size?: Size | 'xsmall' | 'small' | 'large' | 'xlarge';
    variant?: Variant | 'primary' | 'secondary';
    color?: 'primary' | 'secondary' | 'green' | 'red' | 'yellow' | 'grey';
};
