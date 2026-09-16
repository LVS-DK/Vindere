<?php
/* ------------------------------------------------------------------
   Tjekker at tokenet i config.php faktisk virker, at det ikke udløber,
   og at de nødvendige tilladelser er med.

   Køres med:  php tjek-token.php
   ------------------------------------------------------------------ */

declare(strict_types=1);

/* Det her er et fejlfindingsværktøj. Går noget galt, skal det stå på skærmen
   — ikke ende som en tom side. */
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/config.php';
require __DIR__ . '/agentlib.php';

/* Kan køres to steder: fra en kommandolinje, eller i browseren med
   ?noegle=<din FB_VERIFY_TOKEN> bagefter adressen. Uden nøglen svarer den
   ikke — ellers kunne enhver forbipasserende se, hvad der er sat op. */
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    $givet = (string) ($_GET['noegle'] ?? '');
    if (FB_VERIFY_TOKEN === '' || !hash_equals(FB_VERIFY_TOKEN, $givet)) {
        http_response_code(403);
        exit("Sæt ?noegle=<din FB_VERIFY_TOKEN fra config.php> efter adressen.\n");
    }
}

$noedvendige = [
    'pages_manage_metadata'   => 'abonnere på webhooks — uden den kaldes webhook.php aldrig',
    'pages_read_engagement'   => 'læse sidens egne opslag',
    'pages_manage_engagement' => 'like og svare som siden',
];
$anbefalede = [
    'pages_read_user_content' => 'læse det brugerne selv skriver',
];
$fejl = 0;

function dkDato(int $t): string {
    $m = ['januar','februar','marts','april','maj','juni','juli','august','september','oktober','november','december'];
    return (int) date('j', $t) . '. ' . $m[(int) date('n', $t) - 1] . ' ' . date('Y', $t);
}

function linje(bool $ok, string $tekst): void {
    global $fejl;
    if (!$ok) $fejl++;
    echo ($ok ? ' ok  ' : 'FEJL ') . $tekst . "\n";
    // Skub linjen ud med det samme, så en fejl længere nede ikke tager
    // det, der allerede er skrevet, med sig i faldet.
    @ob_flush(); @flush();
}

$configSti = __DIR__ . '/config.php';
echo "\nTjekker opsætningen\n";
echo str_repeat('-', 62), "\n";
echo "Læser:  " . $configSti . "\n";
if (is_file($configSti)) {
    echo "        " . number_format(filesize($configSti)) . " bytes, sidst ændret "
       . date('j/n Y H:i', (int) filemtime($configSti)) . "\n";
} else {
    echo "        FILEN FINDES IKKE PÅ DEN STI\n";
}
echo str_repeat('-', 62), "\n";

/* --- er config.php overhovedet opdateret? --- */
$manglende = $GLOBALS['vs_manglende'] ?? [];
if ($manglende) {
    echo "FEJL Din config.php mangler " . count($manglende) . " indstilling(er):\n";
    foreach ($manglende as $n) echo "       " . $n . "\n";
    echo "     De er sat til ufarlige standardværdier, så resten kan køre, men\n";
    echo "     agenten virker ikke, før de står i config.php med dine egne værdier.\n";
    echo "     Se afsnittet \"Manglende indstillinger\" i AGENT-OPSAETNING.md.\n";
    $fejl++;
    @ob_flush(); @flush();
}

if (!$manglende) {
    echo " ok  Alle indstillinger er fundet i config.php\n";
    @ob_flush(); @flush();
}

/* --- kan serveren overhovedet kalde ud på nettet? --- */
$harCurl = function_exists('curl_init');
$harStream = (bool) ini_get('allow_url_fopen');
linje($harCurl || $harStream,
    $harCurl ? 'Serveren kan kalde ud på nettet (cURL)'
    : ($harStream ? 'Serveren kan kalde ud på nettet (uden cURL, via streams)'
    : 'Serveren kan IKKE kalde ud på nettet — hverken cURL eller allow_url_fopen er slået til. Kontakt dit webhotel; agenten kan ikke virke uden.'));
if (!$harCurl && !$harStream) {
    echo "\nResten kan ikke tjekkes.\n";
    exit(1);
}

linje(FB_APP_SECRET !== '',  'App-hemmelighed er sat');
linje(FB_PAGE_TOKEN !== '',  'Sidetoken er sat');
linje(FB_PAGE_ID !== '',     'Side-ID er sat');
linje(FB_VERIFY_TOKEN !== '' && FB_VERIFY_TOKEN !== 'skift-mig-verify', 'Verify-token er ændret fra standard');
echo (CLAUDE_API_KEY !== '' ? ' ok  ' : ' obs ')
   . 'Claude-nøgle'
   . (CLAUDE_API_KEY !== '' ? ' er sat' : ' er ikke sat — agenten kører, men sender hver kommentar videre til dig uden at vurdere den')
   . "\n";

if (FB_PAGE_TOKEN === '') {
    echo "\nUden sidetoken kan resten ikke tjekkes.\n";
    exit(1);
}

/* --- hvem hører tokenet til? --- */
$r = httpJson(FB_API . '/me?fields=id,name&access_token=' . rawurlencode(FB_PAGE_TOKEN), [], null, 20);
if ($r['kode'] !== 200) {
    linje(false, 'Tokenet blev afvist: ' . ($r['data']['error']['message'] ?? ('HTTP ' . $r['kode'] . ' ' . $r['fejl'])));
    echo "\nHent et nyt token — se AGENT-OPSAETNING.md, punkt 2.\n";
    exit(1);
}
$navn = (string) ($r['data']['name'] ?? '?');
$id   = (string) ($r['data']['id'] ?? '');
linje(true, 'Tokenet hører til: ' . $navn . ' (' . $id . ')');
linje($id === FB_PAGE_ID, 'Side-ID i config passer med tokenet'
    . ($id === FB_PAGE_ID ? '' : ' — config siger "' . FB_PAGE_ID . '", tokenet siger "' . $id . '"'));

/* --- udløber det, og hvad må det? --- */
if (FB_APP_SECRET !== '') {
    $d = httpJson(FB_API . '/debug_token?input_token=' . rawurlencode(FB_PAGE_TOKEN)
        . '&access_token=' . rawurlencode(FB_PAGE_TOKEN), [], null, 20);
    $data = $d['data']['data'] ?? null;
    if (is_array($data)) {
        $udloeber = (int) ($data['expires_at'] ?? 0);
        linje($udloeber === 0, $udloeber === 0
            ? 'Tokenet udløber ikke'
            : 'Tokenet udløber ' . dkDato($udloeber) . ' — det er et korttidstoken. Byt det til et langtidsholdbart, ellers holder agenten op med at virke den dag.');

        $scopes = (array) ($data['scopes'] ?? []);
        if ($scopes) {
            foreach ($noedvendige as $n => $hvorfor) {
                linje(in_array($n, $scopes, true), 'Tilladelse ' . $n . ' (' . $hvorfor . ')');
            }
            foreach ($anbefalede as $n => $hvorfor) {
                echo (in_array($n, $scopes, true) ? ' ok  ' : ' obs ')
                    . 'Tilladelse ' . $n . ' (' . $hvorfor . ')'
                    . (in_array($n, $scopes, true) ? '' : ' — anbefalet, ikke krævet')
                    . "\n";
            }
        } else {
            echo ' ??  Kunne ikke læse tilladelserne fra tokenet' . "\n";
        }
    } else {
        echo ' ??  Kunne ikke slå tokenet op (debug_token svarede ikke som ventet)' . "\n";
    }
}

/* --- kan den overhovedet se opslag? --- */
$p = httpJson(FB_API . '/' . rawurlencode(FB_PAGE_ID ?: $id) . '/posts?limit=1&access_token=' . rawurlencode(FB_PAGE_TOKEN), [], null, 20);
linje($p['kode'] === 200, $p['kode'] === 200
    ? 'Kan læse sidens opslag'
    : 'Kan ikke læse sidens opslag: ' . ($p['data']['error']['message'] ?? ('HTTP ' . $p['kode'])));

echo str_repeat('-', 62), "\n";
if ($fejl === 0) {
    echo "Alt ser rigtigt ud. Tilstand: " . TILSTAND . "\n";
    echo "Næste skridt: opret webhooken (punkt 3 i AGENT-OPSAETNING.md).\n\n";
} else {
    echo $fejl . ($fejl === 1 ? " ting mangler" : " ting mangler") . ". Se AGENT-OPSAETNING.md.\n\n";
}
exit($fejl === 0 ? 0 : 1);
