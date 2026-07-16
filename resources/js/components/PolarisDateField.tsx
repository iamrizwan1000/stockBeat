import { Button, DatePicker, Icon, Popover, TextField } from '@shopify/polaris';
import { CalendarIcon } from '@shopify/polaris-icons';
import { useState } from 'react';

/**
 * A native Polaris date field: a read-only TextField trigger showing the
 * selected date in a human-readable format, opening a Popover-hosted
 * DatePicker on click. Value/onChange use plain `YYYY-MM-DD` strings (what
 * every date filter/form field in this admin already sends to the
 * backend) — parsed as local dates, not UTC, so the picker never shows a
 * day off from what was actually selected.
 */
export default function PolarisDateField({
    label,
    labelHidden,
    value,
    onChange,
    allowClear = true,
}: {
    label: string;
    labelHidden?: boolean;
    value: string;
    onChange: (value: string) => void;
    allowClear?: boolean;
}) {
    const selectedDate = parseIsoDate(value);
    const [popoverActive, setPopoverActive] = useState(false);
    const [{ month, year }, setMonthYear] = useState(() => {
        const base = selectedDate ?? new Date();

        return { month: base.getMonth(), year: base.getFullYear() };
    });

    const displayValue = selectedDate ? formatDisplayDate(selectedDate) : '';

    return (
        <Popover
            active={popoverActive}
            onClose={() => setPopoverActive(false)}
            activator={
                <TextField
                    label={label}
                    labelHidden={labelHidden}
                    value={displayValue}
                    onFocus={() => setPopoverActive(true)}
                    prefix={<Icon source={CalendarIcon} />}
                    placeholder="Any date"
                    autoComplete="off"
                    readOnly
                    clearButton={allowClear && value !== ''}
                    onClearButtonClick={() => onChange('')}
                />
            }
        >
            <Popover.Pane fixed>
                <div style={{ padding: '16px' }}>
                    <DatePicker
                        month={month}
                        year={year}
                        selected={selectedDate}
                        onMonthChange={(newMonth, newYear) =>
                            setMonthYear({ month: newMonth, year: newYear })
                        }
                        onChange={({ start }) => {
                            onChange(formatIsoDate(start));
                            setPopoverActive(false);
                        }}
                    />
                    {allowClear && value !== '' && (
                        <div style={{ marginTop: '8px' }}>
                            <Button
                                onClick={() => {
                                    onChange('');
                                    setPopoverActive(false);
                                }}
                            >
                                Clear date
                            </Button>
                        </div>
                    )}
                </div>
            </Popover.Pane>
        </Popover>
    );
}

function parseIsoDate(value: string): Date | undefined {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);

    if (!match) {
        return undefined;
    }

    const [, y, m, d] = match;

    return new Date(Number(y), Number(m) - 1, Number(d));
}

function formatIsoDate(date: Date): string {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');

    return `${y}-${m}-${d}`;
}

function formatDisplayDate(date: Date): string {
    return date.toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}
