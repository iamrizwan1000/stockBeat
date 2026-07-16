import {
    Button,
    Icon,
    InlineStack,
    Popover,
    Select,
    TextField,
} from '@shopify/polaris';
import { ClockIcon } from '@shopify/polaris-icons';
import { useState } from 'react';

const HOUR_OPTIONS = [
    { label: '--', value: '' },
    ...Array.from({ length: 24 }, (_, h) => ({ label: pad(h), value: pad(h) })),
];

const MINUTE_OPTIONS = [
    { label: '--', value: '' },
    ...Array.from({ length: 60 }, (_, m) => ({ label: pad(m), value: pad(m) })),
];

/**
 * A native Polaris time-of-day field. Polaris has no built-in time picker
 * component (verified against the installed package — no TimePicker/
 * TimeField exists anywhere in it), so this fills that gap the same way
 * PolarisDateField fills the date one: a read-only TextField trigger
 * showing a human-readable time, opening a Popover with hour/minute
 * Selects on click, rather than falling back to the native browser
 * `<input type="time">` widget. Value/onChange use plain 24h `HH:MM`
 * strings — what this admin already sends to the backend.
 */
export default function PolarisTimeField({
    label,
    labelHidden,
    value,
    onChange,
    allowClear = true,
    disabled = false,
}: {
    label: string;
    labelHidden?: boolean;
    value: string;
    onChange: (value: string) => void;
    allowClear?: boolean;
    disabled?: boolean;
}) {
    const [popoverActive, setPopoverActive] = useState(false);
    const [hour, minute] = parseTime(value);

    const displayValue =
        hour !== '' && minute !== '' ? formatDisplayTime(hour, minute) : '';

    // Each Select commits independently, defaulting the *other* half to
    // "00" the first time — otherwise picking hour before minute (or vice
    // versa) would see the other half still blank and clear the whole
    // value instead of building it up.
    const setHour = (h: string) =>
        onChange(h === '' ? '' : `${h}:${minute || '00'}`);
    const setMinute = (m: string) =>
        onChange(m === '' ? '' : `${hour || '00'}:${m}`);

    return (
        <Popover
            active={popoverActive && !disabled}
            onClose={() => setPopoverActive(false)}
            activator={
                <TextField
                    label={label}
                    labelHidden={labelHidden}
                    value={displayValue}
                    onFocus={() => setPopoverActive(true)}
                    prefix={<Icon source={ClockIcon} />}
                    placeholder="Any time"
                    autoComplete="off"
                    readOnly
                    disabled={disabled}
                    clearButton={allowClear && value !== ''}
                    onClearButtonClick={() => onChange('')}
                />
            }
        >
            <Popover.Pane fixed>
                <div style={{ padding: '16px' }}>
                    <InlineStack gap="200" blockAlign="end">
                        <Select
                            label="Hour"
                            labelHidden
                            options={HOUR_OPTIONS}
                            value={hour}
                            onChange={setHour}
                        />
                        <Select
                            label="Minute"
                            labelHidden
                            options={MINUTE_OPTIONS}
                            value={minute}
                            onChange={setMinute}
                        />
                    </InlineStack>
                    {allowClear && value !== '' && (
                        <div style={{ marginTop: '8px' }}>
                            <Button
                                onClick={() => {
                                    onChange('');
                                    setPopoverActive(false);
                                }}
                            >
                                Clear time
                            </Button>
                        </div>
                    )}
                </div>
            </Popover.Pane>
        </Popover>
    );
}

function parseTime(value: string): [string, string] {
    const match = /^(\d{2}):(\d{2})$/.exec(value);

    if (!match) {
        return ['', ''];
    }

    return [match[1], match[2]];
}

function pad(n: number): string {
    return String(n).padStart(2, '0');
}

function formatDisplayTime(hour: string, minute: string): string {
    const asDate = new Date(2000, 0, 1, Number(hour), Number(minute));

    return asDate.toLocaleTimeString(undefined, {
        hour: 'numeric',
        minute: '2-digit',
    });
}
