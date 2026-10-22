<?php

declare(strict_types=1);

namespace Modulento\Dating;

use DateTimeImmutable;
use Modulento\Core\Controller\Controller;
use Modulento\Core\Support\Countries;
use Modulento\Core\Support\Session;

final class DatingController extends Controller
{
    public function profileEdit(array $params): void
    {
        $accountId = (int) $this->app->auth->account()['id'];
        $this->renderProfileForm($accountId, $this->profiles()->find($accountId));
    }

    public function profileSave(array $params): void
    {
        $accountId = (int) $this->app->auth->account()['id'];
        $profiles = $this->profiles();
        $result = $profiles->validate($_POST);

        if ($result['errors'] !== []) {
            $this->renderProfileForm($accountId, $result['values'] + ['account_id' => $accountId], array_map(fn (string $k) => $this->trans($k), $result['errors']));

            return;
        }

        $profiles->save($accountId, $result['values']);
        Session::flash('success', $this->trans('dating.profile.saved'));
        $this->redirect('/account/dating');
    }

    public function photoAdd(array $params): void
    {
        $accountId = (int) $this->app->auth->account()['id'];
        $profile = $this->profiles()->find($accountId);
        if ($profile === null) {
            $this->redirect('/account/dating');

            return;
        }

        $error = $this->photos()->add((int) $profile['id'], $_FILES['photo'] ?? []);
        if ($error !== null) {
            Session::flash('error', $this->trans($error));
        }
        $this->redirect('/account/dating');
    }

    public function photoDelete(array $params): void
    {
        $accountId = (int) $this->app->auth->account()['id'];
        $profile = $this->profiles()->find($accountId);
        if ($profile !== null) {
            $this->photos()->delete((int) $profile['id'], (int) $params['id']);
        }
        $this->redirect('/account/dating');
    }

    public function photoPrimary(array $params): void
    {
        $accountId = (int) $this->app->auth->account()['id'];
        $profile = $this->profiles()->find($accountId);
        if ($profile !== null) {
            $this->photos()->makePrimary((int) $profile['id'], (int) $params['id']);
        }
        $this->redirect('/account/dating');
    }

    public function unblock(array $params): void
    {
        $accountId = (int) $this->app->auth->account()['id'];
        $target = (int) ($_POST['blocked_account_id'] ?? 0);
        if ($target > 0) {
            $this->blocks()->unblock($accountId, $target);
        }
        $this->redirect('/account/dating');
    }

    public function swipe(array $params): void
    {
        $accountId = (int) $this->app->auth->account()['id'];
        $profile = $this->profiles()->find($accountId);
        if ($profile === null) {
            Session::flash('error', $this->trans('dating.flash.profile_required'));
            $this->redirect('/account/dating');

            return;
        }

        $candidate = $this->swipeQueue()->next($accountId, $profile);
        $matches = $this->matches();
        $likesToday = $matches->likesGivenToday($accountId);
        $unlimited = $this->app->subscriptions->allows($accountId, 'dating.feature.unlimited_likes');

        $this->render('@dating/swipe.twig', [
            'candidate' => $candidate !== null ? $this->candidateView($candidate, $profile) : null,
            'likes_left' => $unlimited ? null : max(0, Matches::DAILY_FREE_LIKES - $likesToday),
            'can_rewind' => $this->app->subscriptions->allows($accountId, 'dating.feature.rewind'),
        ]);
    }

    public function like(array $params): void
    {
        $accountId = (int) $this->app->auth->account()['id'];
        $targetId = (int) ($_POST['target_account_id'] ?? 0);
        $matches = $this->matches();

        $unlimited = $this->app->subscriptions->allows($accountId, 'dating.feature.unlimited_likes');
        if (!$unlimited && $matches->likesGivenToday($accountId) >= Matches::DAILY_FREE_LIKES) {
            Session::flash('error', $this->trans('dating.flash.daily_limit'));
            $this->redirect('/dating/swipe');

            return;
        }

        if ($targetId > 0) {
            $matchId = $matches->like($accountId, $targetId);
            if ($matchId !== null) {
                Session::flash('success', $this->trans('dating.flash.new_match'));
                $this->redirect('/dating/matches/' . $matchId);

                return;
            }
        }

        $this->redirect('/dating/swipe');
    }

    public function pass(array $params): void
    {
        $accountId = (int) $this->app->auth->account()['id'];
        $targetId = (int) ($_POST['target_account_id'] ?? 0);
        if ($targetId > 0) {
            $this->matches()->pass($accountId, $targetId);
        }
        $this->redirect('/dating/swipe');
    }

    public function rewindSwipe(array $params): void
    {
        $accountId = (int) $this->app->auth->account()['id'];
        if (!$this->app->subscriptions->allows($accountId, 'dating.feature.rewind')) {
            $this->redirect('/dating/swipe');

            return;
        }
        $this->matches()->rewind($accountId);
        $this->redirect('/dating/swipe');
    }

    public function matchesIndex(array $params): void
    {
        $accountId = (int) $this->app->auth->account()['id'];
        $matches = $this->matches();
        $chat = $this->chat();

        $rows = array_map(function (array $match) use ($accountId, $matches, $chat): array {
            $otherId = $matches->otherAccountId($match, $accountId);
            $otherProfile = $otherId !== null ? $this->profiles()->find($otherId) : null;

            return [
                'id' => (int) $match['id'],
                'other' => $otherProfile !== null ? $this->profileSummary($otherProfile) : null,
                'unread' => $chat->unreadInMatch($accountId, (int) $match['id']),
            ];
        }, $matches->forAccount($accountId));

        $this->render('@dating/matches.twig', [
            'matches' => $rows,
            'liked_by_count' => $matches->likedByCount($accountId),
            'can_see_who_liked' => $this->app->subscriptions->allows($accountId, 'dating.feature.see_who_liked_you'),
        ]);
    }

    public function matchShow(array $params): void
    {
        $accountId = (int) $this->app->auth->account()['id'];
        $match = $this->requireParticipant((int) $params['id'], $accountId);
        if ($match === null) {
            return;
        }

        $chat = $this->chat();
        $chat->markSeen($accountId, (int) $match['id']);
        $otherId = $this->matches()->otherAccountId($match, $accountId);
        $otherProfile = $otherId !== null ? $this->profiles()->find($otherId) : null;

        $this->render('@dating/chat.twig', [
            'match' => ['id' => (int) $match['id'], 'status' => $match['status']],
            'other' => $otherProfile !== null ? $this->profileSummary($otherProfile) : null,
            'messages' => array_map(fn (array $m) => [
                'id' => (int) $m['id'],
                'mine' => (int) $m['author_account_id'] === $accountId,
                'body' => $m['body'],
                'created_at' => $m['created_at'],
            ], $chat->forMatch((int) $match['id'])),
        ]);
    }

    public function messageSend(array $params): void
    {
        $accountId = (int) $this->app->auth->account()['id'];
        $match = $this->requireParticipant((int) $params['id'], $accountId);
        if ($match === null) {
            return;
        }

        $this->chat()->send($match, $accountId, (string) ($_POST['body'] ?? ''));
        $this->redirect('/dating/matches/' . $match['id']);
    }

    public function unmatch(array $params): void
    {
        $accountId = (int) $this->app->auth->account()['id'];
        $match = $this->requireParticipant((int) $params['id'], $accountId);
        if ($match === null) {
            return;
        }

        $this->matches()->unmatch((int) $match['id']);
        Session::flash('success', $this->trans('dating.flash.unmatched'));
        $this->redirect('/dating/matches');
    }

    public function blockFromMatch(array $params): void
    {
        $accountId = (int) $this->app->auth->account()['id'];
        $match = $this->requireParticipant((int) $params['id'], $accountId);
        if ($match === null) {
            return;
        }

        $otherId = $this->matches()->otherAccountId($match, $accountId);
        if ($otherId !== null) {
            $this->blocks()->block($accountId, $otherId);
            $this->matches()->unmatch((int) $match['id']);
        }
        Session::flash('success', $this->trans('dating.flash.blocked'));
        $this->redirect('/dating/matches');
    }

    public function reportFromMatch(array $params): void
    {
        $accountId = (int) $this->app->auth->account()['id'];
        $match = $this->requireParticipant((int) $params['id'], $accountId);
        if ($match === null) {
            return;
        }

        $otherId = $this->matches()->otherAccountId($match, $accountId);
        $category = (string) ($_POST['category'] ?? '');
        if ($otherId !== null && in_array($category, Reports::CATEGORIES, true)) {
            $this->reports()->create($accountId, $otherId, (int) $match['id'], $category, (string) ($_POST['explanation'] ?? ''));
            Session::flash('success', $this->trans('dating.flash.reported'));
        }
        $this->redirect('/dating/matches/' . $match['id']);
    }

    private function renderProfileForm(int $accountId, ?array $profile, array $errors = []): void
    {
        $photos = isset($profile['id']) ? array_map(fn (array $p) => Photos::urls($p), $this->photos()->ofProfile((int) $profile['id'])) : [];

        $this->render('@dating/account/edit.twig', [
            'profile' => $profile,
            'errors' => $errors,
            'photos' => $photos,
            'max_photos' => Photos::MAX_PHOTOS,
            'genders' => Profiles::GENDERS,
            'seeking_options' => Profiles::SEEKING,
            'countries' => Countries::CODES,
            'blocked' => array_map(fn (int $id) => $this->profiles()->find($id) ?? ['account_id' => $id, 'display_name' => null], $this->blocks()->blockedByAccount($accountId)),
        ]);
    }

    /** @return array{id:int, status:string, account_a_id:int, account_b_id:int}|null */
    private function requireParticipant(int $matchId, int $accountId): ?array
    {
        $match = $this->matches()->find($matchId);
        if ($match === null || $this->matches()->otherAccountId($match, $accountId) === null) {
            http_response_code(404);
            $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);

            return null;
        }

        return $match;
    }

    private function candidateView(array $profile, array $viewerProfile): array
    {
        $distanceKm = null;
        if ($profile['lat'] !== null && $profile['lng'] !== null && $viewerProfile['lat'] !== null && $viewerProfile['lng'] !== null) {
            $distanceKm = (int) round(SwipeQueue::haversineKm(
                (float) $viewerProfile['lat'], (float) $viewerProfile['lng'], (float) $profile['lat'], (float) $profile['lng']
            ));
        }

        return $this->profileSummary($profile) + [
            'bio' => $profile['bio'],
            'city' => $profile['city'],
            'distance_km' => $distanceKm,
        ];
    }

    private function profileSummary(array $profile): array
    {
        $photo = $this->photos()->primary((int) $profile['id']);

        return [
            'account_id' => (int) $profile['account_id'],
            'display_name' => $profile['display_name'],
            'age' => $this->age($profile['birthdate']),
            'photo' => $photo !== null ? Photos::urls($photo) : null,
        ];
    }

    private function age(string $birthdate): int
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $birthdate);

        return $parsed === false ? 0 : (new DateTimeImmutable('today'))->diff($parsed)->y;
    }

    private function profiles(): Profiles
    {
        return new Profiles($this->app->db, new HttpGeocoder($this->userAgent()));
    }

    private function photos(): Photos
    {
        return new Photos($this->app->db, $this->uploadDir());
    }

    private function swipeQueue(): SwipeQueue
    {
        return new SwipeQueue($this->app->db, $this->blocks());
    }

    private function matches(): Matches
    {
        return new Matches($this->app->db);
    }

    private function blocks(): Blocks
    {
        return new Blocks($this->app->db);
    }

    private function chat(): Chat
    {
        return new Chat($this->app->db);
    }

    private function reports(): Reports
    {
        return new Reports($this->app->db);
    }

    private function uploadDir(): string
    {
        $config = $this->app->config;

        return ($config['app']['uploads'] ?? $config['app']['root'] . '/var/uploads') . '/dating-photos';
    }

    private function userAgent(): string
    {
        return 'Modulento-Dating/1.0 (+' . $this->app->config['app']['url'] . ')';
    }
}
