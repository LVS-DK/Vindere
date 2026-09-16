<?php
/* ------------------------------------------------------------------
   Test af de hårde regler. Køres med:  php test-regler.php
   Tilføj gerne selv linjer, når I møder en kommentar i virkeligheden,
   som agenten placerede forkert.
   ------------------------------------------------------------------ */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/agentlib.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    $givet = (string) ($_GET['noegle'] ?? '');
    if (FB_VERIFY_TOKEN === '' || !hash_equals(FB_VERIFY_TOKEN, $givet)) {
        http_response_code(403);
        exit("Sæt ?noegle=<din FB_VERIFY_TOKEN fra config.php> efter adressen.\n");
    }
}

$proever = [
    // [tekst, forventet spor: akut | til_dig | null (= modellen afgør)]

    // --- skal fanges som akut ---
    ['I skal fjerne mit billede med det samme', 'akut'],
    ['Kan I fjerne billedet af mig?', 'akut'],
    ['Slet det der foto tak', 'akut'],
    ['Tag det ned', 'akut'],
    ['Jeg vil ikke være med på Facebook', 'akut'],
    ['Jeg har ikke sagt ja til det her', 'akut'],
    ['Der er ingen der har spurgt mig', 'akut'],
    ['Jeg trækker mit samtykke tilbage', 'akut'],
    ['Det er vist ikke helt efter GDPR', 'akut'],
    ['Så hører I fra min advokat', 'akut'],
    ['Det er ulovligt at lægge billeder op uden lov', 'akut'],
    ['Fjern mit navn fra opslaget', 'akut'],

    // --- skal fanges som følsomt ---
    ['Jeg har været indlagt og kunne ikke komme', 'til_dig'],
    ['Ærgerligt, min mand døde i sidste uge', 'til_dig'],
    ['Godt at se ham ædru igen', 'til_dig'],
    ['Jeg blev så ked af det da jeg ikke kunne deltage', 'til_dig'],
    ['Hun har været meget ensom i år', 'til_dig'],

    // --- må gerne gå videre til modellen ---
    ['Tillykke Kurt! Sikke en indsats', null],
    ['Godt gået allesammen', null],
    ['Hvornår er næste arrangement?', null],
    ['💪💪💪', null],
    ['Sikke et flot vejr I havde', null],
    ['Vi tilbyder billige lån klik her', null],
    ['Bente vandt fortjent, hun har trænet hele sommeren', null],
    ['Hvor foregik det henne?', null],
];

$fejl = 0;
foreach ($proever as [$tekst, $forventet]) {
    $r = haardRegel($tekst);
    $fik = $r === null ? null : $r['spor'];
    $ok = ($fik === $forventet);
    if (!$ok) $fejl++;
    printf("%s  %-8s (ventet %-8s)  %s\n",
        $ok ? ' ok ' : 'FEJL',
        $fik ?? 'modellen',
        $forventet ?? 'modellen',
        $tekst);
}

echo "\n", ($fejl === 0 ? "Alle " . count($proever) . " prøver er grønne.\n" : "$fejl af " . count($proever) . " fejlede.\n");
exit($fejl === 0 ? 0 : 1);
