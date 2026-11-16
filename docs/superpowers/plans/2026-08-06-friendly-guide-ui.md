# Friendly Guide UI Implementation Plan

> **Historical:** This #27 plan is retained for provenance but was superseded
> by the stakeholder-approved [Direction A system](../../design/direction-a-ui.md)
> under #31 and #32. Do not use its emoji or chunky-shadow guidance for new UI.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the approved Friendly Guide public chrome, split-desk homepage, and role-gated Account-menu Admin/Staff links (#27).

**Architecture:** Add shared Inertia auth capability flags; introduce a `PublicLayout` (header + AccountMenu + footer) and homepage desk/trail components; restyle tokens in Tailwind v4 `@theme`. Keep article long-form calm. Admin hub defaults to `/admin/moderation`; Staff desk to `/staff/posts`. Channel icons are emoji placeholders keyed by slug.

**Tech Stack:** Laravel 13, Inertia React, Tailwind CSS v4, PHPUnit feature tests, TypeScript.

**Spec:** `docs/superpowers/specs/2026-08-06-friendly-guide-ui-design.md`  
**Issue:** #27  
**Branch:** `cursor/feature/27-friendly-guide-ui`

## Global Constraints

- Personality: Friendly Guide only (trail tiles, chunky offset shadows) — no purple gradients, cream+terracotta kitsch, or broadsheet hairlines.
- Colours: keep `--color-apes-primary` `#2D6A4F`, `--color-shelter-accent` `#BC6C25`, `--color-clinic-accent` `#457B9D`.
- Admin entry: Account menu only; never a persistent public header Admin button.
- Admin hub URL: `/admin/moderation`. Staff desk URL: `/staff/posts`.
- Show Admin menu item only when `auth.can.accessAdmin`; Staff when `auth.can.accessStaff`.
- Never show Admin/Staff links to `public`-only users.
- Honour `prefers-reduced-motion` for hover/shadow motion.
- WCAG 2.2 AA: visible focus, ≥44×44px targets, channel meaning via icon + label (not colour alone).
- Scope v1: public chrome, homepage, Account menu, tokens. Defer article redesign, Editor.js, admin tables, custom SVG mascots.
- Do not commit unrelated dirty mailing/engagement files already present in the working tree.

## File structure

| File | Responsibility |
|------|----------------|
| `resources/css/app.css` | Page tint, radius, chunky-shadow utilities |
| `docs/design/tokens.md` | Document new tokens |
| `resources/js/types/page.ts` | Shared Inertia `auth` prop types |
| `app/Http/Middleware/HandleInertiaRequests.php` | Share `auth.can.accessStaff` / `accessAdmin` |
| `tests/Feature/SharedAuthPropsTest.php` | Assert shared capability flags by role |
| `resources/js/Components/Layout/AccountMenu.tsx` | Role-gated disclosure menu |
| `resources/js/Components/Layout/SiteFooter.tsx` | Legal/mailing footer links |
| `resources/js/Components/Layout/PublicLayout.tsx` | Skip link, header, brand, children, footer |
| `resources/js/Components/Home/ChannelTrailTile.tsx` | Channel trail tile |
| `resources/js/Components/Home/DeskPanel.tsx` | “On the desk” panel + story rows |
| `resources/js/channelMeta.ts` | Slug → icon, hint, accent class map |
| `resources/js/Pages/home.tsx` | Split-desk homepage |
| `resources/js/Pages/{Search,Channels,Articles,Archives,Mailing/Signup,Legal}/*` | Wrap with `PublicLayout` |
| `docs/design/component-inventory.md` | Register new components |
| `tests/Feature/HomePageTest.php` | Homepage still renders; channels/featured shape |

---

### Task 1: Friendly Guide design tokens

**Files:**
- Modify: `resources/css/app.css`
- Modify: `docs/design/tokens.md`
- Test: visual/CSS — verified by `npm run build` (no JS unit test suite)

**Interfaces:**
- Consumes: existing `@theme` colours in `app.css`
- Produces: `--color-page-tint`, `--radius-tile`, utility classes `.shadow-chunky-apes`, `.shadow-chunky-shelter`, `.shadow-chunky-clinic`, `.shadow-chunky-ink`, and `bg-page-tint` via theme colour

- [ ] **Step 1: Extend `@theme` and utilities in `app.css`**

Add inside `@theme { ... }`:

```css
--color-page-tint: #F7FBF9;
--radius-tile: 1rem;
```

Append after the `@theme` block:

```css
.shadow-chunky-apes {
    box-shadow: 4px 4px 0 var(--color-apes-primary);
}
.shadow-chunky-shelter {
    box-shadow: 4px 4px 0 var(--color-shelter-accent);
}
.shadow-chunky-clinic {
    box-shadow: 4px 4px 0 var(--color-clinic-accent);
}
.shadow-chunky-ink {
    box-shadow: 4px 4px 0 var(--color-neutral-900);
}

@media (prefers-reduced-motion: reduce) {
    .shadow-chunky-apes,
    .shadow-chunky-shelter,
    .shadow-chunky-clinic,
    .shadow-chunky-ink {
        transition: none;
    }
}
```

- [ ] **Step 2: Document tokens in `docs/design/tokens.md`**

Under Colour, add `--color-page-tint` `#F7FBF9` (page backgrounds).  
Under a new **Friendly Guide surfaces** subsection, document `--radius-tile` `1rem` and the four `.shadow-chunky-*` utilities. Note: chunky shadows are for trail tiles / desk panels only.

- [ ] **Step 3: Verify CSS builds**

Run: `npm run build`  
Expected: Vite build succeeds (exit 0).

- [ ] **Step 4: Commit**

```bash
git add resources/css/app.css docs/design/tokens.md
git commit -m "feat: add Friendly Guide surface tokens for #27"
```

---

### Task 2: Share auth capability flags

**Files:**
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Create: `resources/js/types/page.ts`
- Create: `tests/Feature/SharedAuthPropsTest.php`

**Interfaces:**
- Consumes: `App\Enums\Role`, `$request->user()?->role`
- Produces: shared Inertia props

```ts
// resources/js/types/page.ts
export type AuthUser = {
    id: number;
    name: string;
    email: string;
    role: 'public' | 'staff' | 'admin';
};

// Historic note: an earlier draft included 'super_admin'. That role was merged into
// 'admin' in v1.28.0 (#713); keep only the three live roles above.

export type SharedAuth = {
    user: AuthUser | null;
    can: {
        accessStaff: boolean;
        accessAdmin: boolean;
    };
};

export type SharedPageProps = {
    appName: string;
    auth: SharedAuth;
    devTools?: boolean;
    flash?: { status?: string | null };
};
```

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SharedAuthPropsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SharedAuthPropsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_has_no_staff_or_admin_access_flags(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user', null)
                ->where('auth.can.accessStaff', false)
                ->where('auth.can.accessAdmin', false));
    }

    public function test_public_user_has_no_staff_or_admin_access_flags(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.role', 'public')
                ->where('auth.can.accessStaff', false)
                ->where('auth.can.accessAdmin', false));
    }

    public function test_staff_can_access_staff_but_not_admin(): void
    {
        $user = User::factory()->staff()->create();

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.can.accessStaff', true)
                ->where('auth.can.accessAdmin', false));
    }

    public function test_admin_can_access_staff_and_admin(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.can.accessStaff', true)
                ->where('auth.can.accessAdmin', true));
    }

    // Historic draft: test_super_admin_can_access_staff_and_admin used
    // User::factory()->superAdmin(). Super admin was merged into admin in
    // v1.28.0 (#713); cover the same access with an admin user instead.
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SharedAuthPropsTest`  
Expected: FAIL (missing `auth.can.*` props).

- [ ] **Step 3: Implement shared props + types**

Update `HandleInertiaRequests::share()` auth block to:

```php
use App\Enums\Role;

// inside share():
'auth' => [
    'user' => $request->user()?->only(['id', 'name', 'email', 'role']),
    'can' => [
        'accessStaff' => $request->user()?->role->atLeast(Role::Staff) ?? false,
        'accessAdmin' => $request->user()?->role->atLeast(Role::Admin) ?? false,
    ],
],
```

Create `resources/js/types/page.ts` with the types listed in **Interfaces** above.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=SharedAuthPropsTest`  
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Middleware/HandleInertiaRequests.php resources/js/types/page.ts tests/Feature/SharedAuthPropsTest.php
git commit -m "feat: share staff/admin capability flags with Inertia for #27"
```

---

### Task 3: PublicLayout, AccountMenu, SiteFooter

**Files:**
- Create: `resources/js/Components/Layout/AccountMenu.tsx`
- Create: `resources/js/Components/Layout/SiteFooter.tsx`
- Create: `resources/js/Components/Layout/PublicLayout.tsx`
- Test: `npm run typecheck` (React has no unit runner; capability gating covered by Task 2 props)

**Interfaces:**
- Consumes: `SharedPageProps` via `usePage<SharedPageProps>()`
- Produces:

```tsx
// PublicLayout props
type PublicLayoutProps = {
    children: React.ReactNode;
    title?: string; // optional; pages may still set <Head> themselves
};
```

Account menu URLs (fixed):
- Profile → `/account`
- Admin panel → `/admin/moderation` (only if `auth.can.accessAdmin`)
- Staff desk → `/staff/posts` (only if `auth.can.accessStaff`)
- Sign out → `POST /logout` via Inertia `<Link method="post" href="/logout" as="button">`
- Guests: links to `/login` and `/register`

- [ ] **Step 1: Create `AccountMenu.tsx`**

```tsx
import { Link, usePage } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import type { SharedPageProps } from '../../types/page';

export default function AccountMenu() {
    const { auth } = usePage<SharedPageProps>().props;
    const [open, setOpen] = useState(false);
    const menuId = useId();
    const rootRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setOpen(false);
        };
        const onClick = (e: MouseEvent) => {
            if (rootRef.current && !rootRef.current.contains(e.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('keydown', onKey);
        document.addEventListener('mousedown', onClick);
        return () => {
            document.removeEventListener('keydown', onKey);
            document.removeEventListener('mousedown', onClick);
        };
    }, [open]);

    if (!auth.user) {
        return (
            <div className="flex items-center gap-3 text-sm">
                <Link href="/login" className="text-neutral-600 hover:text-neutral-900">
                    Login
                </Link>
                <Link
                    href="/register"
                    className="rounded-lg border border-apes-primary bg-white px-3 py-2 font-semibold text-apes-primary"
                >
                    Register
                </Link>
            </div>
        );
    }

    return (
        <div className="relative" ref={rootRef}>
            <button
                type="button"
                className="min-h-11 rounded-lg border border-apes-primary bg-[#e8f2ec] px-3 py-2 text-sm font-bold text-[#1b4332]"
                aria-expanded={open}
                aria-controls={menuId}
                onClick={() => setOpen((v) => !v)}
            >
                You ▾
            </button>
            {open && (
                <div
                    id={menuId}
                    role="menu"
                    className="absolute right-0 z-20 mt-2 min-w-[10rem] rounded-xl border-2 border-neutral-900 bg-white py-1 shadow-chunky-ink"
                >
                    <Link
                        href="/account"
                        role="menuitem"
                        className="block px-3 py-2 text-sm text-neutral-600 hover:bg-neutral-100"
                        onClick={() => setOpen(false)}
                    >
                        Profile
                    </Link>
                    {auth.can.accessAdmin && (
                        <Link
                            href="/admin/moderation"
                            role="menuitem"
                            className="block bg-[#e8f2ec] px-3 py-2 text-sm font-bold text-[#1b4332] hover:bg-[#d8e8df]"
                            onClick={() => setOpen(false)}
                        >
                            Admin panel
                        </Link>
                    )}
                    {auth.can.accessStaff && (
                        <Link
                            href="/staff/posts"
                            role="menuitem"
                            className="block px-3 py-2 text-sm text-neutral-600 hover:bg-neutral-100"
                            onClick={() => setOpen(false)}
                        >
                            Staff desk
                        </Link>
                    )}
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        role="menuitem"
                        className="block w-full px-3 py-2 text-left text-sm text-neutral-600 hover:bg-neutral-100"
                        onClick={() => setOpen(false)}
                    >
                        Sign out
                    </Link>
                </div>
            )}
        </div>
    );
}
```

- [ ] **Step 2: Create `SiteFooter.tsx`**

```tsx
import { Link } from '@inertiajs/react';

export default function SiteFooter() {
    return (
        <footer className="border-t border-neutral-200 py-8">
            <div className="mx-auto flex max-w-5xl flex-wrap gap-4 px-6 text-sm text-neutral-600">
                <Link href="/legal/privacy">Privacy</Link>
                <Link href="/legal/cookies">Cookies</Link>
                <Link href="/legal/rights">Your rights</Link>
                <Link href="/mailing/signup">Mailing lists</Link>
            </div>
        </footer>
    );
}
```

- [ ] **Step 3: Create `PublicLayout.tsx`**

```tsx
import { Link } from '@inertiajs/react';
import AccountMenu from './AccountMenu';
import SiteFooter from './SiteFooter';

export default function PublicLayout({ children }: { children: React.ReactNode }) {
    return (
        <div className="min-h-screen bg-page-tint text-neutral-900">
            <a href="#main-content" className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2 focus:outline focus:outline-2 focus:outline-focus">
                Skip to main content
            </a>
            <header className="border-b border-[#d8e8df] bg-white">
                <div className="mx-auto flex max-w-5xl items-center justify-between gap-4 px-6 py-4">
                    <Link href="/" className="flex items-center gap-2">
                        <span className="flex h-7 w-7 items-center justify-center rounded-[10px] bg-apes-primary text-sm font-extrabold text-white">
                            A
                        </span>
                        <span className="text-base font-semibold text-apes-primary">APES Newsroom</span>
                    </Link>
                    <div className="flex items-center gap-4">
                        <Link href="/search" className="text-sm text-neutral-600 hover:text-neutral-900">
                            Search
                        </Link>
                        <AccountMenu />
                    </div>
                </div>
            </header>
            {children}
            <SiteFooter />
        </div>
    );
}
```

Note: Tailwind maps `--color-page-tint` to `bg-page-tint` via `@theme`. If the class is purged/unknown, use `style={{ backgroundColor: 'var(--color-page-tint)' }}` or `bg-[var(--color-page-tint)]`.

- [ ] **Step 4: Typecheck**

Run: `npm run typecheck`  
Expected: exit 0.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Layout
git commit -m "feat: add PublicLayout and AccountMenu with role-gated links for #27"
```

---

### Task 4: Homepage split-desk layout

**Files:**
- Create: `resources/js/channelMeta.ts`
- Create: `resources/js/Components/Home/ChannelTrailTile.tsx`
- Create: `resources/js/Components/Home/DeskPanel.tsx`
- Modify: `resources/js/Pages/home.tsx`
- Modify: `tests/Feature/HomePageTest.php`

**Interfaces:**
- Consumes: existing home props `{ featured?, recent, channels: { slug, label }[] }`
- Produces: channel meta helper

```ts
// resources/js/channelMeta.ts
export type ChannelAccent = 'apes' | 'shelter' | 'clinic';

export type ChannelMeta = {
    icon: string;
    hint: string;
    accent: ChannelAccent;
    shadowClass: string;
    borderClass: string;
    badgeClass: string;
};

export const channelMetaBySlug: Record<string, ChannelMeta> = {
    'apes-cic': {
        icon: '🌳',
        hint: 'Mission stories',
        accent: 'apes',
        shadowClass: 'shadow-chunky-apes',
        borderClass: 'border-apes-primary',
        badgeClass: 'bg-[#e8f2ec] text-apes-primary',
    },
    'apes-shelter-rescue': {
        icon: '🏠',
        hint: 'Shelter & rescue updates',
        accent: 'shelter',
        shadowClass: 'shadow-chunky-shelter',
        borderClass: 'border-shelter-accent',
        badgeClass: 'bg-[#f5e6d8] text-shelter-accent',
    },
    'apes-pet-care-clinic': {
        icon: '💉',
        hint: 'Clinic notes',
        accent: 'clinic',
        shadowClass: 'shadow-chunky-clinic',
        borderClass: 'border-clinic-accent',
        badgeClass: 'bg-[#e3f0f7] text-clinic-accent',
    },
};
```

- [ ] **Step 1: Extend homepage feature test**

Replace/extend `tests/Feature/HomePageTest.php` to assert channels payload shape (existing controller already sends `slug` + `label`):

```php
public function test_the_homepage_renders_via_inertia(): void
{
    $response = $this->get('/');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('home')
        ->has('channels', 3)
        ->has('channels.0', fn (Assert $c) => $c
            ->has('slug')
            ->has('label')
            ->etc())
        ->has('recent')
        ->has('featured'));
}
```

- [ ] **Step 2: Run test — should still pass against current homepage**

Run: `php artisan test --filter=HomePageTest`  
Expected: PASS (props already exist).

- [ ] **Step 3: Add `channelMeta.ts`, `ChannelTrailTile.tsx`, `DeskPanel.tsx`**

`ChannelTrailTile.tsx`:

```tsx
import { Link } from '@inertiajs/react';
import { channelMetaBySlug } from '../../channelMeta';

export default function ChannelTrailTile({ slug, label }: { slug: string; label: string }) {
    const meta = channelMetaBySlug[slug];
    if (!meta) return null;

    return (
        <Link
            href={`/${slug}`}
            className={`flex min-h-11 flex-1 flex-col justify-center rounded-2xl border-2 bg-white p-3 ${meta.borderClass} ${meta.shadowClass} focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-focus`}
        >
            <span className="text-2xl" aria-hidden="true">
                {meta.icon}
            </span>
            <span className="mt-1 text-sm font-extrabold">{label}</span>
            <span className="text-xs text-neutral-600">{meta.hint}</span>
        </Link>
    );
}
```

`DeskPanel.tsx`: accept `featured` and `recent` with the existing `PostCard` shape; render “On the desk” label, featured block with CTA `Link` to `/articles/{slug}`, dashed divider, then recent rows with channel badge (label text + `badgeClass` from `channelMetaBySlug[channel_slug]`).

- [ ] **Step 4: Rewrite `home.tsx` to use PublicLayout + split desk**

Structure:

```tsx
<PublicLayout>
  <Head title="Home" />
  <main id="main-content" className="mx-auto max-w-5xl px-6 py-10">
    <div className="grid gap-6 md:grid-cols-[2fr_3fr]">
      <div className="order-2 flex flex-col gap-3 md:order-1">
        {channels.map((c) => (
          <ChannelTrailTile key={c.slug} slug={c.slug} label={c.label} />
        ))}
      </div>
      <div className="order-1 md:order-2">
        <DeskPanel featured={featured} recent={recent} />
      </div>
    </div>
  </main>
</PublicLayout>
```

Remove the old inline header/footer from `home.tsx`. Empty state: if no featured and empty recent, DeskPanel shows “No published stories yet.”

- [ ] **Step 5: Run tests + typecheck**

Run:

```bash
php artisan test --filter=HomePageTest
npm run typecheck
```

Expected: both PASS / exit 0.

- [ ] **Step 6: Commit**

```bash
git add resources/js/channelMeta.ts resources/js/Components/Home resources/js/Pages/home.tsx tests/Feature/HomePageTest.php
git commit -m "feat: build Friendly Guide split-desk homepage for #27"
```

---

### Task 5: Wrap remaining public pages in PublicLayout

**Files:**
- Modify: `resources/js/Pages/Search/Index.tsx`
- Modify: `resources/js/Pages/Channels/Show.tsx`
- Modify: `resources/js/Pages/Articles/Show.tsx`
- Modify: `resources/js/Pages/Archives/Author.tsx`
- Modify: `resources/js/Pages/Archives/Tag.tsx`
- Modify: `resources/js/Pages/Archives/Date.tsx`
- Modify: `resources/js/Pages/Mailing/Signup.tsx`
- Modify: `resources/js/Pages/Legal/Privacy.tsx`
- Modify: `resources/js/Pages/Legal/Cookies.tsx`
- Modify: `resources/js/Pages/Legal/Rights.tsx`
- Test: existing feature tests for discovery/mailing/legal + `npm run typecheck`

**Interfaces:**
- Consumes: `PublicLayout` from Task 3
- Produces: consistent chrome on listed public surfaces

- [ ] **Step 1: Wrap each listed page**

For each file:
1. `import PublicLayout from '../../Components/Layout/PublicLayout'` (adjust relative path).
2. Wrap return in `<PublicLayout>…</PublicLayout>`.
3. Ensure the page’s primary `<main>` has `id="main-content"` (move id onto existing main if present).
4. Remove redundant “← Home” / “← APES Newsroom” links only where the header brand already provides navigation home (optional keep on deep archive pages if useful — prefer remove duplicate chrome).
5. Do **not** change article body `.prose` styling beyond wrapping layout.

Example for Search:

```tsx
return (
  <PublicLayout>
    <Head title="Search" />
    <main id="main-content" className="mx-auto max-w-3xl px-6 py-12">
      {/* existing content */}
    </main>
  </PublicLayout>
);
```

- [ ] **Step 2: Run related tests**

Run:

```bash
php artisan test --filter='DiscoveryTest|HomePageTest|MailingConsentTest|SharedAuthPropsTest'
npm run typecheck
npm run lint
```

Expected: all PASS / exit 0. If a legal page lacks a dedicated test, typecheck is sufficient for that file.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages
git commit -m "feat: apply PublicLayout chrome across public pages for #27"
```

---

### Task 6: Sync component inventory + final verification

**Files:**
- Modify: `docs/design/component-inventory.md`
- Test: full suite subset + build

**Interfaces:**
- Consumes: components from Tasks 3–4
- Produces: inventory rows for `PublicLayout`, `AccountMenu`, `SiteFooter`, `ChannelTrailTile`, `DeskPanel`

- [ ] **Step 1: Update component inventory**

In `docs/design/component-inventory.md` Layout section, add/replace:

| Component | Description | States |
|-----------|-------------|--------|
| `PublicLayout` | Skip link, brand header, Search, AccountMenu, page tint, footer | guest, signed-in |
| `AccountMenu` | Login/Register or You disclosure with Profile / Admin / Staff / Sign out | guest, public, staff, admin |
| `SiteFooter` | Privacy, cookies, rights, mailing | default |
| `ChannelTrailTile` | Channel trail with icon, hint, accent border, chunky shadow | default, focus |
| `DeskPanel` | “On the desk” featured + recent stack | empty, with featured |

Note Friendly Guide direction and link to `docs/superpowers/specs/2026-08-06-friendly-guide-ui-design.md`.

- [ ] **Step 2: Final verification**

Run:

```bash
composer test
composer lint
npm run typecheck
npm run lint
npm run build
```

Expected: all succeed. If unrelated dirty mailing/engagement changes cause test failures, stash or leave them unstaged and re-run only:

```bash
php artisan test --filter='SharedAuthPropsTest|HomePageTest|DiscoveryTest|AuthzMatrixTest|DevLoginTest'
```

- [ ] **Step 3: Comment on #27 and commit docs**

```bash
git add docs/design/component-inventory.md
git commit -m "docs: register Friendly Guide components for #27"
```

Then comment on issue #27 with what shipped vs remaining AC.

---

## Spec coverage checklist

| Spec requirement | Task |
|------------------|------|
| Friendly Guide personality / chunky shadows / page tint | 1, 4 |
| Split-desk homepage | 4 |
| Account-menu Admin + Staff (role-gated) | 2, 3 |
| Shared public chrome | 3, 5 |
| Token docs | 1, 6 |
| Accessibility (focus, Escape, targets, reduced motion) | 1, 3, 4 |
| Admin hub `/admin/moderation`, Staff `/staff/posts` | 3 |
| Emoji channel icons | 4 (`channelMeta.ts`) |
| Deferred article/editor redesign | Out of plan |

## Placeholder / consistency self-review

- No TBD steps; Admin/Staff URLs fixed.
- `auth.can.accessStaff` / `accessAdmin` naming consistent across middleware, types, AccountMenu, and tests.
- `PublicLayout` path imports adjust per page depth (`../` vs `../../`).
