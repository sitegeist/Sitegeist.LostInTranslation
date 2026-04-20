import styled, { keyframes } from "styled-components";

export const Container = styled.div`
    width: 100%;
    display: flex;
    flex-direction: column;
    gap: 16px;
`

export const Info = styled.div`
    width: 100%;
    gap: 8px;
`

export const ButtonsContainer = styled.div`
    width: 100%;
    display: flex;
    flex-direction: column;
    gap: 8px;
`

const spin = keyframes`
    from {
        transform: rotate(0deg);
    }

    to {
        transform: rotate(360deg);
    }
`;

export const LoadingContainer = styled.div`
    width: 100%;
    display: flex;
    align-items: center;
    gap: 8px;
`;

export const Spinner = styled.div`
    width: 16px;
    height: 16px;
    border: 2px solid rgba(255, 255, 255, 0.2);
    border-top-color: rgba(255, 255, 255, 0.9);
    border-radius: 50%;
    animation: ${spin} 0.8s linear infinite;
    flex-shrink: 0;
`;
