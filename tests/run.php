<?php

declare(strict_types=1);

// Plain-PHP checks of the dating addon's own classes, on SQLite in memory:
//   MODULENTO_CORE=/path/to/modulento php tests/run.php
// The core is only needed for its classes (vendor/autoload.php); without
// the variable it is expected next to this repository, in ../modulento.
// Exit code 0 means every check passed. No real network request is ever
// made - GeocoderStub below stands in for HttpGeocoder.

use Modulento\Core\App;
use Modulento\Dating\AdminController;
use Modulento\Dating\Blocks;
use Modulento\Dating\Chat;
use Modulento\Dating\DatingController;
use Modulento\Dating\Geocoder;
use Modulento\Dating\Matches;
use Modulento\Dating\Photos;
use Modulento\Dating\Profiles;
use Modulento\Dating\Reports;
use Modulento\Dating\SwipeQueue;

$here = dirname(__DIR__);
$core = getenv('MODULENTO_CORE') ?: dirname($here) . '/modulento';

if (!is_file($core . '/vendor/autoload.php')) {
    fwrite(STDERR, "No Modulento core with vendor/ found at {$core}.\n"
        . "Set MODULENTO_CORE to a working copy of the core in which \"composer install\" has run.\n");
    exit(1);
}

require $core . '/vendor/autoload.php';

date_default_timezone_set('UTC');

$failed = [];
$checks = 0;

function check(string $label, bool $condition): void
{
    global $failed, $checks;
    $checks++;
    if (!$condition) {
        $failed[] = $label;
    }
}

// --- Database --------------------------------------------------------------
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('CREATE TABLE setting (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
$pdo->exec("CREATE TABLE extension (id TEXT PRIMARY KEY, version TEXT, enabled INTEGER, enabled_at TEXT)");
$pdo->exec("INSERT INTO extension VALUES ('dating', '0', 1, '2026-01-01 00:00:00')");
$pdo->exec("INSERT INTO setting VALUES ('core.modules_disabled', 'subscriptions')");
$pdo->exec("CREATE TABLE account (id INTEGER PRIMARY KEY, email TEXT UNIQUE, display_name TEXT, password_hash TEXT, status TEXT DEFAULT 'active',
    identity_status TEXT NOT NULL DEFAULT 'none', locale TEXT DEFAULT 'de', created_at TEXT, last_login_at TEXT)");
$pdo->exec("CREATE TABLE provider (id INTEGER PRIMARY KEY, account_id INTEGER UNIQUE REFERENCES account (id) ON DELETE CASCADE, type TEXT, status TEXT,
    name TEXT, slug TEXT UNIQUE, legal_name TEXT, street TEXT, postal_code TEXT, city TEXT, country TEXT, contact_email TEXT, phone TEXT,
    vat_id TEXT, company_register TEXT, decided_at TEXT, decided_by INTEGER, created_at TEXT, updated_at TEXT, avg_response_minutes INTEGER, response_sample_count INTEGER NOT NULL DEFAULT 0,
    rating_count INTEGER NOT NULL DEFAULT 0, rating_sum INTEGER NOT NULL DEFAULT 0)");
$pdo->exec('CREATE TABLE provider_translation (provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, locale TEXT, headline TEXT, description TEXT, PRIMARY KEY (provider_id, locale))');
$pdo->exec("CREATE TABLE offer (id INTEGER PRIMARY KEY, provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, type TEXT, category_id INTEGER,
    status TEXT, price_from INTEGER, currency TEXT, published_at TEXT, created_at TEXT, updated_at TEXT, rating_count INTEGER NOT NULL DEFAULT 0, rating_sum INTEGER NOT NULL DEFAULT 0)");
$pdo->exec('CREATE TABLE offer_translation (offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, locale TEXT, title TEXT, slug TEXT, summary TEXT, description TEXT, PRIMARY KEY (offer_id, locale))');
$pdo->exec('CREATE TABLE offer_image (id INTEGER PRIMARY KEY, offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, name TEXT, extension TEXT, width INTEGER, height INTEGER, position INTEGER, created_at TEXT)');
$pdo->exec('CREATE TABLE page (id INTEGER PRIMARY KEY, status TEXT, role TEXT, in_header INTEGER, in_footer INTEGER, position INTEGER)');
$pdo->exec('CREATE TABLE page_translation (page_id INTEGER, locale TEXT, title TEXT, slug TEXT, meta_description TEXT, body TEXT)');
$pdo->exec('CREATE TABLE account_preference (account_id INTEGER, name TEXT, value TEXT, PRIMARY KEY (account_id, name))');
$pdo->exec('CREATE TABLE role (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE role_permission (role_id INTEGER REFERENCES role (id) ON DELETE CASCADE, permission TEXT)');
$pdo->exec('CREATE TABLE account_role (account_id INTEGER REFERENCES account (id) ON DELETE CASCADE, role_id INTEGER REFERENCES role (id) ON DELETE CASCADE)');
// The core's generic read-state table, reused here with scope='dating_match'.
$pdo->exec('CREATE TABLE message_seen (account_id INTEGER NOT NULL REFERENCES account (id) ON DELETE CASCADE, scope TEXT NOT NULL, ref_id INTEGER NOT NULL,
    sub_id INTEGER NOT NULL DEFAULT 0, seen_id INTEGER NOT NULL, PRIMARY KEY (account_id, scope, ref_id, sub_id))');

$pdo->exec("CREATE TABLE x_dating_profile (
    id INTEGER PRIMARY KEY, account_id INTEGER NOT NULL UNIQUE REFERENCES account (id) ON DELETE CASCADE,
    display_name TEXT NOT NULL, birthdate TEXT NOT NULL, gender TEXT NOT NULL, seeking TEXT NOT NULL DEFAULT 'any',
    bio TEXT, postal_code TEXT, city TEXT, country TEXT, lat REAL, lng REAL, geocoded_at TEXT,
    max_distance_km INTEGER NOT NULL DEFAULT 50, status TEXT NOT NULL DEFAULT 'active', created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
$pdo->exec("CREATE TABLE x_dating_photo (
    id INTEGER PRIMARY KEY, profile_id INTEGER NOT NULL REFERENCES x_dating_profile (id) ON DELETE CASCADE,
    name TEXT NOT NULL, extension TEXT NOT NULL, is_primary INTEGER NOT NULL DEFAULT 0, position INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL)");
$pdo->exec("CREATE TABLE x_dating_swipe (
    liker_account_id INTEGER NOT NULL REFERENCES account (id) ON DELETE CASCADE,
    target_account_id INTEGER NOT NULL REFERENCES account (id) ON DELETE CASCADE,
    direction TEXT NOT NULL, created_at TEXT NOT NULL, PRIMARY KEY (liker_account_id, target_account_id))");
$pdo->exec("CREATE TABLE x_dating_match (
    id INTEGER PRIMARY KEY, account_a_id INTEGER NOT NULL REFERENCES account (id) ON DELETE CASCADE,
    account_b_id INTEGER NOT NULL REFERENCES account (id) ON DELETE CASCADE,
    status TEXT NOT NULL DEFAULT 'active', created_at TEXT NOT NULL, unmatched_at TEXT)");
$pdo->exec("CREATE TABLE x_dating_message (
    id INTEGER PRIMARY KEY, match_id INTEGER NOT NULL REFERENCES x_dating_match (id) ON DELETE CASCADE,
    author_account_id INTEGER NOT NULL REFERENCES account (id) ON DELETE CASCADE, body TEXT NOT NULL, created_at TEXT NOT NULL)");
$pdo->exec("CREATE TABLE x_dating_block (
    blocker_account_id INTEGER NOT NULL REFERENCES account (id) ON DELETE CASCADE,
    blocked_account_id INTEGER NOT NULL REFERENCES account (id) ON DELETE CASCADE,
    created_at TEXT NOT NULL, PRIMARY KEY (blocker_account_id, blocked_account_id))");
$pdo->exec("CREATE TABLE x_dating_report (
    id INTEGER PRIMARY KEY, reporter_account_id INTEGER NOT NULL REFERENCES account (id) ON DELETE CASCADE,
    reported_account_id INTEGER NOT NULL REFERENCES account (id) ON DELETE CASCADE,
    match_id INTEGER REFERENCES x_dating_match (id) ON DELETE SET NULL,
    category TEXT NOT NULL, explanation TEXT, status TEXT NOT NULL DEFAULT 'open',
    decided_at TEXT, decided_by INTEGER, created_at TEXT NOT NULL)");

// The migration and the schema above must name the same columns, per table.
$migration = (string) file_get_contents($here . '/migrations/001_dating.sql');
foreach ([
    'x_dating_profile', 'x_dating_photo', 'x_dating_swipe', 'x_dating_match',
    'x_dating_message', 'x_dating_block', 'x_dating_report',
] as $table) {
    preg_match('/CREATE TABLE ' . $table . ' \((.*?)\n\) ENGINE/s', $migration, $body);
    preg_match_all('/^\s{4}([a-z_]+) [A-Z]/m', $body[1] ?? '', $columns);
    $inTest = array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(), 'name');
    sort($columns[1]);
    sort($inTest);
    check("schema: {$table} has the same columns in the migration and in the tests", $columns[1] !== [] && $columns[1] === $inTest);
}
check('schema: a profile is unique per account', str_contains($migration, 'UNIQUE KEY uq_x_dating_profile_account (account_id)'));
check('schema: a match pair is unique', str_contains($migration, 'UNIQUE KEY uq_x_dating_match_pair (account_a_id, account_b_id)'));
check('schema: removing an account removes its profile', str_contains($migration, 'fk_x_dating_profile_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE'));

$uploads = sys_get_temp_dir() . '/modulento-dating-test-' . bin2hex(random_bytes(4));
mkdir($uploads . '/extensions', 0777, true);
symlink($here, $uploads . '/extensions/dating');

$app = new App([
    'app' => [
        'env' => 'dev', 'url' => 'https://dating.example', 'name' => 'Testseite', 'root' => $core,
        'extensions' => $uploads . '/extensions', 'uploads' => $uploads, 'secret_key' => $uploads . '/secret.key',
    ],
    'mail' => ['from' => 'noreply@dating.example', 'transport' => 'log', 'log_path' => $uploads . '/mail.log'],
    'update' => ['token' => ''],
    'packages' => ['sources' => []],
], $pdo, 'de');
$app->translator->load($core . '/core/lang', 'core');
$app->extensions->loadEnabled($app);

check('extension: the core loads it as an app', isset($app->extensions->loaded()['dating']) && $app->extensions->loaded()['dating']->type === 'app');
check('extension: an account link for the profile and for matches is announced',
    array_column($app->accountLinks(), 'path') === ['/account/dating', '/dating/matches']);
check('extension: a permission for the report queue is declared', array_key_exists('dating.reports.manage', $app->permissions()));
check('extension: every premium feature is declared', array_keys($app->subscriptions->declaredFeatures()) === [
    'dating.feature.unlimited_likes', 'dating.feature.see_who_liked_you', 'dating.feature.rewind', 'dating.feature.boost',
]);
check('extension: without a billing addon installed, every feature is open (Subscriptions::allows() default)',
    $app->subscriptions->allows(1, 'dating.feature.unlimited_likes') && $app->subscriptions->allows(1, 'dating.feature.rewind'));
check('extension: a home page section is announced', array_column($app->homeSections(), 'template') === ['@dating/home_section.twig']);

/** Stands in for HttpGeocoder: known postal codes resolve to fixed coordinates, everything else does not. */
final class GeocoderStub implements Geocoder
{
    /** @var array<string, array{lat: float, lng: float}> */
    private array $known;
    public int $calls = 0;

    public function __construct()
    {
        $this->known = [
            '10115' => ['lat' => 52.5325, 'lng' => 13.3846], // Berlin
            '80331' => ['lat' => 48.1375, 'lng' => 11.5755], // Munich, ~500km from Berlin
        ];
    }

    public function geocode(string $postalCode, string $city, string $country): ?array
    {
        $this->calls++;

        return $this->known[$postalCode] ?? null;
    }
}

// --- Fixtures: four accounts ------------------------------------------------
$testHash = password_hash('correct horse battery', PASSWORD_BCRYPT, ['cost' => 4]);
foreach ([1 => 'anna', 2 => 'bruno', 3 => 'carla', 4 => 'dario'] as $id => $name) {
    $pdo->exec("INSERT INTO account (id, email, display_name, password_hash, created_at) VALUES ({$id}, '{$name}@example.test', '" . ucfirst($name) . "', '{$testHash}', '2026-01-01 00:00:00')");
}

$geocoder = new GeocoderStub();
$profiles = new Profiles($pdo, $geocoder);

// --- Profiles::validate() ---------------------------------------------------
$tooYoung = (new DateTimeImmutable('today'))->modify('-15 years')->format('Y-m-d');
$adult = (new DateTimeImmutable('today'))->modify('-30 years')->format('Y-m-d');

$result = $profiles->validate(['display_name' => '', 'birthdate' => $tooYoung, 'gender' => 'nonsense', 'country' => 'XX']);
check('validate: missing name, underage, bad gender and bad country are all caught', $result['errors'] === [
    'dating.profile.error.display_name', 'dating.profile.error.too_young', 'dating.profile.error.gender', 'dating.profile.error.country',
]);

$validInput = ['display_name' => 'Anna', 'birthdate' => $adult, 'gender' => 'female', 'seeking' => 'male', 'postal_code' => '10115', 'city' => 'Berlin', 'country' => 'de', 'max_distance_km' => '100'];
$result = $profiles->validate($validInput);
check('validate: a correct submission has no errors and normalises the country to uppercase', $result['errors'] === [] && $result['values']['country'] === 'DE');

// --- Profiles::save(): creation, geocoding, update without re-geocoding -----
$profiles->save(1, $result['values']);
$anna = $profiles->find(1);
check('save: the profile was created', $anna !== null && $anna['display_name'] === 'Anna');
check('save: a known postal code is geocoded on first save', $anna['lat'] !== null && abs((float) $anna['lat'] - 52.5325) < 0.001);
check('save: the geocoder was asked exactly once so far', $geocoder->calls === 1);

$sameAddress = $profiles->validate($validInput + ['bio' => 'Hallo!']);
$profiles->save(1, $sameAddress['values']);
check('save: saving again without an address change does not re-geocode', $geocoder->calls === 1);
check('save: the bio was still updated', $profiles->find(1)['bio'] === 'Hallo!');

$movedInput = $validInput;
$movedInput['postal_code'] = '80331';
$movedInput['city'] = 'München';
$movedResult = $profiles->validate($movedInput);
$profiles->save(1, $movedResult['values']);
check('save: changing the address re-geocodes', $geocoder->calls === 2 && abs((float) $profiles->find(1)['lat'] - 48.1375) < 0.001);
$profiles->save(1, $profiles->validate($validInput)['values']); // move Anna back to Berlin for the distance checks below

// Bruno (male, seeking female) in Berlin - a mutual match for Anna.
$profiles->save(2, $profiles->validate(['display_name' => 'Bruno', 'birthdate' => $adult, 'gender' => 'male', 'seeking' => 'female', 'postal_code' => '10115', 'city' => 'Berlin', 'country' => 'DE'])['values']);
// Carla (female, seeking female) in Munich - outside Anna's 100km radius from Berlin.
$profiles->save(3, $profiles->validate(['display_name' => 'Carla', 'birthdate' => $adult, 'gender' => 'female', 'seeking' => 'female', 'postal_code' => '80331', 'city' => 'München', 'country' => 'DE'])['values']);
// Dario (male, seeking male) in Berlin - wrong orientation for Anna either way.
$profiles->save(4, $profiles->validate(['display_name' => 'Dario', 'birthdate' => $adult, 'gender' => 'male', 'seeking' => 'male', 'postal_code' => '10115', 'city' => 'Berlin', 'country' => 'DE'])['values']);

check('geocoder: Anna\'s two address changes plus Bruno/Carla/Dario\'s first save all geocoded once each', $geocoder->calls === 6);

// --- SwipeQueue::next() ------------------------------------------------------
$blocks = new Blocks($pdo);
$queue = new SwipeQueue($pdo, $blocks);

$next = $queue->next(1, $anna = $profiles->find(1));
check('queue: Anna (seeking male, Berlin) is shown Bruno, not Carla (wrong distance) or Dario (wrong orientation)', $next !== null && $next['account_id'] === 2);

check('haversine: Berlin to Munich is roughly 500km, not within a 100km radius', SwipeQueue::haversineKm(52.5325, 13.3846, 48.1375, 11.5755) > 400);

// --- Controller swipe(): the card markup and a real distance, both Berlin ---
$app->auth->login(1);
ob_start();
(new DatingController($app))->swipe([]);
$cardBody = ob_get_clean();
check('controller swipe: renders the card for the one candidate left (Bruno)', str_contains($cardBody, 'date-card') && str_contains($cardBody, 'Bruno'));
check('controller swipe: shows a real distance (Anna and Bruno are both in Berlin, so 0 km)', str_contains($cardBody, '0 km'));

// --- Matches: like/pass/match detection --------------------------------------
$matches = new Matches($pdo);
check('like: a one-sided like creates no match yet', $matches->like(1, 2) === null);
check('like: the reverse like completes the match', $matches->like(2, 1) !== null);
$matchId = $matches->like(2, 1);
check('like: liking again just returns the existing match, not a new one', $matches->forAccount(1) !== [] && count($matches->forAccount(1)) === 1);

check('queue: once matched, Bruno no longer appears in Anna\'s queue', $queue->next(1, $profiles->find(1)) === null || $queue->next(1, $profiles->find(1))['account_id'] !== 2);

$matches->pass(1, 4);
check('pass: Dario is excluded from Anna\'s queue by orientation anyway, but the pass itself is recorded', $matches->likesGivenToday(1) === 1);
check('rewind: takes back the last pass', $matches->rewind(1) === true);
check('rewind: nothing left to take back a second time in a row for the same account', $matches->rewind(1) === false);

check('likedByCount: Carla liking Anna without a reverse like counts as "liked you"', (function () use ($matches): bool {
    $before = $matches->likedByCount(1);
    $matches->like(3, 1);

    return $matches->likedByCount(1) === $before + 1;
})());

$unmatchedId = $matches->like(1, 4);
$matches->like(4, 1);
$reMatchId = $matches->like(4, 1);

// --- Chat --------------------------------------------------------------------
$match = $matches->find($matchId);
$chat = new Chat($pdo);

check('chat: sending onto an active match works', $chat->send($match, 1, 'Hallo Bruno!') !== null);
check('chat: an empty message is not sent', $chat->send($match, 2, '   ') === null);
$chat->send($match, 2, 'Hallo Anna!');
check('chat: both messages are in the thread, oldest first', array_column($chat->forMatch((int) $match['id']), 'body') === ['Hallo Bruno!', 'Hallo Anna!']);
check('chat: Anna has one unread message (Bruno\'s), not her own', $chat->unreadInMatch(1, (int) $match['id']) === 1);
$chat->markSeen(1, (int) $match['id']);
check('chat: marking seen clears the unread count, via the shared message_seen table', $chat->unreadInMatch(1, (int) $match['id']) === 0);
check('chat: message_seen now carries scope dating_match for this account/match', (function () use ($pdo, $matchId): bool {
    $stmt = $pdo->prepare("SELECT seen_id FROM message_seen WHERE account_id = 1 AND scope = 'dating_match' AND ref_id = :match AND sub_id = 0");
    $stmt->execute(['match' => $matchId]);

    return (int) $stmt->fetchColumn() > 0;
})());

$matches->unmatch((int) $match['id']);
check('chat: an unmatched match can no longer receive messages', $chat->send($matches->find((int) $match['id']), 1, 'noch da?') === null);

// --- Blocks --------------------------------------------------------------------
check('blocks: nobody is blocked by default', !$blocks->eitherWay(3, 1));
$blocks->block(1, 3);
check('blocks: a one-sided block is enough for eitherWay() in both directions', $blocks->blocked(1, 3) && !$blocks->blocked(3, 1) && $blocks->eitherWay(1, 3) && $blocks->eitherWay(3, 1));
check('blocks: Carla no longer appears in Anna\'s queue once blocked, even though she liked Anna', (function () use ($queue, $profiles): bool {
    $annaProfile = $profiles->save(1, $profiles->validate(['display_name' => 'Anna', 'birthdate' => $GLOBALS['adult'] ?? (new DateTimeImmutable('today'))->modify('-30 years')->format('Y-m-d'), 'gender' => 'female', 'seeking' => 'any', 'postal_code' => '10115', 'city' => 'Berlin', 'country' => 'DE'])['values']) ?? $profiles->find(1);
    $next = $queue->next(1, $profiles->find(1));

    return $next === null || $next['account_id'] !== 3;
})());
check('blocks: unblocking lifts the restriction again', (function () use ($blocks): bool {
    $blocks->unblock(1, 3);

    return !$blocks->blocked(1, 3);
})());
check('blocks: blockedByAccount lists what an account itself has blocked', (function () use ($blocks): bool {
    $blocks->block(1, 4);

    return $blocks->blockedByAccount(1) === [4];
})());
$blocks->unblock(1, 4);

// --- Reports --------------------------------------------------------------------
$reports = new Reports($pdo);
check('reports: reporting yourself is refused', $reports->create(1, 1, null, 'other', 'x') === null);
check('reports: an unknown category is refused', $reports->create(1, 2, null, 'not_a_category', null) === null);
$reportId = $reports->create(1, 2, $matchId, 'harassment', 'Unangemessene Nachrichten.');
check('reports: a valid report is created and listed as open', $reportId !== null && count(array_filter($reports->listOpen(), fn (array $r) => (int) $r['id'] === $reportId)) === 1);
check('reports: openCount reflects it', $reports->openCount() === 1);
$reports->decide($reportId, 'actioned', 999);
check('reports: deciding removes it from the open queue', $reports->openCount() === 0);
check('reports: an invalid status is ignored', (function () use ($reports): bool {
    $id = $reports->create(2, 1, null, 'other', null);
    $reports->decide($id, 'nonsense', 999);

    return $reports->find($id)['status'] === 'open';
})());

// --- Controllers: smoke tests through the same objects the router would use --
$app->auth->login(1);

ob_start();
(new DatingController($app))->profileEdit([]);
$body = ob_get_clean();
check('controller profileEdit: shows the current profile\'s display name', str_contains($body, 'Anna'));

$_POST = ['display_name' => '', 'birthdate' => $adult, 'gender' => 'female'];
ob_start();
(new DatingController($app))->profileSave([]);
ob_end_clean();
check('controller profileSave: a validation error re-renders the form rather than redirecting', !isset($_SESSION['_flash']['success']));

$_POST = $validInput;
ob_start();
(new DatingController($app))->profileSave([]);
ob_end_clean();
check('controller profileSave: a valid submission flashes success', (Modulento\Core\Support\Session::pullFlashes()['success'] ?? null) === $app->translator->trans('dating.profile.saved'));

ob_start();
(new DatingController($app))->swipe([]);
$body = ob_get_clean();
check('controller swipe: renders without error for an account with a profile', $body !== '');

$app->auth->login(3); // Carla: no match left to open
ob_start();
(new DatingController($app))->matchShow(['id' => (string) $matchId]); // Bruno/Anna's match, Carla is not part of it
$body = ob_get_clean();
check('controller matchShow: 404s for a match the account is not part of', str_contains($body, '404') || http_response_code() === 404);

$app->auth->login(1);
ob_start();
(new DatingController($app))->matchesIndex([]);
$body = ob_get_clean();
check('controller matchesIndex: lists an active match', $body !== '');

$app->auth->login(999); // admin stand-in, not a participant - but AdminController does not check that, only the permission (enforced by the router, not here)
ob_start();
(new AdminController($app))->reports([]);
$body = ob_get_clean();
check('controller admin reports: renders the open queue', str_contains($body, 'Bruno') && str_contains($body, 'Anna'));

// --- Home page section -------------------------------------------------------
check('home section: a logged-out visitor is pointed at registration', str_contains(
    $app->view()->render('@dating/home_section.twig', ['logged_in' => false, 'has_profile' => false]), 'register'
));
check('home section: a logged-in account without a profile is pointed at creating one', str_contains(
    $app->view()->render('@dating/home_section.twig', ['logged_in' => true, 'has_profile' => false]), '/account/dating'
));
check('home section: a logged-in account with a profile is pointed at the swipe queue', str_contains(
    $app->view()->render('@dating/home_section.twig', ['logged_in' => true, 'has_profile' => true]), '/dating/swipe'
));

// --- Language files -----------------------------------------------------------
$de = require $here . '/lang/de.php';
$en = require $here . '/lang/en.php';
check('lang: de and en have the same keys', array_keys($de) === array_keys($en));
check('lang: every key starts with "dating."', array_filter(array_keys($de), fn (string $key) => !str_starts_with($key, 'dating.')) === []);
$used = [];
foreach ([...glob($here . '/templates/{,*/}*.twig', GLOB_BRACE), ...glob($here . '/src/*.php')] as $file) {
    preg_match_all("/trans\('(dating\.[a-z0-9_.]+[a-z0-9])'/", (string) file_get_contents($file), $found);
    $used = array_merge($used, $found[1]);
}
check('lang: every literal key used in templates and code exists', array_diff(array_unique($used), array_keys($de)) === []);

// --- Clean up -------------------------------------------------------------------
$remove = function (string $dir) use (&$remove): void {
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
        is_dir("{$dir}/{$entry}") && !is_link("{$dir}/{$entry}") ? $remove("{$dir}/{$entry}") : unlink("{$dir}/{$entry}");
    }
    rmdir($dir);
};
$remove($uploads);

if ($failed !== []) {
    echo "FAILED:\n";
    foreach ($failed as $label) {
        echo "  - {$label}\n";
    }
    echo count($failed) . " of {$checks} checks failed\n";
    exit(1);
}

echo "OK ({$checks} checks)\n";
