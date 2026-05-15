import styled from 'styled-components/macro';
import tw from 'twin.macro';

export default styled.div<{ $hoverable?: boolean }>`
    ${tw`flex rounded-lg no-underline text-neutral-100 items-center p-4 overflow-hidden`};
    background: #0b0b10;
    border: 1px solid rgba(139, 92, 246, 0.24);
    box-shadow: 0 16px 34px rgba(0, 0, 0, 0.42), inset 0 1px 0 rgba(255, 255, 255, 0.04);
    transition: transform 240ms cubic-bezier(0.4, 0, 0.2, 1), border-color 240ms cubic-bezier(0.4, 0, 0.2, 1), box-shadow 240ms cubic-bezier(0.4, 0, 0.2, 1), background 240ms cubic-bezier(0.4, 0, 0.2, 1);

    ${(props) =>
        props.$hoverable !== false &&
        `
        &:hover {
            transform: translateY(-3px);
            border-color: rgba(139, 92, 246, 0.68);
            background: #111117;
            box-shadow: 0 22px 48px rgba(0, 0, 0, 0.52), 0 0 28px rgba(139, 92, 246, 0.18);
        }
    `};

    & .icon {
        ${tw`rounded-lg w-14 flex items-center justify-center p-3`};
        background: #111117;
        border: 1px solid rgba(139, 92, 246, 0.36);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.06), 0 0 20px rgba(139, 92, 246, 0.18);
        color: #a78bfa;
    }
`;
