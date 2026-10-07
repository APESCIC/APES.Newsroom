import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEventHandler } from 'react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import type { SharedPageProps } from '../../../types/page';

export type SettingDefinition = {
    key: string;
    label: string;
    help: string;
    type: 'enum';
    options: { value: string; label: string }[];
    value: string;
};

type SettingValues = Record<string, string>;

function nestByDots(values: SettingValues): Record<string, unknown> {
    const nested: Record<string, unknown> = {};

    for (const [key, value] of Object.entries(values)) {
        const segments = key.split('.');
        let cursor = nested;
        segments.slice(0, -1).forEach((segment) => {
            cursor[segment] = (cursor[segment] as Record<string, unknown> | undefined) ?? {};
            cursor = cursor[segment] as Record<string, unknown>;
        });
        cursor[segments[segments.length - 1]] = value;
    }

    return nested;
}

function inputId(key: string, suffix: string): string {
    return `setting-${key.replace(/\./g, '-')}-${suffix}`;
}

function SettingField({
    setting,
    value,
    error,
    onChange,
}: {
    setting: SettingDefinition;
    value: string;
    error?: string;
    onChange: (value: string) => void;
}) {
    const helpId = inputId(setting.key, 'help');
    const errorId = inputId(setting.key, 'error');

    switch (setting.type) {
        case 'enum':
            return (
                <fieldset aria-describedby={error ? `${helpId} ${errorId}` : helpId} aria-invalid={error ? true : undefined}>
                    <legend className="text-sm font-semibold">{setting.label}</legend>
                    <p id={helpId} className="mt-1 text-sm text-muted">
                        {setting.help}
                    </p>
                    <div className="mt-3 flex flex-wrap gap-4">
                        {setting.options.map((option) => (
                            <label key={option.value} className="inline-flex min-h-11 items-center gap-2 text-sm">
                                <input
                                    type="radio"
                                    name={setting.key}
                                    value={option.value}
                                    checked={value === option.value}
                                    onChange={() => onChange(option.value)}
                                />
                                {option.label}
                            </label>
                        ))}
                    </div>
                    {error && (
                        <p id={errorId} className="mt-1 text-sm text-red-700">
                            {error}
                        </p>
                    )}
                </fieldset>
            );
        default: {
            const exhaustive: never = setting.type;
            return exhaustive;
        }
    }
}

export default function SettingsIndex({ settings }: { settings: SettingDefinition[] }) {
    const { flash } = usePage<SharedPageProps>().props;
    const form = useForm<SettingValues>(Object.fromEntries(settings.map((setting) => [setting.key, setting.value])));

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.transform((values) => ({ settings: nestByDots(values) }));
        form.put('/admin/settings', { preserveScroll: true });
    };

    return (
        <WorkspaceLayout area="Admin" active="settings" title="Settings" subtitle="Site-wide configuration for the Newsroom.">
            <Head title="Settings" />
            <form onSubmit={submit} className="mx-auto flex max-w-3xl flex-col gap-6 rounded-card border border-border bg-white p-6">
                {flash?.status && (
                    <p role="status" className="text-sm text-green-800">
                        {flash.status}
                    </p>
                )}
                {settings.map((setting) => (
                    <SettingField
                        key={setting.key}
                        setting={setting}
                        value={form.data[setting.key] ?? setting.value}
                        error={form.errors[`settings.${setting.key}`]}
                        onChange={(value) => form.setData(setting.key, value)}
                    />
                ))}
                <button type="submit" disabled={form.processing} className="button-primary w-fit min-h-11 px-5">
                    Save settings
                </button>
            </form>
        </WorkspaceLayout>
    );
}
