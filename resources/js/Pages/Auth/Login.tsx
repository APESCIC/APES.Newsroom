import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import AuthCard from '../../Components/Auth/AuthCard';

interface LoginProps {
    staffLoginUrl: string | null;
    staffLoginLabel: string | null;
}

const DEV_ROLES = [
    { key: 'public', label: 'Public' },
    { key: 'staff', label: 'Staff' },
    { key: 'admin', label: 'Admin' },
    { key: 'super_admin', label: 'Super admin' },
] as const;

export default function Login({ staffLoginUrl, staffLoginLabel }: LoginProps) {
    const { devTools } = usePage<{ devTools?: boolean }>().props;
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/login', {
            onFinish: () => reset('password'),
        });
    };

    return (
        <>
            <Head title="Log in" />
            <AuthCard title="Log in">
                {staffLoginUrl && (
                    <div className="mb-6 flex flex-col gap-2">
                        <a href={staffLoginUrl} className="button-secondary w-full">
                            {staffLoginLabel ?? 'Staff sign in'}
                        </a>
                        <p className="text-center text-xs text-muted">
                            Staff must use the button above. Public accounts can sign in below.
                        </p>
                    </div>
                )}
                {devTools && (
                    <div className="mb-6 flex flex-col gap-2 rounded-control border border-warning bg-warning-mist p-3">
                        <p className="text-xs font-bold tracking-wide text-warning uppercase">Local role preview</p>
                        <div className="flex flex-wrap gap-1.5">
                            {DEV_ROLES.map((role) => (
                                <button
                                    key={role.key}
                                    type="button"
                                    onClick={() => router.post(`/_dev/login/${role.key}`)}
                                    className="button-secondary px-2.5 py-1 text-xs"
                                >
                                    {role.label}
                                </button>
                            ))}
                        </div>
                    </div>
                )}
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div>
                        <label htmlFor="email" className="text-sm font-bold text-body">Email</label>
                        <input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} required autoFocus className="form-input mt-1" />
                        {errors.email && <p className="text-sm text-danger">{errors.email}</p>}
                    </div>
                    <div>
                        <label htmlFor="password" className="text-sm font-bold text-body">Password</label>
                        <input
                            id="password"
                            type="password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            required
                            className="form-input mt-1"
                        />
                        {errors.password && <p className="text-sm text-danger">{errors.password}</p>}
                    </div>
                    <button type="submit" disabled={processing} className="button-primary">
                        Log in
                    </button>
                </form>
                <p className="mt-6 flex flex-col gap-1 text-sm text-muted">
                    <Link href="/login/magic-link" className="text-teal-deep hover:underline">Email me a sign-in link instead</Link>
                    <Link href="/forgot-password" className="text-teal-deep hover:underline">Forgot your password?</Link>
                    <span>
                        Need an account? <Link href="/register" className="text-teal-deep hover:underline">Register</Link>
                    </span>
                </p>
            </AuthCard>
        </>
    );
}
