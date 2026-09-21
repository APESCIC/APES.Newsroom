import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';

type ReleaseForm = {
    id?: number;
    version: string;
    previous_version: string;
    released_at: string;
    channel: string;
    version_type: string;
    theme: string;
    is_current: boolean;
    is_published: boolean;
    slug: string;
    change_types: string[];
    topic_tags: string[];
    summary: string;
    detailed_changes_text: string;
    affected_areas_text: string;
    version_decision_text: string;
    validation_text: string;
};

const emptyRelease = (releasesInBeta: boolean): ReleaseForm => ({
    version: '',
    previous_version: '',
    released_at: new Date().toISOString().slice(0, 10),
    channel: releasesInBeta ? 'beta' : 'stable',
    version_type: '',
    theme: '',
    is_current: false,
    is_published: false,
    slug: '',
    change_types: [],
    topic_tags: [],
    summary: '',
    detailed_changes_text: '',
    affected_areas_text: '',
    version_decision_text: '',
    validation_text: '',
});

function toggleValue(list: string[], value: string): string[] {
    return list.includes(value) ? list.filter((item) => item !== value) : [...list, value];
}

function FieldError({ message }: { message?: string }) {
    if (!message) {
        return null;
    }

    return <p className="mt-1 text-sm text-red-700">{message}</p>;
}

export default function ReleaseEdit({
    release,
    changeTypes,
    topicTags,
    releasesInBeta = true,
}: {
    release: ReleaseForm | null;
    changeTypes: string[];
    topicTags: string[];
    releasesInBeta?: boolean;
}) {
    const editing = Boolean(release?.id);
    const form = useForm<ReleaseForm>(release ?? emptyRelease(releasesInBeta));

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        if (editing && release?.id) {
            form.put(`/admin/releases/${release.id}`);
            return;
        }
        form.post('/admin/releases');
    };

    return (
        <WorkspaceLayout
            area="Admin"
            active="releases"
            title={editing ? `Edit ${release?.version ?? 'release'}` : 'New release'}
            subtitle="Structured fields match the public Change Log Hub card sections."
            actions={
                <Link href="/admin/releases" className="button-glass inline-flex min-h-11 items-center px-4">
                    Back to list
                </Link>
            }
        >
            <Head title={editing ? 'Edit release' : 'New release'} />
            <form onSubmit={submit} className="mx-auto flex max-w-3xl flex-col gap-6 rounded-card border border-border bg-white p-6">
                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label htmlFor="version" className="text-sm font-semibold">
                            Version
                        </label>
                        <input
                            id="version"
                            className="form-input mt-1 w-full"
                            value={form.data.version}
                            onChange={(e) => form.setData('version', e.target.value)}
                            required
                        />
                        <FieldError message={form.errors.version} />
                    </div>
                    <div>
                        <label htmlFor="previous_version" className="text-sm font-semibold">
                            Previous version
                        </label>
                        <input
                            id="previous_version"
                            className="form-input mt-1 w-full"
                            value={form.data.previous_version}
                            onChange={(e) => form.setData('previous_version', e.target.value)}
                        />
                        <FieldError message={form.errors.previous_version} />
                    </div>
                    <div>
                        <label htmlFor="released_at" className="text-sm font-semibold">
                            Release date
                        </label>
                        <input
                            id="released_at"
                            type="date"
                            className="form-input mt-1 w-full"
                            value={form.data.released_at}
                            onChange={(e) => form.setData('released_at', e.target.value)}
                            required
                        />
                        <FieldError message={form.errors.released_at} />
                    </div>
                    <div>
                        <label htmlFor="channel" className="text-sm font-semibold">
                            Channel
                        </label>
                        <select
                            id="channel"
                            className="form-input mt-1 w-full"
                            value={form.data.channel}
                            onChange={(e) => form.setData('channel', e.target.value)}
                        >
                            <option value="beta">Beta</option>
                            <option value="stable">Stable</option>
                        </select>
                        <FieldError message={form.errors.channel} />
                    </div>
                    <div>
                        <label htmlFor="version_type" className="text-sm font-semibold">
                            Version type label
                        </label>
                        <input
                            id="version_type"
                            className="form-input mt-1 w-full"
                            placeholder={releasesInBeta ? 'patch beta' : 'patch stable'}
                            value={form.data.version_type}
                            onChange={(e) => form.setData('version_type', e.target.value)}
                        />
                        <FieldError message={form.errors.version_type} />
                    </div>
                    <div>
                        <label htmlFor="theme" className="text-sm font-semibold">
                            Theme / highlight
                        </label>
                        <input
                            id="theme"
                            className="form-input mt-1 w-full"
                            placeholder="Change Log Hub"
                            value={form.data.theme}
                            onChange={(e) => form.setData('theme', e.target.value)}
                        />
                        <FieldError message={form.errors.theme} />
                    </div>
                    <div className="sm:col-span-2">
                        <label htmlFor="slug" className="text-sm font-semibold">
                            Deep-link slug
                        </label>
                        <input
                            id="slug"
                            className="form-input mt-1 w-full"
                            placeholder="Auto from version if blank"
                            value={form.data.slug}
                            onChange={(e) => form.setData('slug', e.target.value)}
                        />
                        <FieldError message={form.errors.slug} />
                    </div>
                </div>

                <fieldset>
                    <legend className="text-sm font-semibold">Change types</legend>
                    <div className="mt-2 flex flex-wrap gap-3">
                        {changeTypes.map((type) => (
                            <label key={type} className="inline-flex min-h-11 items-center gap-2 text-sm capitalize">
                                <input
                                    type="checkbox"
                                    checked={form.data.change_types.includes(type)}
                                    onChange={() => form.setData('change_types', toggleValue(form.data.change_types, type))}
                                />
                                {type}
                            </label>
                        ))}
                    </div>
                    <FieldError message={form.errors.change_types} />
                </fieldset>

                <fieldset>
                    <legend className="text-sm font-semibold">Topic tags</legend>
                    <div className="mt-2 flex flex-wrap gap-3">
                        {topicTags.map((tag) => (
                            <label key={tag} className="inline-flex min-h-11 items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={form.data.topic_tags.includes(tag)}
                                    onChange={() => form.setData('topic_tags', toggleValue(form.data.topic_tags, tag))}
                                />
                                {tag}
                            </label>
                        ))}
                    </div>
                    <FieldError message={form.errors.topic_tags} />
                </fieldset>

                <div>
                    <label htmlFor="summary" className="text-sm font-semibold">
                        Summary
                    </label>
                    <textarea
                        id="summary"
                        className="form-input mt-1 min-h-24 w-full"
                        value={form.data.summary}
                        onChange={(e) => form.setData('summary', e.target.value)}
                        required
                    />
                    <FieldError message={form.errors.summary} />
                </div>

                {(
                    [
                        ['detailed_changes_text', 'Detailed changes (one per line)'],
                        ['affected_areas_text', 'Affected areas (one per line)'],
                        ['version_decision_text', 'Version decision (one per line)'],
                        ['validation_text', 'Validation (one per line)'],
                    ] as const
                ).map(([field, label]) => (
                    <div key={field}>
                        <label htmlFor={field} className="text-sm font-semibold">
                            {label}
                        </label>
                        <textarea
                            id={field}
                            className="form-input mt-1 min-h-28 w-full"
                            value={form.data[field]}
                            onChange={(e) => form.setData(field, e.target.value)}
                        />
                        <FieldError message={form.errors[field]} />
                    </div>
                ))}

                <div className="flex flex-wrap gap-6">
                    <label className="inline-flex min-h-11 items-center gap-2 text-sm font-semibold">
                        <input
                            type="checkbox"
                            checked={form.data.is_published}
                            onChange={(e) => form.setData('is_published', e.target.checked)}
                        />
                        Published
                    </label>
                    <label className="inline-flex min-h-11 items-center gap-2 text-sm font-semibold">
                        <input
                            type="checkbox"
                            checked={form.data.is_current}
                            onChange={(e) => form.setData('is_current', e.target.checked)}
                        />
                        Mark as current release
                    </label>
                </div>

                <button type="submit" disabled={form.processing} className="button-primary w-fit min-h-11 px-5">
                    {editing ? 'Save release' : 'Create release'}
                </button>
            </form>
        </WorkspaceLayout>
    );
}
