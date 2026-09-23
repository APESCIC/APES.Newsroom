<?php

use App\Enums\Role;
use App\Http\Controllers\Account\MembershipCheckoutController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\GhostContentImportController;
use App\Http\Controllers\Admin\GhostMembersImportController;
use App\Http\Controllers\Admin\ModerationController;
use App\Http\Controllers\Admin\ReleaseController as AdminReleaseController;
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
use App\Http\Controllers\Staff\CampaignController as StaffCampaignController;
use App\Http\Controllers\Staff\MediaController as StaffMediaController;
use App\Http\Controllers\Staff\MemberController as StaffMemberController;
use App\Http\Controllers\Staff\MembershipPlanController as StaffMembershipPlanController;
use App\Http\Controllers\Staff\MetricsController as StaffMetricsController;
use App\Http\Controllers\Staff\NewsletterController as StaffNewsletterController;
use App\Http\Controllers\Staff\OfferController as StaffOfferController;
use App\Http\Controllers\Staff\PageController as StaffPageController;
use App\Http\Controllers\Staff\PostController as StaffPostController;
use App\Http\Controllers\Staff\WebhookController as StaffWebhookController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\TagRssController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/legal/privacy', [LegalController::class, 'privacy'])->name('legal.privacy');
Route::get('/legal/cookies', [LegalController::class, 'cookies'])->name('legal.cookies');
Route::get('/legal/rights', [LegalController::class, 'rights'])->name('legal.rights');
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
Route::post('/newsletters/{slug}/signup', [NewsletterSignupController::class, 'store'])->name('newsletters.signup.store');
Route::get('/mailing/signup', [SignupController::class, 'show'])->name('mailing.signup');
Route::post('/mailing/signup', [SignupController::class, 'store'])->name('mailing.signup.store');
Route::get('/mailing/confirm/{token}', ConfirmController::class)->name('mailing.confirm');
Route::get('/mailing/track/open/{recipient}', [CampaignTrackingController::class, 'open'])->name('mailing.track.open');
Route::get('/mailing/track/click/{recipient}', [CampaignTrackingController::class, 'click'])->name('mailing.track.click');
Route::get('/mailing/preferences', [PreferenceController::class, 'showSigned'])->name('mailing.preferences.signed');
Route::post('/mailing/preferences', [PreferenceController::class, 'updateSigned'])->name('mailing.preferences.signed.update');
Route::get('/mailing/unsubscribe', [UnsubscribeController::class, 'show'])->name('mailing.unsubscribe');
Route::post('/mailing/unsubscribe', [UnsubscribeController::class, 'store'])->name('mailing.unsubscribe.store');
Route::post('/mailing/unsubscribe/one-click', [UnsubscribeController::class, 'oneClick'])->name('mailing.unsubscribe.one-click');

Route::get('/{channel}/rss.xml', [ChannelRssController::class, 'show'])
    ->where('channel', 'apes-cic|apes-shelter-rescue|apes-pet-care-clinic')
    ->name('channels.rss');
Route::get('/{channel}', [ChannelController::class, 'show'])
    ->where('channel', 'apes-cic|apes-shelter-rescue|apes-pet-care-clinic')
    ->name('channels.show');

Route::middleware(['auth', 'verified'])->prefix('account')->name('account.')->group(function () {
    Route::get('/', [AccountController::class, 'show'])->name('show');
    Route::patch('/', [AccountController::class, 'update'])->name('update');
    Route::get('/export', [AccountController::class, 'export'])->name('export');
    Route::delete('/', [AccountController::class, 'destroy'])->name('destroy');
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
        Route::get('/members', StaffMemberController::class)->name('members.index');
        Route::get('/webhooks', [StaffWebhookController::class, 'index'])->name('webhooks.index');
        Route::post('/webhooks', [StaffWebhookController::class, 'store'])->name('webhooks.store');
        Route::patch('/webhooks/{webhook}', [StaffWebhookController::class, 'update'])->name('webhooks.update');
        Route::delete('/webhooks/{webhook}', [StaffWebhookController::class, 'destroy'])->name('webhooks.destroy');

        Route::get('/newsletters', [StaffNewsletterController::class, 'index'])->name('newsletters.index');
        Route::get('/newsletters/new', [StaffNewsletterController::class, 'create'])->name('newsletters.create');
        Route::post('/newsletters', [StaffNewsletterController::class, 'store'])->name('newsletters.store');
        Route::get('/newsletters/{newsletter}/edit', [StaffNewsletterController::class, 'edit'])->name('newsletters.edit');
        Route::patch('/newsletters/{newsletter}', [StaffNewsletterController::class, 'update'])->name('newsletters.update');
        Route::post('/newsletters/{newsletter}/archive', [StaffNewsletterController::class, 'archive'])->name('newsletters.archive');
        Route::post('/newsletters/{newsletter}/segments', [StaffNewsletterController::class, 'storeSegment'])->name('newsletters.segments.store');
        Route::post('/newsletters/{newsletter}/restore', [StaffNewsletterController::class, 'restore'])->name('newsletters.restore');

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
    });

require __DIR__.'/auth.php';

if (app()->environment('local')) {
    require __DIR__.'/dev.php';
}
