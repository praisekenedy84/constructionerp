import { Input, InputProps } from '@/Components/ui/input';
import { formatAmountInput, parseAmountInput } from '@/lib/formatters';
import * as React from 'react';

export interface AmountInputProps
    extends Omit<InputProps, 'type' | 'value' | 'onChange' | 'inputMode'> {
    /** Raw numeric string without commas (what forms / API expect). */
    value: string | number;
    /** Called with the raw numeric string (commas stripped). */
    onValueChange: (raw: string) => void;
    /** Max decimal places while typing (default 2 for money). */
    maxDecimals?: number;
}

function significantCount(value: string, caret: number): number {
    return value.slice(0, caret).replace(/[^\d.]/g, '').length;
}

function caretFromSignificant(value: string, count: number): number {
    if (count <= 0) {
        return 0;
    }

    let seen = 0;
    for (let i = 0; i < value.length; i++) {
        if (/[\d.]/.test(value[i])) {
            seen += 1;
            if (seen >= count) {
                return i + 1;
            }
        }
    }

    return value.length;
}

/**
 * Money / amount field with live thousand separators (1,250,000.50).
 * Stores and emits a plain numeric string so backend validation stays unchanged.
 */
const AmountInput = React.forwardRef<HTMLInputElement, AmountInputProps>(
    ({ value, onValueChange, maxDecimals = 2, onBlur, onFocus, ...props }, ref) => {
        const rawValue = value === null || value === undefined ? '' : String(value);
        const innerRef = React.useRef<HTMLInputElement | null>(null);
        const [focused, setFocused] = React.useState(false);
        const [display, setDisplay] = React.useState(() => formatAmountInput(rawValue, maxDecimals));

        React.useImperativeHandle(ref, () => innerRef.current as HTMLInputElement);

        React.useEffect(() => {
            if (!focused) {
                setDisplay(formatAmountInput(rawValue, maxDecimals));
            }
        }, [rawValue, maxDecimals, focused]);

        function setRefs(node: HTMLInputElement | null) {
            innerRef.current = node;
            if (typeof ref === 'function') {
                ref(node);
            } else if (ref) {
                ref.current = node;
            }
        }

        function handleChange(e: React.ChangeEvent<HTMLInputElement>) {
            const el = e.target;
            const caret = el.selectionStart ?? el.value.length;
            const digitsBefore = significantCount(el.value, caret);

            const raw = parseAmountInput(el.value);
            const nextDisplay = formatAmountInput(raw, maxDecimals);
            const nextRaw = parseAmountInput(nextDisplay);

            setDisplay(nextDisplay);
            onValueChange(nextRaw === '.' ? '' : nextRaw);

            requestAnimationFrame(() => {
                const input = innerRef.current;
                if (!input) {
                    return;
                }
                const nextCaret = caretFromSignificant(nextDisplay, digitsBefore);
                input.setSelectionRange(nextCaret, nextCaret);
            });
        }

        return (
            <Input
                {...props}
                ref={setRefs}
                type="text"
                inputMode="decimal"
                autoComplete="off"
                value={display}
                onChange={handleChange}
                onFocus={(e) => {
                    setFocused(true);
                    onFocus?.(e);
                }}
                onBlur={(e) => {
                    setFocused(false);
                    const normalized = formatAmountInput(parseAmountInput(e.target.value), maxDecimals);
                    setDisplay(normalized);
                    onValueChange(parseAmountInput(normalized));
                    onBlur?.(e);
                }}
            />
        );
    },
);
AmountInput.displayName = 'AmountInput';

export { AmountInput };
