import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEventHandler } from 'react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import type { SharedPageProps } from '../../../types/page';

type Option = { value: string; label: string };

type BaseSetting = {
    key: string;
    section: string;
    label: string;
    help: string;
    options: Option[];
    min: number | null;
    max: number | null;
    unit: string | null;
};

export type SettingDefinition =
    | (BaseSetting & { type: 'enum'; value: string })
    | (BaseSetting & { type: 'int'; value: number })
    | (BaseSetting & { type: 'bool'; value: boolean })
    | (BaseSetting & { type: 'multi_enum'; value: string[] });

type SettingValue = SettingDefinition['value'];

type SettingValues = Record<string, SettingValue>;

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

function ErrorText({ id, error }: { id: string; error?: string }) {
    return error ? (
        <p id={id} className="mt-1 text-sm text-red-700">
            {error}
        </p>
    ) : null;
}

function SettingField({
    setting,
    value,
    error,
    onChange,
}: {
    setting: SettingDefinition;
    value: SettingValue;
    error?: string;
    onChange: (value: SettingValue) => void;
}) {
    const helpId = inputId(setting.key, 'help');
    const errorId = inputId(setting.key, 'error');
    const describedBy = error ? `${helpId} ${errorId}` : helpId;
    const help = (
        <p id={helpId} className="mt-1 text-sm text-muted">
            {setting.help}
        </p>
    );

    switch (setting.type) {
        case 'enum':
            return (
                <fieldset aria-describedby={describedBy} aria-invalid={error ? true : undefined}>
                    <legend className="text-sm font-semibold">{setting.label}</legend>
                    {help}
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
                    <ErrorText id={errorId} error={error} />
                </fieldset>
            );
        case 'int': {
            const id = inputId(setting.key, 'input');
            return (
                <div>
                    <label htmlFor={id} className="text-sm font-semibold">
                        {setting.label}
                    </label>
                    {help}
                    <div className="mt-2 flex items-center gap-2">
                        <input
                            id={id}
                            type="number"
                            className="form-input w-32"
                            min={setting.min ?? undefined}
                            max={setting.max ?? undefined}
                            value={Number.isNaN(value as number) ? '' : String(value)}
                            aria-describedby={describedBy}
                            aria-invalid={error ? true : undefined}
                            onChange={(event) => onChange(event.target.valueAsNumber)}
                        />
                        {setting.unit && <span className="text-sm text-muted">{setting.unit}</span>}
                    </div>
                    {setting.min !== null && setting.max !== null && (
                        <p className="mt-1 text-xs text-muted">
                            Between {setting.min} and {setting.max}.
                        </p>
                    )}
                    <ErrorText id={errorId} error={error} />
                </div>
            );
        }
        case 'bool': {
            const id = inputId(setting.key, 'input');
            return (
                <div>
                    <label htmlFor={id} className="inline-flex min-h-11 items-center gap-2 text-sm font-semibold">
                        <input
                            id={id}
                            type="checkbox"
                            checked={value === true}
                            aria-describedby={describedBy}
                            aria-invalid={error ? true : undefined}
                            onChange={(event) => onChange(event.target.checked)}
                        />
                        {setting.label}
                    </label>
                    {help}
                    <ErrorText id={errorId} error={error} />
                </div>
            );
        }
        case 'multi_enum': {
            const selected = Array.isArray(value) ? value : [];
            return (
                <fieldset aria-describedby={describedBy} aria-invalid={error ? true : undefined}>
                    <legend className="text-sm font-semibold">{setting.label}</legend>
                    {help}
                    <div className="mt-3 flex flex-wrap gap-4">
                        {setting.options.map((option) => (
                            <label key={option.value} className="inline-flex min-h-11 items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={selected.includes(option.value)}
                                    onChange={(event) =>
                                        onChange(
                                            event.target.checked
                                                ? [...selected, option.value]
                                                : selected.filter((item) => item !== option.value),
                                        )
                                    }
                                />
                                {option.label}
                            </label>
                        ))}
                    </div>
                    <ErrorText id={errorId} error={error} />
                </fieldset>
            );
        }
        default: {
            const exhaustive: never = setting;
            return exhaustive;
        }
    }
}

export default function SettingsIndex({ settings }: { settings: SettingDefinition[] }) {
    const { flash } = usePage<SharedPageProps>().props;
    const form = useForm<SettingValues>(Object.fromEntries(settings.map((setting) => [setting.key, setting.value])));
    const sections = [...new Set(settings.map((setting) => setting.section))];

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
                {sections.map((section) => (
                    <section key={section} aria-labelledby={inputId(section, 'heading')} className="flex flex-col gap-6">
                        <h2 id={inputId(section, 'heading')} className="text-lg font-bold text-body">
                            {section}
                        </h2>
                        {settings
                            .filter((setting) => setting.section === section)
                            .map((setting) => (
                                <SettingField
                                    key={setting.key}
                                    setting={setting}
                                    value={form.data[setting.key] ?? setting.value}
                                    error={form.errors[`settings.${setting.key}`]}
                                    onChange={(value) => form.setData(setting.key, value)}
                                />
                            ))}
                    </section>
                ))}
                <button type="submit" disabled={form.processing} className="button-primary w-fit min-h-11 px-5">
                    Save settings
                </button>
            </form>
        </WorkspaceLayout>
    );
}
