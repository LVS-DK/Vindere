<?php
/* ------------------------------------------------------------------
   Tager imod Metas webhook-kald og lægger nye kommentarer i kø.
   Denne fil skal være offentligt tilgængelig — det er Meta der kalder den.
   Den svarer altid hurtigt; alt arbejde sker i agent.php.
   ------------------------------------------------------------------ */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/agentlib.php';

/* --- Metas verifikation, når webhooken oprettes --- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $mode = $_GET['hub_mode'] ?? '';
    $token = (string) ($_GET['hub_verify_token'] ?? '');
    $challenge = $_GET['hub_challenge'] ?? '';
    if ($mode === 'subscribe' && FB_VERIFY_TOKEN !== '' && hash_equals(FB_VERIFY_TOKEN, $token)) {
        // Meta accepterer KUN udfordringen og intet andet. Er der sluppet et
        // mellemrum, en BOM eller en advarsel ud fra en anden fil, fejler
        // verifikationen med en besked, der ikke røber hvorfor. Så vi smider
        // alt, hvad der måtte være skrevet indtil nu, væk.
        while (ob_get_level() > 0) { @ob_end_clean(); }
        if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
        echo $challenge;
        exit;
    }
    agentLog('VERIFIKATION afvist (mode="' . $mode . '", token passede ikke)');
    http_response_code(403);
    exit;
}

$raa = file_get_contents('php://input') ?: '';

/* --- signaturtjek: uden det kan hvem som helst fodre agenten --- */
$sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if (FB_APP_SECRET === '' || strpos($sig, 'sha256=') !== 0) {
    agentLog('AFVIST kald uden gyldig signatur');
    http_response_code(403);
    exit;
}
$forventet = 'sha256=' . hash_hmac('sha256', $raa, FB_APP_SECRET);
if (!hash_equals($forventet, $sig)) {
    agentLog('AFVIST kald med forkert signatur');
    http_response_code(403);
    exit;
}

/* --- svar Meta med det samme; de venter ikke tålmodigt --- */
http_response_code(200);
echo 'ok';
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

$data = json_decode($raa, true);
if (!is_array($data)) { agentLog('MODTAGET kald med ulæseligt indhold'); exit; }

rydKoeOp();
$nu = time();

/* Hvert kald noteres, også når intet kommer i kø. Ellers kan man ikke se
   forskel på "Meta sendte ingenting" og "Meta sendte noget, vi sorterede fra". */
$antalAendringer = 0;
foreach ((array) ($data['entry'] ?? []) as $e) $antalAendringer += count((array) ($e['changes'] ?? []));
agentLog('MODTAGET ' . $antalAendringer . ' ændring(er) fra Meta');

foreach ((array) ($data['entry'] ?? []) as $entry) {
    foreach ((array) ($entry['changes'] ?? []) as $aendring) {
        $felt = (string) ($aendring['field'] ?? '');
        if ($felt !== 'feed') { agentLog('  sprunget over: felt "' . $felt . '", ikke feed'); continue; }

        $v = (array) ($aendring['value'] ?? []);
        $item = (string) ($v['item'] ?? '');
        $verb = (string) ($v['verb'] ?? '');
        if ($item !== 'comment') { agentLog('  sprunget over: item "' . $item . '" (' . $verb . '), ikke en kommentar'); continue; }
        if ($verb !== 'add')     { agentLog('  sprunget over: verb "' . $verb . '", ikke en ny kommentar'); continue; }

        $id = (string) ($v['comment_id'] ?? '');
        if ($id === '') { agentLog('  sprunget over: kommentaren har intet comment_id'); continue; }

        $fraId = (string) ($v['from']['id'] ?? '');
        // Siden svarer ikke sig selv. Uden denne linje kan agenten
        // ende i en løkke med sine egne svar.
        if ($fraId !== '' && FB_PAGE_ID !== '' && $fraId === FB_PAGE_ID) {
            agentLog('  sprunget over: skrevet af siden selv'); continue;
        }

        if (is_file(jobSti($id))) { agentLog('  sprunget over: ' . $id . ' er set før'); continue; }

        $tekst = trim((string) ($v['message'] ?? ''));
        if ($tekst === '') {
            agentLog('  sprunget over: ' . $id . ' har ingen tekst — mangler pages_read_user_content?'); continue;
        }

        $postId = (string) ($v['post_id'] ?? ($entry['id'] ?? ''));
        $oprettet = isset($v['created_time']) ? (int) $v['created_time'] : $nu;

        // Her er created_time KOMMENTARENS eget tidspunkt, ikke opslagets.
        // Den er derfor altid ny; tjekket fanger kun forsinkede genudsendelser
        // fra Meta. Opslagets alder afgøres i agenten, hvor vi kan slå den op.
        if ($nu - $oprettet > OVERVAAGES_TIMER * 3600) {
            agentLog('  sprunget over: kommentaren ' . $id . ' er selv ' . round(($nu - $oprettet) / 3600) . ' timer gammel — genudsendelse?');
            continue;
        }

        jobGem([
            'id'          => $id,
            'post_id'     => $postId,
            'parent_id'   => (string) ($v['parent_id'] ?? ''),
            'fra_id'      => $fraId,
            'fra_navn'    => (string) ($v['from']['name'] ?? 'Ukendt'),
            'tekst'       => $tekst,
            'modtaget'    => date('c'),
            'forfalder'   => $nu + random_int(FORSINKELSE_MIN * 60, FORSINKELSE_MAKS * 60),
            'status'      => 'i_koe',
            'beslutning'  => null,
            'handlet'     => false,
            'gennemset'   => false,
            'tilstand'    => TILSTAND,
            'opslag_tekst' => '',
        ]);
        agentLog('KØ ' . $id . ' fra ' . (string) ($v['from']['name'] ?? '?'));
    }
}

/* ------------------------------------------------------------------
   Har webhotellet ikke cron, ville køen aldrig blive behandlet. Så tager
   vi et par modne kommentarer med her, mens vi alligevel er vågne.
   Meta har fået sit svar for længst; det her sker efter.

   Det er en reserve, ikke en erstatning: kommer der ingen nye kommentarer,
   bliver de sidste i køen liggende, indtil noget kalder agent.php.
   ------------------------------------------------------------------ */
$modne = behandlKoe(3);
if ($modne) agentLog('WEBHOOK behandlede ' . count($modne) . ' modne kommentar(er)');
