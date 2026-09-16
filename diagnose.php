<?php
/* ------------------------------------------------------------------
   Selvstændig diagnose. Afhænger ikke af andet end config.php, og kan
   ikke gå ned på en manglende indstilling.

   Læg filen i samme mappe som config.php og åbn:
       https://ditdomæne.dk/vindere/diagnose.php?noegle=DIN_VERIFY_TOKEN

   Har du ikke sat FB_VERIFY_TOKEN endnu, så brug ?noegle=diagnose

   SLET FILEN, når du er færdig med at fejlsøge.
   ------------------------------------------------------------------ */

ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

$configSti = __DIR__ . '/config.php';

/* --- filen selv, FØR vi indlæser den --- */
$raa = is_file($configSti) ? (string) file_get_contents($configSti) : null;

/* --- indlæs den, men lad den ikke vælte os --- */
$indlaesFejl = '';
if ($raa !== null) {
    try {
        require_once $configSti;
    } catch (Throwable $e) {
        $indlaesFejl = get_class($e) . ': ' . $e->getMessage();
    }
}

/* --- adgang --- */
$givet = (string) ($_GET['noegle'] ?? '');
$forventet = defined('FB_VERIFY_TOKEN') && FB_VERIFY_TOKEN !== '' ? FB_VERIFY_TOKEN : 'diagnose';
if (!hash_equals($forventet, $givet)) {
    http_response_code(403);
    exit("Sæt ?noegle=<din FB_VERIFY_TOKEN> efter adressen.\n"
       . "Er FB_VERIFY_TOKEN ikke sat endnu, så brug ?noegle=diagnose\n");
}

function skjul($v): string {
    $s = (string) $v;
    if ($s === '') return '(tom)';
    $n = strlen($s);
    if ($n <= 12) return str_repeat('•', $n) . "  ($n tegn)";
    return substr($s, 0, 4) . str_repeat('•', 6) . substr($s, -4) . "  ($n tegn)";
}

echo "DIAGNOSE — Vindersedlen\n";
echo str_repeat('=', 66), "\n\n";

/* ---------- 1. filen ---------- */
echo "1. CONFIG-FILEN\n", str_repeat('-', 66), "\n";
echo "Sti:            $configSti\n";
if ($raa === null) {
    echo "FINDES IKKE. Alt andet herunder er derfor meningsløst.\n";
    exit;
}
echo "Størrelse:      " . number_format(strlen($raa)) . " bytes\n";
echo "Sidst ændret:   " . date('j/n Y H:i:s', (int) filemtime($configSti)) . "\n";
echo "Antal define(): " . preg_match_all('/^\s*define\s*\(/m', $raa) . "\n";

$bom = substr($raa, 0, 3) === "\xEF\xBB\xBF";
echo "BOM i starten:  " . ($bom ? "JA — fjern den, den giver fejl i api.php" : "nej") . "\n";

$foerTag = strpos($raa, '<?php');
echo "Starter med <?php: " . ($foerTag === 0 ? 'ja' : ($foerTag === false ? 'NEJ — der er intet <?php i filen' : "NEJ — der står $foerTag tegn før")) . "\n";

$lukning = strpos($raa, '?>');
echo "Indeholder ?>:  " . ($lukning === false ? 'nej' : "JA ved tegn $lukning — alt efter det bliver ikke kørt") . "\n";

$aabne = substr_count($raa, '/*');
$lukkede = substr_count($raa, '*/');
echo "Kommentarer:    $aabne stk '/*' og $lukkede stk '*/'"
   . ($aabne === $lukkede ? " (passer)" : " — PASSER IKKE, en kommentar er ikke lukket") . "\n";

if ($indlaesFejl) echo "Fejl ved indlæsning: $indlaesFejl\n";
echo "\n";

/* ---------- 2. indstillingerne ---------- */
echo "2. INDSTILLINGER\n", str_repeat('-', 66), "\n";
$hemmelige = ['FB_APP_SECRET', 'FB_PAGE_TOKEN', 'CLAUDE_API_KEY', 'FB_VERIFY_TOKEN', 'KODE_A', 'KODE_BRIAN'];
$forventede = [
    'KODE_A', 'KODE_BRIAN', 'DATA_DIR', 'OPBEVARING_DAGE', 'MAX_BILLEDE_BYTES',
    'TILSTAND', 'FB_APP_SECRET', 'FB_VERIFY_TOKEN', 'FB_PAGE_ID', 'FB_PAGE_TOKEN',
    'FB_API', 'CLAUDE_API_KEY', 'CLAUDE_API_URL', 'CLAUDE_MODEL',
    'FORSINKELSE_MIN', 'FORSINKELSE_MAKS', 'MAKS_SVAR_PR_TRAAD',
    'OVERVAAGES_TIMER', 'KOE_DIR',
];
$mangler = [];
foreach ($forventede as $n) {
    $findes = defined($n);
    if (!$findes) $mangler[] = $n;
    $vaerdi = '';
    if ($findes) {
        $vaerdi = in_array($n, $hemmelige, true) ? skjul(constant($n)) : (string) constant($n);
        if ($vaerdi === '') $vaerdi = '(tom)';
    }
    // står navnet i filens tekst, selvom konstanten ikke er defineret?
    $iTekst = (bool) preg_match("/define\s*\(\s*'" . preg_quote($n, '/') . "'/", $raa);
    printf("%-20s %-9s %s\n",
        $n,
        $findes ? 'defineret' : ($iTekst ? 'I FILEN!' : 'mangler'),
        $findes ? $vaerdi : ($iTekst ? 'står i filen, men blev ikke kørt' : ''));
}
echo "\n";
if ($mangler) {
    echo "Mangler: " . implode(', ', $mangler) . "\n\n";
} else {
    echo "Alle forventede indstillinger er til stede.\n\n";
}

/* ---------- 3. serveren ---------- */
echo "3. SERVEREN\n", str_repeat('-', 66), "\n";
echo "PHP-version:      " . PHP_VERSION . "\n";
echo "cURL:             " . (function_exists('curl_init') ? 'ja' : 'nej') . "\n";
echo "allow_url_fopen:  " . (ini_get('allow_url_fopen') ? 'ja' : 'nej') . "\n";
echo "mbstring:         " . (function_exists('mb_strtolower') ? 'ja' : 'NEJ — påkrævet') . "\n";
echo "Datamappe:        " . (defined('DATA_DIR') ? DATA_DIR : '(DATA_DIR mangler)') . "\n";
if (defined('DATA_DIR')) {
    echo "  findes:         " . (is_dir(DATA_DIR) ? 'ja' : 'nej') . "\n";
    echo "  skrivbar:       " . (is_dir(DATA_DIR) && is_writable(DATA_DIR) ? 'ja' : 'NEJ') . "\n";
}
echo "\n";

/* ---------- 3b. kø og log ---------- */
echo "4. KØ OG LOG\n", str_repeat('-', 66), "\n";
$koe = defined('KOE_DIR') ? KOE_DIR : '(KOE_DIR mangler)';
echo "Kømappe:          $koe\n";
if (defined('KOE_DIR')) {
    if (!is_dir(KOE_DIR)) @mkdir(KOE_DIR, 0770, true);
    $findes = is_dir(KOE_DIR);
    $skrivbar = $findes && is_writable(KOE_DIR);
    echo "  findes:         " . ($findes ? 'ja' : 'NEJ — kunne ikke oprettes') . "\n";
    echo "  skrivbar:       " . ($skrivbar ? 'ja' : 'NEJ — agenten kan hverken gemme kommentarer eller skrive log') . "\n";

    // Prøv rent faktisk at skrive. Rettigheder kan se rigtige ud og alligevel fejle.
    $proeve = KOE_DIR . '/.skriveproeve';
    $ok = @file_put_contents($proeve, 'x') !== false;
    echo "  skriveprøve:    " . ($ok ? 'lykkedes' : 'MISLYKKEDES') . "\n";
    if ($ok) @unlink($proeve);

    $log = KOE_DIR . '/agent.log';
    echo "  agent.log:      " . (is_file($log)
        ? number_format(filesize($log)) . ' bytes, sidst skrevet ' . date('j/n H:i:s', (int) filemtime($log))
        : 'findes ikke endnu') . "\n";
    $jobs = glob(KOE_DIR . '/*.json') ?: [];
    echo "  kommentarer:    " . count($jobs) . " i kø/afgjort\n";
    if (is_file($log)) {
        $linjer = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        echo "\n  Sidste 10 loglinjer:\n";
        foreach (array_slice($linjer, -10) as $l) echo "    $l\n";
    }
}
echo "\n";

/* ---------- 5. filerne i mappen ---------- */
echo "5. FILER I MAPPEN\n", str_repeat('-', 66), "\n";
foreach (['index.html','gennemgang.html','skygge.html','api.php','stil.css',
          'agentlib.php','webhook.php','agent.php','tjek-token.php','test-regler.php',
          'lib/jszip.min.js'] as $f) {
    $sti = __DIR__ . '/' . $f;
    printf("%-22s %s\n", $f, is_file($sti)
        ? number_format(filesize($sti)) . ' bytes, ' . date('j/n H:i', (int) filemtime($sti))
        : 'MANGLER');
}
echo "\n", str_repeat('=', 66), "\n";
echo "Husk at slette diagnose.php, når fejlsøgningen er slut.\n";
