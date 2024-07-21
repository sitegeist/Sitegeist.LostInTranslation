import * as React from 'react';

interface IconProps {
    icon: string;
}

export default function Icon({ icon }: IconProps) {
    return <i className={`fas fa-${icon}`} />;
}
