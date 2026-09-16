<?php
/* ------------------------------------------------------------------
   Kommentar-agenten — fælles funktioner for webhook.php og agent.php
   ------------------------------------------------------------------ */

declare(strict_types=1);

/* ------------------------------------------------------------------
   Manglende indstillinger må ikke vælte noget.

   Bliver config.php ikke opdateret, når nye indstillinger kommer til,
   ville hver eneste fil dø med en fatal fejl på den første linje, der
   nævner en konstant, der ikke findes. I stedet udfylder vi de manglende
   med ufarlige standardværdier og husker, hvad der manglede, så
   tjek-token.php kan sige det med rene ord.
   ------------------------------------------------------------------ */

$GLOBALS['vs_manglende'] = [];

(function () {
    $standarder = [
        'TILSTAND'            => 'skygge',
        'FB_APP_SECRET'       => '',
        'FB_VERIFY_TOKEN'     => '',
        'FB_PAGE_ID'          => '',
        'FB_PAGE_TOKEN'       => '',
        'FB_API'              => 'https://graph.facebook.com/v25.0',
        'CLAUDE_API_KEY'      => '',
        'CLAUDE_API_URL'      => 'https://api.anthropic.com/v1/messages',
        'CLAUDE_MODEL'        => 'claude-haiku-4-5-20251001',
        'FORSINKELSE_MIN'     => 4,
        'FORSINKELSE_MAKS'    => 14,
        'MAKS_SVAR_PR_TRAAD'  => 6,
        'OVERVAAGES_TIMER'    => 48,
        'CRON_NOEGLE'         => '',
        'OPBEVARING_DAGE'     => 14,
        'MAX_BILLEDE_BYTES'   => 8 * 1024 * 1024,
    ];
    foreach ($standarder as $navn => $vaerdi) {
        if (!defined($navn)) {
            define($navn, $vaerdi);
            $GLOBALS['vs_manglende'][] = $navn;
        }
    }
    // KOE_DIR afhænger af DATA_DIR og skal derfor sættes til sidst.
    if (!defined('KOE_DIR')) {
        define('KOE_DIR', (defined('DATA_DIR') ? DATA_DIR : __DIR__ . '/data') . '/kommentarer');
        $GLOBALS['vs_manglende'][] = 'KOE_DIR';
    }
})();

/* ---------- kø ---------- */

function koeMappe(): string {
    if (!is_dir(KOE_DIR)) @mkdir(KOE_DIR, 0770, true);
    $ht = KOE_DIR . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
    }
    return KOE_DIR;
}

function jobSti(string $kommentarId): string {
    return koeMappe() . '/' . sha1($kommentarId) . '.json';
}

function jobGem(array $job): void {
    file_put_contents(jobSti($job['id']), json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function jobLaes(string $sti): ?array {
    $d = json_decode((string) @file_get_contents($sti), true);
    return is_array($d) ? $d : null;
}

function jobAlle(): array {
    $ud = [];
    foreach (glob(koeMappe() . '/*.json') ?: [] as $f) {
        $j = jobLaes($f);
        if ($j) $ud[] = $j;
    }
    usort($ud, function ($a, $b) { return strcmp((string) $b['modtaget'], (string) $a['modtaget']); });
    return $ud;
}

function svarITraad(string $postId): int {
    $n = 0;
    foreach (jobAlle() as $j) {
        if (($j['post_id'] ?? '') === $postId && !empty($j['handlet']) && ($j['beslutning']['spor'] ?? '') === 'svar') $n++;
    }
    return $n;
}

function rydKoeOp(): void {
    $graense = time() - (OPBEVARING_DAGE * 86400);
    foreach (glob(koeMappe() . '/*.json') ?: [] as $f) {
        $t = @filemtime($f);
        if ($t !== false && $t < $graense) @unlink($f);
    }
}

/* ---------- hårde regler, som modellen ikke får lov at overtrumfe ---------- */

/**
 * Nogle kommentarer må aldrig besvares automatisk, uanset hvad en model
 * mener om dem. Listen er bevidst bred: falder vi til den forkerte side her,
 * koster det et menneskes tillid, ikke et svar.
 */
function haardRegel(string $tekst): ?array {
    $t = mb_strtolower($tekst, 'UTF-8');

    // Akut: nogen vil af med sit billede eller sit navn, eller nævner
    // myndigheder. Mønstrene er bevidst løse — hellere en falsk alarm på
    // dit bord end ét oversete menneske, der har bedt om at komme af.
    $akut = [
        'fjernelse'  => '/\b(fjern|slet|tag)\w*\b[^.!?]{0,40}?\b(billed\w*|foto\w*|mit navn|mig|opslag\w*)\b/u',
        'ned med det'=> '/\b(tag|ta)\w*\b[^.!?]{0,25}\bned\b/u',
        'vil ikke på'=> '/\b(vil|ønsker)\b[^.!?]{0,15}\bikke\b[^.!?]{0,30}\b(på|med|være|figurere|optræde)\b/u',
        'manglende samtykke' => '/\b(ikke|ingen|aldrig)\b[^.!?]{0,25}\b(sagt ja|givet lov|spurgt|samtykke|tilladelse)\b/u',
        'samtykke trukket'   => '/\b(tr(æ|ae)kk\w*|fortryder)\b[^.!?]{0,30}\bsamtykke\b/u',
        'databeskyttelse'    => '/\b(gdpr|persondata\w*|databeskyttelse|datatilsyn\w*)\b/u',
        'myndigheder'        => '/\b(advokat\w*|politi\w*|anmeld\w*|kr(æ|ae)nk\w*|ulovlig\w*)\b/u',
    ];
    foreach ($akut as $navn => $m) {
        if (preg_match($m, $t)) {
            return [
                'spor' => 'akut',
                'begrundelse' => 'Handler om samtykke, sletning eller myndigheder (' . $navn . '). Agenten svarer aldrig selv her.',
                'tillid' => 1.0,
            ];
        }
    }

    // Følsomt: rører ved et menneskes situation. Skal besvares af et menneske,
    // men haster ikke på samme måde.
    $foelsomt = [
        '/\b(d(ø|oe)d\w*|begravels\w*|bisat\w*|savner ham|savner hende)\b/u',
        '/\b(indlagt|hospital\w*|sygehus\w*|sygdom\w*|kr(æ|ae)ft|demens|alzheimer)\b/u',
        '/\b(misbrug\w*|stoffer|afrusning|antabus|druk\w*|(æ|ae)dru)\b/u',
        '/\b(depress\w*|angst|psykiatri\w*|indl(æ|ae)ggels\w*|selvmord\w*)\b/u',
        '/\b(ensom\w*|hjeml(ø|oe)s\w*|skilsmiss\w*|fyret|arbejdsl(ø|oe)s\w*)\b/u',
        '/\bked af det\b/u',
    ];
    foreach ($foelsomt as $m) {
        if (preg_match($m, $t)) {
            return [
                'spor' => 'til_dig',
                'begrundelse' => 'Rører ved noget personligt i en persons situation. Det skal et menneske svare på.',
                'tillid' => 1.0,
            ];
        }
    }
    return null;
}

/* ---------- kald til Claude ---------- */

/**
 * Kalder en adresse og tolker svaret som JSON.
 *
 * Bruger cURL hvor det er tilgængeligt, og falder ellers tilbage til
 * almindelige streams. Ikke alle webhoteller har cURL slået til, og uden
 * denne reserve ville hele agenten dø tavst på sådan et.
 */
function httpJson(string $url, array $headers, ?array $krop, int $timeout = 30): array {
    $body = $krop === null ? null : json_encode($krop, JSON_UNESCAPED_UNICODE);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $svar = curl_exec($ch);
        $kode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fejl = curl_error($ch);
        curl_close($ch);
        if ($svar === false) return ['kode' => 0, 'data' => null, 'raa' => '', 'fejl' => $fejl];
        return ['kode' => $kode, 'data' => json_decode((string) $svar, true), 'raa' => $svar, 'fejl' => ''];
    }

    if (!ini_get('allow_url_fopen')) {
        return ['kode' => 0, 'data' => null, 'raa' => '',
                'fejl' => 'Serveren har hverken cURL eller allow_url_fopen slået til.'];
    }

    $h = $headers;
    if ($body !== null) $h[] = 'Content-Length: ' . strlen($body);
    $ctx = stream_context_create([
        'http' => [
            'method'        => $body === null ? 'GET' : 'POST',
            'header'        => implode("\r\n", $h),
            'content'       => $body,
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $svar = @file_get_contents($url, false, $ctx);
    $kode = 0;
    foreach ($http_response_header ?? [] as $linje) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $linje, $m)) $kode = (int) $m[1];
    }
    if ($svar === false) {
        $e = error_get_last();
        return ['kode' => $kode, 'data' => null, 'raa' => '',
                'fejl' => $e['message'] ?? 'Kaldet kunne ikke gennemføres.'];
    }
    return ['kode' => $kode, 'data' => json_decode($svar, true), 'raa' => $svar, 'fejl' => ''];
}

function agentPrompt(array $job): string {
    $opslag = trim((string) ($job['opslag_tekst'] ?? ''));
    return <<<TXT
Du hjælper med at passe kommentarsporet under et Facebook-opslag fra en dansk
forening af væresteder. Opslaget fejrer vinderne af et idrætsarrangement — folk
har fået pokaler, og der er billeder af dem. Tonen i tråden er varm og
uformel, og mange af dem, der skriver, er brugere af værestederne eller deres
pårørende.

Din opgave er at placere ÉN kommentar i ét af fire spor og, hvis den skal
besvares, skrive svaret.

SPORENE

"svar": kommentaren er varm, positiv og uden konkret indhold — en lykønskning,
en hilsen, "sejt gået", en tagget ven, et hjerte. Skriv et kort, varmt svar på
dansk. Højst to sætninger. Skriv som et menneske fra foreningen, ikke som en
kundeservice. Brug personens fornavn hvis det står i kommentaren. Højst én
emoji, og kun hvis det falder naturligt. Gentag aldrig kommentarens egne ord
tilbage. Sig aldrig "tak for din kommentar".

"like": kommentaren er kort og rent positiv, men der er ikke rigtig noget at
svare på — "💪", "Tillykke", "Godt gået". Et like er det rigtige og kan ikke
rammes forkert.

"til_dig": alt hvad et menneske skal tage sig af. Konkrete spørgsmål (hvornår er
næste arrangement, hvem vandt, hvor foregik det), kritik, utilfredshed, noget der
handler om en bestemt persons situation, sarkasme du er i tvivl om, eller noget
du bare ikke er sikker på. Skriv i begrundelsen hvad der skal tages stilling til.

"ignorer": spam, reklame, noget helt uden for emnet, eller ren volapyk.

REGLER

Er du i tvivl mellem "svar" og "til_dig", vælger du altid "til_dig". Et
ubesvaret svar er usynligt; et forkert svar står der for altid.
Lov aldrig noget på foreningens vegne. Nævn aldrig nogen, der ikke selv står
i kommentaren. Kommenter aldrig på nogens helbred, økonomi eller livssituation.

OPSLAGET
$opslag

KOMMENTAREN
Skrevet af: {$job['fra_navn']}
Tekst: {$job['tekst']}

Svar KUN med JSON på formen:
{"spor":"svar|like|til_dig|ignorer","tillid":0.0-1.0,"begrundelse":"kort, på dansk","svar":"kun hvis spor er svar, ellers tom streng"}
TXT;
}

function klassificer(array $job): array {
    if (CLAUDE_API_KEY === '') {
        return ['spor' => 'til_dig', 'tillid' => 0.0, 'begrundelse' => 'Ingen API-nøgle sat i config.php.', 'svar' => ''];
    }
    $r = httpJson(CLAUDE_API_URL, [
        'Content-Type: application/json',
        'x-api-key: ' . CLAUDE_API_KEY,
        'anthropic-version: 2023-06-01',
    ], [
        'model'      => CLAUDE_MODEL,
        'max_tokens' => 400,
        'messages'   => [['role' => 'user', 'content' => agentPrompt($job)]],
    ], 45);

    if ($r['kode'] !== 200) {
        $besked = $r['data']['error']['message'] ?? ($r['fejl'] ?: ('HTTP ' . $r['kode']));
        return ['spor' => 'til_dig', 'tillid' => 0.0, 'begrundelse' => 'Modellen svarede ikke: ' . $besked, 'svar' => '', 'fejl' => true];
    }

    $tekst = '';
    foreach ((array) ($r['data']['content'] ?? []) as $blok) {
        if (($blok['type'] ?? '') === 'text') $tekst .= $blok['text'];
    }
    $b = tolkJson($tekst);
    if ($b === null) {
        return ['spor' => 'til_dig', 'tillid' => 0.0, 'begrundelse' => 'Kunne ikke læse modellens svar som JSON.', 'svar' => '', 'fejl' => true];
    }

    $spor = (string) ($b['spor'] ?? 'til_dig');
    if (!in_array($spor, ['svar', 'like', 'til_dig', 'ignorer'], true)) $spor = 'til_dig';
    $svar = trim((string) ($b['svar'] ?? ''));
    $tillid = (float) ($b['tillid'] ?? 0);

    // Lav tillid håndteres som tvivl, og tvivl går til et menneske.
    if ($spor === 'svar' && ($tillid < 0.7 || $svar === '')) {
        $spor = 'til_dig';
        $b['begrundelse'] = 'Usikker på et svar (' . number_format($tillid, 2) . '). ' . (string) ($b['begrundelse'] ?? '');
    }
    return ['spor' => $spor, 'tillid' => $tillid, 'begrundelse' => trim((string) ($b['begrundelse'] ?? '')), 'svar' => $svar];
}

function tolkJson(string $tekst) {
    $d = json_decode(trim($tekst), true);
    if (is_array($d)) return $d;
    if (preg_match('/\{.*\}/s', $tekst, $m)) {
        $d = json_decode($m[0], true);
        if (is_array($d)) return $d;
    }
    return null;
}

/* ---------- Facebook ---------- */

function fbSvar(string $kommentarId, string $besked): array {
    return httpJson(FB_API . '/' . rawurlencode($kommentarId) . '/comments', [
        'Content-Type: application/json',
    ], ['message' => $besked, 'access_token' => FB_PAGE_TOKEN]);
}

function fbLike(string $kommentarId): array {
    return httpJson(FB_API . '/' . rawurlencode($kommentarId) . '/likes', [
        'Content-Type: application/json',
    ], ['access_token' => FB_PAGE_TOKEN]);
}

/**
 * Henter opslagets tekst og alder.
 *
 * Alderen bruges til at lade gamle tråde passe sig selv. Den kan ikke tages
 * fra webhook-kaldet: dér er created_time kommentarens eget tidspunkt, ikke
 * opslagets, og en kommentar er jo altid ny, når den lander.
 */
function fbOpslag(string $postId): array {
    if (FB_PAGE_TOKEN === '' || $postId === '') return ['tekst' => '', 'oprettet' => 0];
    $r = httpJson(FB_API . '/' . rawurlencode($postId)
        . '?fields=message,created_time&access_token=' . rawurlencode(FB_PAGE_TOKEN), [], null, 15);
    $t = (string) ($r['data']['created_time'] ?? '');
    return [
        'tekst'    => (string) ($r['data']['message'] ?? ''),
        'oprettet' => $t !== '' ? (int) strtotime($t) : 0,
    ];
}

/* ---------- behandling af køen ---------- */

/**
 * Tager de kommentarer, hvis forsinkelse er udløbet, og afgør hvad der skal ske.
 *
 * Bruges af agent.php (cronjobbet) og af webhook.php, så systemet også kommer
 * videre på et webhotel uden cron — så længe der kommer nye kommentarer.
 * Returnerer en linje pr. behandlet kommentar.
 */
function behandlKoe(int $maks = 10): array {
    $nu = time();
    $ud = [];

    foreach (glob(koeMappe() . '/*.json') ?: [] as $sti) {
        if (count($ud) >= $maks) break;
        $job = jobLaes($sti);
        if (!$job || ($job['status'] ?? '') !== 'i_koe') continue;
        if ((int) ($job['forfalder'] ?? 0) > $nu) continue;

        // 1. Hårde regler først. Modellen får aldrig lov at overtrumfe dem.
        $haard = haardRegel((string) $job['tekst']);
        if ($haard !== null) {
            $job['beslutning'] = $haard + ['svar' => ''];
            $job['status'] = 'afgjort';
            $job['handlet'] = false;
            $job['afgjort'] = date('c');
            jobGem($job);
            agentLog('HÅRD REGEL ' . $job['id'] . ' → ' . $haard['spor']);
            $ud[] = $job['id'] . ' → ' . $haard['spor'] . ' (hård regel)';
            continue;
        }

        // 2. Hent opslaget: teksten, så modellen ved hvad tråden handler om,
        //    og alderen, så gamle tråde kan passe sig selv.
        if (($job['opslag_hentet'] ?? false) !== true && ($job['post_id'] ?? '') !== '') {
            $o = fbOpslag((string) $job['post_id']);
            $job['opslag_tekst'] = mb_substr($o['tekst'], 0, 1500);
            $job['opslag_oprettet'] = $o['oprettet'];
            $job['opslag_hentet'] = true;
        }

        $opslagAlder = (int) ($job['opslag_oprettet'] ?? 0);
        if ($opslagAlder > 0 && (time() - $opslagAlder) > OVERVAAGES_TIMER * 3600) {
            $timer = round((time() - $opslagAlder) / 3600);
            $job['beslutning'] = [
                'spor' => 'ignorer',
                'tillid' => 1.0,
                'begrundelse' => 'Opslaget er ' . $timer . ' timer gammelt (grænsen er ' . OVERVAAGES_TIMER . '). Gamle tråde passer sig selv.',
                'svar' => '',
            ];
            $job['status'] = 'afgjort';
            $job['handlet'] = false;
            $job['afgjort'] = date('c');
            jobGem($job);
            agentLog('GAMMELT OPSLAG ' . $job['id'] . ' → ignorer (' . $timer . ' timer)');
            $ud[] = $job['id'] . ' → ignorer (gammelt opslag)';
            continue;
        }

        // 3. Lad modellen vurdere.
        $b = klassificer($job);
        $job['beslutning'] = $b;
        $job['status'] = 'afgjort';
        $job['afgjort'] = date('c');

        // 4. Loft over hvor meget agenten fylder i én tråd.
        if ($b['spor'] === 'svar' && svarITraad((string) $job['post_id']) >= MAKS_SVAR_PR_TRAAD) {
            $job['beslutning']['spor'] = 'like';
            $job['beslutning']['begrundelse'] = 'Agenten har allerede svaret nok i denne tråd. ' . $b['begrundelse'];
            $b = $job['beslutning'];
        }

        // 5. Handl — men kun så meget som tilstanden tillader.
        $maaLike  = in_array(TILSTAND, ['likes', 'fuld'], true);
        $maaSvare = (TILSTAND === 'fuld');

        if ($b['spor'] === 'like' && $maaLike) {
            $r = fbLike((string) $job['id']);
            $job['handlet'] = ($r['kode'] === 200);
            $job['handling_fejl'] = $r['kode'] === 200 ? '' : ('HTTP ' . $r['kode'] . ' ' . ($r['data']['error']['message'] ?? $r['fejl']));
        } elseif ($b['spor'] === 'svar' && $maaSvare && $b['svar'] !== '') {
            $r = fbSvar((string) $job['id'], (string) $b['svar']);
            $job['handlet'] = ($r['kode'] === 200);
            $job['handling_fejl'] = $r['kode'] === 200 ? '' : ('HTTP ' . $r['kode'] . ' ' . ($r['data']['error']['message'] ?? $r['fejl']));
        }

        jobGem($job);
        agentLog('AFGJORT ' . $job['id'] . ' → ' . $b['spor']
            . ($job['handlet'] ? ' (udført)' : ' (kun noteret, tilstand: ' . TILSTAND . ')'));
        $ud[] = $job['id'] . ' → ' . $b['spor'] . ($job['handlet'] ? ' (udført)' : '');
    }
    return $ud;
}

/* ---------- log ---------- */

function agentLog(string $linje): void {
    @file_put_contents(koeMappe() . '/agent.log',
        date('c') . ' ' . $linje . "\n", FILE_APPEND | LOCK_EX);
}
