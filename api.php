<?php
/* ------------------------------------------------------------------
   Vindersedlen — backend
   Alt går gennem denne ene fil. Ingen database.
   ------------------------------------------------------------------ */

declare(strict_types=1);
require __DIR__ . '/config.php';

session_name('vindersedlen');
session_start();

/* ---------- hjælpere ---------- */

function svar(array $data, int $kode = 200) {
    http_response_code($kode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fejl(string $besked, int $kode = 400) {
    svar(['fejl' => $besked], $kode);
}

function rolle(): string {
    return $_SESSION['rolle'] ?? '';
}

function kraev(string ...$roller): void {
    if (!in_array(rolle(), $roller, true)) {
        fejl('Du er ikke logget ind.', 401);
    }
}

function gyldigtId(string $id): bool {
    return (bool) preg_match('/^[a-f0-9]{16}$/', $id);
}

function gyldigSlot(string $slot): bool {
    return (bool) preg_match('/^[a-z0-9]{1,4}$/', $slot);
}

function mappe(string $id): string {
    if (!gyldigtId($id)) fejl('Ukendt indsendelse.', 404);
    return DATA_DIR . '/' . $id;
}

function laesMeta(string $id): array {
    $sti = mappe($id) . '/meta.json';
    if (!is_file($sti)) fejl('Ukendt indsendelse.', 404);
    $data = json_decode((string) file_get_contents($sti), true);
    return is_array($data) ? $data : [];
}

function gemMeta(string $id, array $meta): void {
    $sti = mappe($id) . '/meta.json';
    file_put_contents($sti, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function kropSomJson(): array {
    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > 2 * 1024 * 1024) fejl('For meget data på én gang.', 413);
    $data = json_decode($raw, true);
    if (!is_array($data)) fejl('Ugyldigt indhold.');
    return $data;
}

function rydOp(): void {
    if (!is_dir(DATA_DIR)) return;
    $graense = time() - (OPBEVARING_DAGE * 86400);
    foreach (glob(DATA_DIR . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $meta = $dir . '/meta.json';
        $tid = is_file($meta) ? filemtime($meta) : filemtime($dir);
        if ($tid !== false && $tid < $graense) slet(basename($dir));
    }
}

function slet(string $id): void {
    $dir = DATA_DIR . '/' . $id;
    if (!is_dir($dir) || !gyldigtId($id)) return;
    foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($dir);
}

function sikrDataMappe(): void {
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0770, true);
    }
    if (!is_dir(DATA_DIR) || !is_writable(DATA_DIR)) {
        fejl('Datamappen kan ikke skrives til. Ret rettighederne på serveren.', 500);
    }
    $ht = DATA_DIR . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
    }
}

/* ---------- begivenheder ---------- */

function begivenhedSti(): string {
    return DATA_DIR . '/begivenheder.json';
}

function laesBegivenheder(): array {
    $sti = begivenhedSti();
    if (!is_file($sti)) return [];
    $data = json_decode((string) file_get_contents($sti), true);
    return is_array($data) ? array_values($data) : [];
}

function gemBegivenheder(array $liste): void {
    usort($liste, fn($a, $b) => strcmp((string) $a['dato'], (string) $b['dato']) ?: strcmp((string) $a['sport'], (string) $b['sport']));
    file_put_contents(begivenhedSti(), json_encode(array_values($liste), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function gyldigDato(string $dato): bool {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dato, $m)) return false;
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

/* ---------- sedlen læses af Claude ---------- */

function laesSeddel(string $jpeg): array {
    require_once __DIR__ . '/agentlib.php';
    if (CLAUDE_API_KEY === '') fejl('Der er ingen CLAUDE_API_KEY i config.php, så sedlen kan ikke læses automatisk.', 503);

    // Håndskrift er svær, så den billige model, agenten bruger, er ikke god nok her.
    // Sonnet prøver først; kan den ikke, tager Opus over. Fable bruges aldrig.
    $modeller = [
        defined('CLAUDE_MODEL_SEDDEL') ? CLAUDE_MODEL_SEDDEL : 'claude-sonnet-5',
        defined('CLAUDE_MODEL_SEDDEL_RESERVE') ? CLAUDE_MODEL_SEDDEL_RESERVE : 'claude-opus-5',
    ];
    $modeller = array_values(array_unique(array_filter($modeller, fn($m) => $m !== '' && stripos($m, 'fable') === false)));
    if (!$modeller) fejl('Der er ingen tilladt model sat til at læse sedlen i config.php.', 503);

    $navn = [
        'type' => 'object',
        'properties' => [
            'fornavn'   => ['type' => 'string'],
            'vaerested' => ['type' => 'string'],
        ],
        'required' => ['fornavn', 'vaerested'],
        'additionalProperties' => false,
    ];
    $skema = [
        'type' => 'object',
        'properties' => [
            'placeringer' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'gruppe'    => ['type' => 'string'],
                        'placering' => ['type' => 'integer'],
                        'navne'     => ['type' => 'array', 'items' => $navn],
                        'usikker'   => ['type' => 'boolean'],
                    ],
                    'required' => ['gruppe', 'placering', 'navne', 'usikker'],
                    'additionalProperties' => false,
                ],
            ],
            'faellesskabspokal' => [
                'type' => 'object',
                'properties' => [
                    'fornavn'     => ['type' => 'string'],
                    'vaerested'   => ['type' => 'string'],
                    'begrundelse' => ['type' => 'string'],
                ],
                'required' => ['fornavn', 'vaerested', 'begrundelse'],
                'additionalProperties' => false,
            ],
            'bemaerkning' => ['type' => 'string'],
        ],
        'required' => ['placeringer', 'faellesskabspokal', 'bemaerkning'],
        'additionalProperties' => false,
    ];

    $instruks = <<<TXT
Billedet er en håndskrevet vinderseddel fra et sportsarrangement for brugere af danske væresteder.
Sedlen er nummereret med placeringer (1, 2, 3 …). Ud for hver placering står ét navn eller flere
navne, typisk et hold eller et par. Ofte står værestedet og byen ud for navnene.
Sedlen kan være delt op i flere rækker eller puljer (f.eks. "A-række" og "B-række"), der hver har
deres egne placeringer.

Læs sedlen og returnér én post pr. placering i den rækkefølge, de står:
- gruppe: overskriften på den række eller pulje, placeringen hører til, f.eks. "A-række". Tom, hvis sedlen ikke er delt op.
- placering: tallet på sedlen.
- navne: hver person på den placering for sig, med fornavn og værested. Værestedet skrives med byen,
  hvis den står der, f.eks. "Borgercaféen Haderslev". Deler to personer værested, får begge det.
  Står der to væresteder adskilt af skråstreg, hører det første til den første person og det andet til den anden;
  en by skrevet over et værested hører til det værested. Står der intet værested, så lad feltet være tomt.
  Står der et efternavn, så tag kun fornavnet med.
- usikker: true, hvis du ikke kan læse et af navnene sikkert. Skriv så dit bedste bud.

Står der en fællesskabspokal på sedlen, så udfyld faellesskabspokal. Ellers lad alle tre felter være tomme.
Skriv i bemaerkning kort på dansk, hvis noget på sedlen ikke kunne læses. Ellers lad den være tom.
Opdigt aldrig navne, der ikke står på sedlen.
TXT;

    $sidsteFejl = '';
    foreach ($modeller as $model) {
        $d = kaldSeddelModel($model, $jpeg, $skema, $instruks);
        if (is_array($d)) {
            $d['model'] = $model;
            return $d;
        }
        $sidsteFejl = $d;
    }
    fejl($sidsteFejl, 502);
}

/* Returnerer det læste som array, eller en fejlbesked som tekst,
   så næste model kan få en chance. */
function kaldSeddelModel(string $model, string $jpeg, array $skema, string $instruks) {
    // Ingen server-side fallbacks: de kunne sende billedet videre til en model,
    // vi ikke har valgt. Rækkefølgen styres i laesSeddel().
    $r = httpJson(CLAUDE_API_URL, [
        'Content-Type: application/json',
        'x-api-key: ' . CLAUDE_API_KEY,
        'anthropic-version: 2023-06-01',
    ], [
        'model'         => $model,
        'max_tokens'    => 16000,
        'output_config' => [
            'effort' => 'medium',
            'format' => ['type' => 'json_schema', 'schema' => $skema],
        ],
        'messages' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($jpeg)]],
                ['type' => 'text', 'text' => $instruks],
            ],
        ]],
    ], 70);

    if ($r['kode'] !== 200) {
        $besked = $r['data']['error']['message'] ?? ($r['fejl'] ?: ('HTTP ' . $r['kode']));
        return 'Claude kunne ikke læse sedlen: ' . $besked;
    }
    $stop = (string) ($r['data']['stop_reason'] ?? '');
    if ($stop === 'refusal') return 'Claude ville ikke læse billedet.';
    if ($stop === 'max_tokens') return 'Svaret fra Claude blev afbrudt.';

    $tekst = '';
    foreach ((array) ($r['data']['content'] ?? []) as $blok) {
        if (($blok['type'] ?? '') === 'text') $tekst .= $blok['text'];
    }
    $d = json_decode($tekst, true);
    if (!is_array($d) || !isset($d['placeringer'])) return 'Claudes svar kunne ikke læses.';

    // Fandt modellen ingen navne, er det også et "kan ikke", og næste model prøver.
    $navne = 0;
    foreach ((array) $d['placeringer'] as $p) $navne += count((array) ($p['navne'] ?? []));
    if ($navne === 0) return 'Claude kunne ikke finde nogen navne på sedlen.' . (!empty($d['bemaerkning']) ? ' (' . $d['bemaerkning'] . ')' : '');
    return $d;
}

/* ---------- ruter ---------- */

$do = (string) ($_GET['do'] ?? '');
$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

switch ($do) {

/* --- login / status --- */

case 'login':
    if (!$post) fejl('Forkert metode.', 405);
    $b = kropSomJson();
    $kode = (string) ($b['kode'] ?? '');
    if ($kode !== '' && hash_equals(KODE_BRIAN, $kode)) {
        session_regenerate_id(true);
        $_SESSION['rolle'] = 'brian';
        svar(['rolle' => 'brian']);
    }
    if ($kode !== '' && hash_equals(KODE_A, $kode)) {
        session_regenerate_id(true);
        $_SESSION['rolle'] = 'a';
        svar(['rolle' => 'a']);
    }
    usleep(400000); // lille bremse på gættere
    fejl('Koden passer ikke.', 401);

case 'hvem':
    svar(['rolle' => rolle()]);

case 'logud':
    $_SESSION = [];
    session_destroy();
    svar(['ok' => true]);

/* --- A sender ind --- */

case 'opret':
    if (!$post) fejl('Forkert metode.', 405);
    kraev('a', 'brian');
    sikrDataMappe();
    rydOp();
    $id = bin2hex(random_bytes(8));
    if (!@mkdir(DATA_DIR . '/' . $id, 0770)) fejl('Kunne ikke oprette indsendelsen.', 500);
    $b = kropSomJson();
    $meta = [
        'id'        => $id,
        'status'    => 'kladde',
        'oprettet'  => date('c'),
        'sport'     => (string) ($b['sport'] ?? ''),
        'dato'      => (string) ($b['dato'] ?? date('Y-m-d')),
        'stemning'  => (string) ($b['stemning'] ?? ''),
        'vindere'   => array_values((array) ($b['vindere'] ?? [])),
        'faellesskabspokal' => $b['faellesskabspokal'] ?? null,
        'samtykke'  => (bool) ($b['samtykke'] ?? false),
    ];
    gemMeta($id, $meta);
    svar(['id' => $id]);

case 'laesseddel':
    if (!$post) fejl('Forkert metode.', 405);
    kraev('a', 'brian');
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') fejl('Tomt billede.');
    if (strlen($raw) > MAX_BILLEDE_BYTES) fejl('Billedet er for stort.', 413);
    $info = @getimagesizefromstring($raw);
    if ($info === false || ($info[2] ?? 0) !== IMAGETYPE_JPEG) fejl('Kun JPEG kan sendes.');
    @set_time_limit(150);
    svar(laesSeddel($raw));

case 'billede':
    $id = (string) ($_GET['id'] ?? '');
    $slot = (string) ($_GET['slot'] ?? '');
    if (!gyldigSlot($slot)) fejl('Ugyldigt billednummer.');
    $dir = mappe($id);
    $sti = $dir . '/p' . $slot . '.jpg';

    if ($post) {
        kraev('a', 'brian');
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') fejl('Tomt billede.');
        if (strlen($raw) > MAX_BILLEDE_BYTES) fejl('Billedet er for stort.', 413);
        $info = @getimagesizefromstring($raw);
        if ($info === false || ($info[2] ?? 0) !== IMAGETYPE_JPEG) fejl('Kun JPEG kan sendes.');
        if (!is_dir($dir)) fejl('Ukendt indsendelse.', 404);
        file_put_contents($sti, $raw, LOCK_EX);
        svar(['ok' => true, 'bytes' => strlen($raw)]);
    }

    kraev('brian');
    if (!is_file($sti)) fejl('Billedet findes ikke.', 404);
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($sti));
    header('Cache-Control: private, max-age=600');
    readfile($sti);
    exit;

case 'faerdig':
    if (!$post) fejl('Forkert metode.', 405);
    kraev('a', 'brian');
    $id = (string) ($_GET['id'] ?? '');
    $meta = laesMeta($id);
    $meta['status'] = 'afventer';
    $meta['sendt'] = date('c');
    gemMeta($id, $meta);
    svar(['ok' => true]);

/* --- begivenheder: A læser, Brian retter --- */

case 'begivenheder':
    kraev('a', 'brian');
    sikrDataMappe();
    svar(['begivenheder' => laesBegivenheder()]);

case 'gembegivenhed':
    if (!$post) fejl('Forkert metode.', 405);
    kraev('brian');
    sikrDataMappe();
    $b = kropSomJson();
    $sport = trim((string) ($b['sport'] ?? ''));
    $dato = (string) ($b['dato'] ?? '');
    if ($sport === '') fejl('Skriv hvilken sportsgren det er.');
    if (!preg_match('/^.{1,80}$/u', $sport)) fejl('Sportsgrenen må højst være 80 tegn.');
    if (!gyldigDato($dato)) fejl('Vælg en gyldig dato.');
    $id = (string) ($b['id'] ?? '');
    $liste = laesBegivenheder();
    if ($id === '') {
        $id = bin2hex(random_bytes(8));
        $liste[] = ['id' => $id, 'sport' => $sport, 'dato' => $dato];
    } else {
        $fundet = false;
        foreach ($liste as &$bg) {
            if (($bg['id'] ?? '') === $id) { $bg['sport'] = $sport; $bg['dato'] = $dato; $fundet = true; }
        }
        unset($bg);
        if (!$fundet) fejl('Begivenheden findes ikke længere.', 404);
    }
    gemBegivenheder($liste);
    svar(['id' => $id, 'begivenheder' => laesBegivenheder()]);

case 'sletbegivenhed':
    if (!$post) fejl('Forkert metode.', 405);
    kraev('brian');
    sikrDataMappe();
    $b = kropSomJson();
    $id = (string) ($b['id'] ?? '');
    $liste = array_filter(laesBegivenheder(), fn($bg) => ($bg['id'] ?? '') !== $id);
    gemBegivenheder($liste);
    svar(['begivenheder' => laesBegivenheder()]);

/* --- Brian gennemgår --- */

case 'liste':
    kraev('brian');
    sikrDataMappe();
    $ud = [];
    foreach (glob(DATA_DIR . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $sti = $dir . '/meta.json';
        if (!is_file($sti)) continue;
        $m = json_decode((string) file_get_contents($sti), true);
        if (!is_array($m) || ($m['status'] ?? '') === 'kladde') continue;
        $ud[] = [
            'id'       => $m['id'] ?? basename($dir),
            'status'   => $m['status'] ?? '',
            'sport'    => $m['sport'] ?? '',
            'dato'     => $m['dato'] ?? '',
            'sendt'    => $m['sendt'] ?? ($m['oprettet'] ?? ''),
            'antal'    => count((array) ($m['vindere'] ?? [])),
        ];
    }
    usort($ud, fn($a, $b) => strcmp((string) $b['sendt'], (string) $a['sendt']));
    svar(['poster' => $ud]);

case 'hent':
    kraev('brian');
    svar(['post' => laesMeta((string) ($_GET['id'] ?? ''))]);

case 'godkend':
    if (!$post) fejl('Forkert metode.', 405);
    kraev('brian');
    $id = (string) ($_GET['id'] ?? '');
    $meta = laesMeta($id);
    $b = kropSomJson();
    if (isset($b['vindere'])) $meta['vindere'] = array_values((array) $b['vindere']);
    if (array_key_exists('faellesskabspokal', $b)) $meta['faellesskabspokal'] = $b['faellesskabspokal'];
    if (isset($b['stemning'])) $meta['stemning'] = (string) $b['stemning'];
    if (isset($b['sport'])) $meta['sport'] = (string) $b['sport'];
    $meta['status'] = 'godkendt';
    $meta['godkendt'] = date('c');
    gemMeta($id, $meta);
    svar(['ok' => true]);

case 'gemudsnit':
    if (!$post) fejl('Forkert metode.', 405);
    kraev('brian');
    $id = (string) ($_GET['id'] ?? '');
    $meta = laesMeta($id);
    $b = kropSomJson();
    $meta['udsnit'] = (array) ($b['udsnit'] ?? []);
    gemMeta($id, $meta);
    svar(['ok' => true]);

case 'slet':
    if (!$post) fejl('Forkert metode.', 405);
    kraev('brian');
    slet((string) ($_GET['id'] ?? ''));
    svar(['ok' => true]);

/* --- kommentar-agenten --- */

case 'agentliste':
    kraev('brian');
    require_once __DIR__ . '/agentlib.php';
    $jobs = jobAlle();
    $enig = 0; $uenig = 0;
    foreach ($jobs as $j) {
        if (($j['dom'] ?? '') === 'enig') $enig++;
        if (($j['dom'] ?? '') === 'uenig') $uenig++;
    }
    svar([
        'tilstand'  => TILSTAND,
        'noegler'   => ['claude' => CLAUDE_API_KEY !== '', 'facebook' => FB_PAGE_TOKEN !== ''],
        'enig'      => $enig,
        'uenig'     => $uenig,
        'jobs'      => array_slice($jobs, 0, 200),
    ]);

case 'agentlog':
    kraev('brian');
    require_once __DIR__ . '/agentlib.php';
    $sti = koeMappe() . '/agent.log';
    if (!is_file($sti)) svar(['linjer' => [], 'findes' => false]);
    // Læs kun halen — loggen kan blive lang, og vi vil kun se det nye.
    $alle = file($sti, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $antal = max(1, min(300, (int) ($_GET['antal'] ?? 60)));
    svar([
        'findes'  => true,
        'ialt'    => count($alle),
        'linjer'  => array_slice($alle, -$antal),
        'aendret' => date('c', (int) filemtime($sti)),
    ]);

case 'agentdom':
    if (!$post) fejl('Forkert metode.', 405);
    kraev('brian');
    require_once __DIR__ . '/agentlib.php';
    $b = kropSomJson();
    $id = (string) ($b['id'] ?? '');
    if ($id === '') fejl('Mangler id.');
    $sti = jobSti($id);
    $job = jobLaes($sti);
    if (!$job) fejl('Ukendt kommentar.', 404);
    $dom = (string) ($b['dom'] ?? '');
    if (!in_array($dom, ['enig', 'uenig', ''], true)) fejl('Ugyldig dom.');
    $job['dom'] = $dom;
    $job['dom_note'] = mb_substr((string) ($b['note'] ?? ''), 0, 500);
    $job['gennemset'] = true;
    jobGem($job);
    svar(['ok' => true]);

default:
    fejl('Ukendt handling.', 404);
}
