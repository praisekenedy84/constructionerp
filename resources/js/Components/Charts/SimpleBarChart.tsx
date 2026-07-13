import ChartContainer from '@/Components/Charts/ChartContainer';
import { CHART_COLORS } from '@/lib/chart-colors';
import { currencyTooltipFormatter, formatChartCurrency } from '@/lib/chart-helpers';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Legend,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

export interface BarSeries {
    key: string;
    name: string;
    color?: string;
}

interface SimpleBarChartProps {
    data: Record<string, string | number>[];
    xKey: string;
    series: BarSeries[];
    stacked?: boolean;
    height?: number;
}

export default function SimpleBarChart({
    data,
    xKey,
    series,
    stacked = false,
    height = 280,
}: SimpleBarChartProps) {
    if (data.length === 0) {
        return (
            <p className="py-12 text-center text-sm text-slate-500">No data to display.</p>
        );
    }

    return (
        <ChartContainer height={height}>
            <BarChart data={data} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" vertical={false} />
                <XAxis
                    dataKey={xKey}
                    tick={{ fill: '#64748b', fontSize: 12 }}
                    axisLine={{ stroke: '#e2e8f0' }}
                    tickLine={false}
                />
                <YAxis
                    tick={{ fill: '#64748b', fontSize: 12 }}
                    axisLine={false}
                    tickLine={false}
                    tickFormatter={formatChartCurrency}
                />
                <Tooltip
                    formatter={currencyTooltipFormatter}
                    contentStyle={{
                        borderRadius: '8px',
                        border: '1px solid #e2e8f0',
                        fontSize: '13px',
                    }}
                />
                {series.length > 1 && <Legend wrapperStyle={{ fontSize: '13px' }} />}
                {series.map((s, i) => (
                    <Bar
                        key={s.key}
                        dataKey={s.key}
                        name={s.name}
                        fill={s.color ?? CHART_COLORS[i % CHART_COLORS.length]}
                        stackId={stacked ? 'stack' : undefined}
                        radius={[4, 4, 0, 0]}
                    />
                ))}
            </BarChart>
        </ChartContainer>
    );
}
