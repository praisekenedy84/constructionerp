import ChartContainer from '@/Components/Charts/ChartContainer';
import { CHART_COLORS } from '@/lib/chart-colors';
import { Cell, Legend, Pie, PieChart, Tooltip } from 'recharts';

interface SimplePieChartProps {
    data: { name: string; value: number }[];
    height?: number;
    valueLabel?: string;
    formatValue?: (value: number) => string;
}

export default function SimplePieChart({
    data,
    height = 280,
    valueLabel = 'Count',
    formatValue,
}: SimplePieChartProps) {
    if (data.length === 0) {
        return (
            <p className="py-12 text-center text-sm text-slate-500">No data to display.</p>
        );
    }

    return (
        <ChartContainer height={height}>
            <PieChart>
                <Pie
                    data={data}
                    dataKey="value"
                    nameKey="name"
                    cx="50%"
                    cy="50%"
                    innerRadius={60}
                    outerRadius={100}
                    paddingAngle={2}
                >
                    {data.map((_, i) => (
                        <Cell key={i} fill={CHART_COLORS[i % CHART_COLORS.length]} />
                    ))}
                </Pie>
                <Tooltip
                    formatter={(value) => [
                        formatValue ? formatValue(Number(value)) : value,
                        valueLabel,
                    ]}
                    contentStyle={{
                        borderRadius: '8px',
                        border: '1px solid #e2e8f0',
                        fontSize: '13px',
                    }}
                />
                <Legend wrapperStyle={{ fontSize: '13px' }} />
            </PieChart>
        </ChartContainer>
    );
}
