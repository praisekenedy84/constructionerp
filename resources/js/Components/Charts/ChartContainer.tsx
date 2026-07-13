import { ReactNode } from 'react';
import { ResponsiveContainer } from 'recharts';

interface ChartContainerProps {
    children: ReactNode;
    height?: number;
}

export default function ChartContainer({ children, height = 280 }: ChartContainerProps) {
    return (
        <ResponsiveContainer width="100%" height={height}>
            {children as React.ReactElement}
        </ResponsiveContainer>
    );
}
