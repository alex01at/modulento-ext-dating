<?php

declare(strict_types=1);

namespace Modulento\Dating;

use Modulento\Core\App;
use Modulento\Core\Extension\Extension as ExtensionContract;
use Modulento\Core\Extension\Registrar;
use Modulento\Core\Support\Router;

/**
 * Swipe-based matching: a profile per account (not per provider - there is
 * nothing to sell here), a swipe queue, mutual-like matches, a chat per
 * match, and peer-to-peer block/report built in from the start. Free
 * accounts get a daily like limit (Matches::DAILY_FREE_LIKES); the
 * subscription features below lift or extend it, exactly like
 * modulento-ext-card's subscriptionFeature() gates.
 */
final class Extension implements ExtensionContract
{
    public const PERMISSION_REPORTS = 'dating.reports.manage';

    public function register(Registrar $registrar): void
    {
        $registrar->permission(self::PERMISSION_REPORTS, 'dating.permission.reports_manage');

        $registrar->subscriptionFeature('dating.feature.unlimited_likes', 'dating.subscription.unlimited_likes');
        $registrar->subscriptionFeature('dating.feature.see_who_liked_you', 'dating.subscription.see_who_liked_you');
        $registrar->subscriptionFeature('dating.feature.rewind', 'dating.subscription.rewind');
        $registrar->subscriptionFeature('dating.feature.boost', 'dating.subscription.boost');

        $registrar->routes(function (Router $router): void {
            $router->get('/account/dating', [DatingController::class, 'profileEdit']);
            $router->post('/account/dating', [DatingController::class, 'profileSave']);
            $router->post('/account/dating/photos/add', [DatingController::class, 'photoAdd']);
            $router->post('/account/dating/photos/{id}/delete', [DatingController::class, 'photoDelete']);
            $router->post('/account/dating/photos/{id}/primary', [DatingController::class, 'photoPrimary']);
            $router->post('/account/dating/unblock', [DatingController::class, 'unblock']);

            $router->get('/dating/swipe', [DatingController::class, 'swipe']);
            $router->post('/dating/swipe/like', [DatingController::class, 'like']);
            $router->post('/dating/swipe/pass', [DatingController::class, 'pass']);
            $router->post('/dating/swipe/rewind', [DatingController::class, 'rewindSwipe']);

            $router->get('/dating/matches', [DatingController::class, 'matchesIndex']);
            $router->get('/dating/matches/{id}', [DatingController::class, 'matchShow']);
            $router->post('/dating/matches/{id}/messages', [DatingController::class, 'messageSend']);
            $router->post('/dating/matches/{id}/unmatch', [DatingController::class, 'unmatch']);
            $router->post('/dating/matches/{id}/block', [DatingController::class, 'blockFromMatch']);
            $router->post('/dating/matches/{id}/report', [DatingController::class, 'reportFromMatch']);

            $router->get('/media/dating/{id}/{file}', [PhotoMediaController::class, 'show'], Router::PUBLIC);

            $router->get('/admin/dating/reports', [AdminController::class, 'reports'], self::PERMISSION_REPORTS);
            $router->post('/admin/dating/reports/{id}/decide', [AdminController::class, 'decide'], self::PERMISSION_REPORTS);
        });

        $registrar->accountLink('dating.nav.profile', '/account/dating');
        $registrar->accountLink('dating.nav.matches', '/dating/matches');

        // A promotional section on the home page, under the operator's own
        // blocks (Administration → Startseite) - the catalogue-shaped
        // "offers"/"categories"/"providers" blocks make no sense here (there
        // is nothing to sell), so this is how a dating install explains
        // itself and gets a visitor into the swipe queue.
        $registrar->homeSection('@dating/home_section.twig', function (App $app): array {
            $account = $app->auth->account();
            $profile = $account !== null
                ? (new Profiles($app->db, new HttpGeocoder($this->userAgent($app))))->find((int) $account['id'])
                : null;

            return ['logged_in' => $account !== null, 'has_profile' => $profile !== null];
        });

        $registrar->adminMenu('dating.admin.menu', '/admin/dating/reports', self::PERMISSION_REPORTS, 'moderation');

        // A tiny status panel on the account's own dashboard - matches and
        // unread messages at a glance, nothing shown before a profile exists.
        $registrar->dashboardSection('@dating/dashboard_section.twig', function (array $account, App $app): array {
            $profile = (new Profiles($app->db, new HttpGeocoder($this->userAgent($app))))->find((int) $account['id']);
            if ($profile === null) {
                return [];
            }

            $matches = new Matches($app->db);
            $chat = new Chat($app->db);

            return [
                'match_count' => count($matches->forAccount((int) $account['id'])),
                'unread_count' => $chat->unreadTotal((int) $account['id']),
            ];
        });
    }

    public function userAgent(App $app): string
    {
        return 'Modulento-Dating/1.0 (+' . $app->config['app']['url'] . ')';
    }
}
