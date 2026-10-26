<?php

use App\Enums\Channel;
use App\Enums\Role;
use App\Http\Controllers\Account\EmailChangeController as AccountEmailChangeController;
use App\Http\Controllers\Account\MembershipCheckoutController;
use App\Http\Controllers\Account\PasskeyController as AccountPasskeyController;
use App\Http\Controllers\Account\PasswordController as AccountPasswordController;
use App\Http\Controllers\Account\SecurityController as AccountSecurityController;
use App\Http\Controllers\Account\SessionController as AccountSessionController;
use App\Http\Controllers\Account\TwoFactorController as AccountTwoFactorController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\ApiTokenController as AdminApiTokenController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\ContentApiKeyController as AdminContentApiKeyController;
use App\Http\Controllers\Admin\GhostContentImportController;
use App\Http\Controllers\Admin\GhostMembersImportController;
use App\Http\Controllers\Admin\IntegrationController as AdminIntegrationController;
use App\Http\Controllers\Admin\MemberController as AdminMemberController;
use App\Http\Controllers\Admin\ModerationController;
use App\Http\Controllers\Admin\ReleaseController as AdminReleaseController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\UserSecurityController as AdminUserSecurityController;
use App\Http\Controllers\Admin\WebhookController as AdminWebhookController;
use App\Http\Controllers\AnalyticsConsentController;
use App\Http\Controllers\ArchiveController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\ChangeLogHubController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ChannelRssController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\Mailing\CampaignTrackingController;
use App\Http\Controllers\Mailing\ConfirmController;
use App\Http\Controllers\Mailing\NewsletterSignupController;
use App\Http\Controllers\Mailing\PreferenceController;
use App\Http\Controllers\Mailing\SignupController;
use App\Http\Controllers\Mailing\UnsubscribeController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReactionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RssController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\Staff\AiAssistController as StaffAiAssistController;
use App\Http\Controllers\Staff\CampaignController as StaffCampaignController;
use App\Http\Controllers\Staff\MediaController as StaffMediaController;
use App\Http\Controllers\Staff\MediaLibraryController as StaffMediaLibraryController;
use App\Http\Controllers\Staff\MembershipPlanController as StaffMembershipPlanController;
use App\Http\Controllers\Staff\MetricsController as StaffMetricsController;
use App\Http\Controllers\Staff\NewsletterController as StaffNewsletterController;
use App\Http\Controllers\Staff\OfferController as StaffOfferController;
use App\Http\Controllers\Staff\PageController as StaffPageController;
use App\Http\Controllers\Staff\PostController as StaffPostController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\TagRssController;
use App\Support\Integrations\IntegrationDefinitions;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/legal/privacy', [LegalController::class, 'privacy'])->name('legal.privacy');
Route::get('/legal/cookies', [LegalController::class, 'cookies'])->name('legal.cookies');
Route::get('/legal/rights', [LegalController::class, 'rights'])->name('legal.rights');
Route::post('/analytics/consent', [AnalyticsConsentController::class, 'store'])->name('analytics.consent');
Route::get('/change-log-hub', ChangeLogHubController::class)->name('change-log-hub');

Route::get('/health', HealthController::class)->name('health');
Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/rss.xml', [RssController::class, 'index'])->name('rss');

Route::get('/search', [SearchController::class, 'index'])->name('search');
Route::get('/authors/{author}', [ArchiveController::class, 'author'])->name('archives.author');
Route::get('/tags/{slug}', [ArchiveController::class, 'tag'])->name('archives.tag');
Route::get('/tags/{slug}/rss.xml', [TagRssController::class, 'show'])->name('tags.rss');
Route::get('/archive/{year}/{month?}', [ArchiveController::class, 'date'])
    ->whereNumber('year')
    ->whereNumber('month')
    ->name('archives.date');
Route::get('/articles/{slug}', [ArticleController::class, 'show'])->name('articles.show');
Route::get('/pages/{slug}', [PageController::class, 'show'])->name('pages.show');
Route::get('/profiles/{profile}', [ProfileController::class, 'show'])->name('profiles.show');

Route::get('/newsletters/{slug}/signup', [NewsletterSignupController::class, 'show'])->name('newsletters.signup');
Route::post('/newsletters/{slug}/signup', [NewsletterSignupController::class, 'store'])
    ->middleware('throttle:mailing-signup')
    ->name('newsletters.signup.store');
Route::get('/mailing/signup', [SignupController::class, 'show'])->name('mailing.signup');
Route::post('/mailing/signup', [SignupController::class, 'store'])
    ->middleware('throttle:mailing-signup')
    ->name('mailing.signup.store');
Route::get('/mailing/confirm/{token}', ConfirmController::class)->name('mailing.confirm');
Route::get('/mailing/track/open/{recipient}', [CampaignTrackingController::class, 'open'])->name('mailing.track.open');
Route::get('/mailing/track/click/{recipient}', [CampaignTrackingController::class, 'click'])->name('mailing.track.click');
Route::get('/mailing/preferences', [PreferenceController::class, 'showSigned'])->name('mailing.preferences.signed');
Route::post('/mailing/preferences', [PreferenceController::class, 'updateSigned'])->name('mailing.preferences.signed.update');
Route::get('/mailing/unsubscribe', [UnsubscribeController::class, 'show'])->name('mailing.unsubscribe');
Route::post('/mailing/unsubscribe', [UnsubscribeController::class, 'store'])->name('mailing.unsubscribe.store');
Route::post('/mailing/unsubscribe/one-click', [UnsubscribeController::class, 'oneClick'])->name('mailing.unsubscribe.one-click');

Route::get('/{channel}/rss.xml', [ChannelRssController::class, 'show'])
    ->where('channel', Channel::routePattern())
    ->name('channels.rss');
Route::get('/{channel}', [ChannelController::class, 'show'])
    ->where('channel', Channel::routePattern())
    ->name('channels.show');

Route::middleware(['auth', 'verified'])->prefix('account')->name('account.')->group(function () {
    Route::get('/', [AccountController::class, 'show'])->name('show');
    Route::patch('/', [AccountController::class, 'update'])->name('update');
    Route::post('/email/resend', [AccountEmailChangeController::class, 'resend'])
        ->middleware('throttle:email-change')
        ->name('email.resend');
    Route::delete('/email/pending', [AccountEmailChangeController::class, 'destroy'])->name('email.pending.destroy');
    Route::get('/export', [AccountController::class, 'export'])->name('export');
    Route::delete('/', [AccountController::class, 'destroy'])->middleware('auth.recent')->name('destroy');
    Route::get('/security', [AccountSecurityController::class, 'show'])->name('security');
    Route::delete('/sessions/others', [AccountSessionController::class, 'destroyOthers'])
        ->middleware('auth.recent')
        ->name('sessions.destroy-others');
    Route::delete('/sessions/{session}', [AccountSessionController::class, 'destroy'])
        ->whereNumber('session')
        ->name('sessions.destroy');
    Route::put('/password', [AccountPasswordController::class, 'update'])
        ->middleware('throttle:password-change')
        ->name('password.update');
    Route::post('/two-factor', [AccountTwoFactorController::class, 'store'])
        ->middleware('auth.recent')
        ->name('two-factor.store');
    Route::post('/two-factor/confirm', [AccountTwoFactorController::class, 'confirm'])
        ->middleware(['auth.recent', 'throttle:two-factor-setup'])
        ->name('two-factor.confirm');
    Route::post('/two-factor/recovery-codes', [AccountTwoFactorController::class, 'regenerateRecoveryCodes'])
        ->middleware('auth.recent')
        ->name('two-factor.recovery-codes');
    Route::delete('/two-factor', [AccountTwoFactorController::class, 'destroy'])
        ->middleware('auth.recent')
        ->name('two-factor.destroy');
    Route::post('/passkeys/options', [AccountPasskeyController::class, 'options'])
        ->middleware(['auth.recent', 'throttle:passkey-manage'])
        ->name('passkeys.options');
    Route::post('/passkeys', [AccountPasskeyController::class, 'store'])
        ->middleware(['auth.recent', 'throttle:passkey-manage'])
        ->name('passkeys.store');
    Route::patch('/passkeys/{passkey}', [AccountPasskeyController::class, 'update'])
        ->whereNumber('passkey')
        ->name('passkeys.update');
    Route::delete('/passkeys/{passkey}', [AccountPasskeyController::class, 'destroy'])
        ->whereNumber('passkey')
        ->middleware('auth.recent')
        ->name('passkeys.destroy');
    Route::get('/mailing', [PreferenceController::class, 'showAccount'])->name('mailing');
    Route::post('/mailing', [PreferenceController::class, 'updateAccount'])->name('mailing.update');
    Route::get('/public-profile', [ProfileController::class, 'edit'])->name('public-profile');
    Route::post('/public-profile', [ProfileController::class, 'update'])->name('public-profile.update');
    Route::post('/membership/checkout', [MembershipCheckoutController::class, 'checkout'])->name('membership.checkout');
    Route::post('/membership/portal', [MembershipCheckoutController::class, 'portal'])->name('membership.portal');
    Route::get('/membership/success', [MembershipCheckoutController::class, 'success'])->name('membership.success');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('/articles/{slug}/comments', [CommentController::class, 'store'])->name('articles.comments.store');
    Route::patch('/comments/{comment}', [CommentController::class, 'update'])->name('comments.update');
    Route::post('/articles/{slug}/reactions', [ReactionController::class, 'toggle'])->name('articles.reactions.toggle');
    Route::post('/reports', [ReportController::class, 'store'])->name('reports.store');
});

Route::middleware(['auth', 'verified', 'role:'.Role::Admin->value])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/moderation', [ModerationController::class, 'index'])->name('moderation.index');
        Route::post('/moderation/profiles/{profile}', [ModerationController::class, 'moderateProfile'])->name('moderation.profiles');
        Route::post('/moderation/comments/{comment}', [ModerationController::class, 'moderateComment'])->name('moderation.comments');
        Route::post('/moderation/reports/{report}', [ModerationController::class, 'resolveReport'])->name('moderation.reports');
        Route::post('/moderation/comments/{comment}/restore', [ModerationController::class, 'restoreComment'])->name('moderation.comments.restore');
        Route::get('/releases', [AdminReleaseController::class, 'index'])->name('releases.index');
        Route::get('/releases/new', [AdminReleaseController::class, 'create'])->name('releases.create');
        Route::post('/releases', [AdminReleaseController::class, 'store'])->name('releases.store');
        Route::get('/releases/{release}/edit', [AdminReleaseController::class, 'edit'])->name('releases.edit');
        Route::put('/releases/{release}', [AdminReleaseController::class, 'update'])->name('releases.update');
        Route::delete('/releases/{release}', [AdminReleaseController::class, 'destroy'])->name('releases.destroy');
        Route::get('/imports/ghost-members', [GhostMembersImportController::class, 'index'])->name('imports.ghost-members');
        Route::post('/imports/ghost-members', [GhostMembersImportController::class, 'upload'])->name('imports.ghost-members.upload');
        Route::get('/imports/ghost-members/{run}/report', [GhostMembersImportController::class, 'report'])->name('imports.ghost-members.report');
        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/settings', [SettingsController::class, 'update'])->middleware('auth.recent')->name('settings.update');
        Route::prefix('/settings/integrations')->name('settings.integrations.')->group(function () {
            $providers = IntegrationDefinitions::providerNames();

            Route::get('/', [AdminIntegrationController::class, 'index'])->name('index');
            Route::post('/{provider}/test', [AdminIntegrationController::class, 'test'])
                ->whereIn('provider', $providers)
                ->middleware('throttle:integration-keys')
                ->name('test');
            Route::middleware(['role:'.Role::SuperAdmin->value, 'auth.recent:300', 'throttle:integration-keys'])->group(function () use ($providers) {
                Route::put('/{provider}/{key}', [AdminIntegrationController::class, 'update'])
                    ->whereIn('provider', $providers)
                    ->where('key', '[a-z_]+')
                    ->name('update');
                Route::delete('/{provider}/{key}', [AdminIntegrationController::class, 'destroy'])
                    ->whereIn('provider', $providers)
                    ->where('key', '[a-z_]+')
                    ->name('destroy');
            });
        });
        Route::get('/audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');
        Route::get('/audit-log/export', [AuditLogController::class, 'export'])->middleware('auth.recent')->name('audit-log.export');
        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [AdminUserController::class, 'show'])->whereNumber('user')->name('users.show');
        Route::middleware('auth.recent')->group(function () {
            Route::post('/users/{user}/lock', [AdminUserSecurityController::class, 'lock'])->whereNumber('user')->name('users.lock');
            Route::post('/users/{user}/unlock', [AdminUserSecurityController::class, 'unlock'])->whereNumber('user')->name('users.unlock');
            Route::post('/users/{user}/sign-out', [AdminUserSecurityController::class, 'signOut'])->whereNumber('user')->name('users.sign-out');
            Route::post('/users/{user}/password-reset', [AdminUserSecurityController::class, 'forcePasswordReset'])->whereNumber('user')->name('users.password-reset');
            Route::post('/users/{user}/two-factor/reset', [AdminUserSecurityController::class, 'resetTwoFactor'])->whereNumber('user')->name('users.two-factor.reset');
            Route::post('/users/{user}/passkeys/remove', [AdminUserSecurityController::class, 'removePasskeys'])->whereNumber('user')->name('users.passkeys.remove');
            Route::patch('/users/{user}/role', [AdminUserSecurityController::class, 'changeRole'])->whereNumber('user')->name('users.role');
        });
        Route::get('/api-tokens', [AdminApiTokenController::class, 'index'])->name('api-tokens.index');
        Route::middleware('auth.recent')->group(function () {
            Route::post('/api-tokens', [AdminApiTokenController::class, 'store'])->name('api-tokens.store');
            Route::delete('/api-tokens/{apiToken}', [AdminApiTokenController::class, 'destroy'])->whereNumber('apiToken')->name('api-tokens.destroy');
        });
        Route::get('/content-api-keys', [AdminContentApiKeyController::class, 'index'])->name('content-api-keys.index');
        Route::middleware('auth.recent')->group(function () {
            Route::post('/content-api-keys', [AdminContentApiKeyController::class, 'store'])->name('content-api-keys.store');
            Route::delete('/content-api-keys/{contentApiKey}', [AdminContentApiKeyController::class, 'destroy'])->whereNumber('contentApiKey')->name('content-api-keys.destroy');
        });
        Route::get('/members', AdminMemberController::class)->name('members.index');
        Route::get('/webhooks', [AdminWebhookController::class, 'index'])->name('webhooks.index');
        Route::middleware('auth.recent')->group(function () {
            Route::post('/webhooks', [AdminWebhookController::class, 'store'])->name('webhooks.store');
            Route::patch('/webhooks/{webhook}', [AdminWebhookController::class, 'update'])->name('webhooks.update');
            Route::delete('/webhooks/{webhook}', [AdminWebhookController::class, 'destroy'])->name('webhooks.destroy');
        });
    });

Route::middleware(['auth', 'verified', 'role:'.Role::Staff->value])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/imports/ghost-content', [GhostContentImportController::class, 'index'])->name('imports.ghost-content');
        Route::post('/imports/ghost-content', [GhostContentImportController::class, 'upload'])->name('imports.ghost-content.upload');
        Route::post('/imports/ghost-content/{run}/confirm', [GhostContentImportController::class, 'confirm'])->name('imports.ghost-content.confirm');
        Route::get('/imports/ghost-content/{run}/report', [GhostContentImportController::class, 'report'])->name('imports.ghost-content.report');
    });

Route::middleware(['auth', 'verified', 'role:'.Role::Staff->value])
    ->prefix('staff')
    ->name('staff.')
    ->group(function () {
        Route::get('/posts', [StaffPostController::class, 'index'])->name('posts.index');
        Route::get('/posts/review', [StaffPostController::class, 'reviewQueue'])->name('posts.review');
        Route::get('/posts/new', [StaffPostController::class, 'create'])->name('posts.create');
        Route::post('/posts', [StaffPostController::class, 'store'])->name('posts.store');
        Route::get('/posts/{post}/edit', [StaffPostController::class, 'edit'])->name('posts.edit');
        Route::patch('/posts/{post}', [StaffPostController::class, 'update'])->name('posts.update');
        Route::delete('/posts/{post}', [StaffPostController::class, 'destroy'])->name('posts.destroy');
        Route::post('/posts/{post}/submit', [StaffPostController::class, 'submitForReview'])->name('posts.submit');
        Route::post('/posts/{post}/reject', [StaffPostController::class, 'reject'])->name('posts.reject');
        Route::post('/posts/{post}/schedule', [StaffPostController::class, 'schedule'])->name('posts.schedule');
        Route::post('/posts/{post}/publish', [StaffPostController::class, 'publish'])->name('posts.publish');
        Route::post('/posts/{post}/unpublish', [StaffPostController::class, 'unpublish'])->name('posts.unpublish');
        Route::post('/posts/{post}/revisions/{revision}/restore', [StaffPostController::class, 'restoreRevision'])->name('posts.revisions.restore');
        Route::get('/posts/{post}/preview', [StaffPostController::class, 'preview'])->name('posts.preview');
        Route::get('/posts/{post}/campaign', [StaffCampaignController::class, 'preview'])->name('posts.campaign.preview');
        Route::post('/posts/{post}/campaign/test-send', [StaffCampaignController::class, 'testSend'])->name('posts.campaign.test');

        Route::get('/metrics', StaffMetricsController::class)->name('metrics.index');
        Route::permanentRedirect('/members', '/admin/members')->name('members.legacy');
        Route::permanentRedirect('/webhooks', '/admin/webhooks')->name('webhooks.legacy');

        Route::get('/newsletters', [StaffNewsletterController::class, 'index'])->name('newsletters.index');
        Route::get('/newsletters/new', [StaffNewsletterController::class, 'create'])->name('newsletters.create');
        Route::post('/newsletters', [StaffNewsletterController::class, 'store'])->name('newsletters.store');
        Route::get('/newsletters/{newsletter}/edit', [StaffNewsletterController::class, 'edit'])->name('newsletters.edit');
        Route::patch('/newsletters/{newsletter}', [StaffNewsletterController::class, 'update'])->name('newsletters.update');
        Route::post('/newsletters/{newsletter}/archive', [StaffNewsletterController::class, 'archive'])->name('newsletters.archive');
        Route::post('/newsletters/{newsletter}/segments', [StaffNewsletterController::class, 'storeSegment'])->name('newsletters.segments.store');
        Route::post('/newsletters/{newsletter}/restore', [StaffNewsletterController::class, 'restore'])->name('newsletters.restore');
        Route::post('/newsletters/{newsletter}/verify-sender', [StaffNewsletterController::class, 'verifySender'])
            ->middleware('throttle:10,1')
            ->name('newsletters.verify-sender');

        Route::get('/membership-plans', [StaffMembershipPlanController::class, 'index'])->name('membership-plans.index');
        Route::patch('/membership-plans/{plan}', [StaffMembershipPlanController::class, 'update'])->name('membership-plans.update');

        Route::get('/offers', [StaffOfferController::class, 'index'])->name('offers.index');
        Route::post('/offers', [StaffOfferController::class, 'store'])->name('offers.store');
        Route::patch('/offers/{offer}', [StaffOfferController::class, 'update'])->name('offers.update');

        Route::get('/pages', [StaffPageController::class, 'index'])->name('pages.index');
        Route::get('/pages/new', [StaffPageController::class, 'create'])->name('pages.create');
        Route::post('/pages', [StaffPageController::class, 'store'])->name('pages.store');
        Route::get('/pages/{page}/edit', [StaffPageController::class, 'edit'])->name('pages.edit');
        Route::patch('/pages/{page}', [StaffPageController::class, 'update'])->name('pages.update');
        Route::delete('/pages/{page}', [StaffPageController::class, 'destroy'])->name('pages.destroy');
        Route::post('/pages/{page}/publish', [StaffPageController::class, 'publish'])->name('pages.publish');
        Route::post('/pages/{page}/unpublish', [StaffPageController::class, 'unpublish'])->name('pages.unpublish');
        Route::get('/pages/{page}/preview', [StaffPageController::class, 'preview'])->name('pages.preview');

        Route::post('/media/by-url', [StaffMediaController::class, 'byUrl'])->name('media.by-url');
        Route::post('/media/link-meta', [StaffMediaController::class, 'linkMeta'])->name('media.link-meta');
        Route::post('/media/upload', [StaffMediaController::class, 'upload'])->name('media.upload');
        Route::post('/media/hero', [StaffMediaController::class, 'heroUpload'])
            ->middleware('throttle:media-upload')
            ->name('media.hero.upload');
        Route::get('/media/{asset}', [StaffMediaController::class, 'show'])->whereNumber('asset')->name('media.show');
        Route::get('/media', [StaffMediaLibraryController::class, 'index'])->name('media.index');
        Route::get('/media/library', [StaffMediaLibraryController::class, 'picker'])->name('media.library');
        Route::get('/media/{asset}/usage', [StaffMediaLibraryController::class, 'usage'])->whereNumber('asset')->name('media.usage');
        Route::delete('/media/{asset}', [StaffMediaLibraryController::class, 'destroy'])->whereNumber('asset')->name('media.destroy');
        Route::get('/media/unsplash', [StaffMediaController::class, 'unsplashSearch'])->name('media.unsplash.search');
        Route::post('/media/unsplash/select', [StaffMediaController::class, 'unsplashSelect'])->name('media.unsplash.select');
        Route::get('/ai/status', [StaffAiAssistController::class, 'status'])->name('ai.status');
        Route::post('/ai/suggest', StaffAiAssistController::class)->name('ai.suggest');
    });

require __DIR__.'/auth.php';

if (app()->environment('local')) {
    require __DIR__.'/dev.php';
}
