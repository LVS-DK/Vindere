<?php
/* ------------------------------------------------------------------
   Kommentar-agenten.

   Kan kaldes på tre måder:

   1. Som cronjob på serveren:
        * * * * * /usr/bin/php /sti/til/vindere/agent.php >/dev/null 2>&1

   2. Som adresse, hvis webhotellet kun kan kalde en URL — eller hvis du
      bruger en gratis cron-tjeneste udefra:
        https://ditdomæne.dk/vindere/agent.php?noegle=DIN_CRON_NOEGLE

   3. Af sig selv, hver gang webhook.php modtager en ny kommentar. Det gør
      systemet brugbart helt uden cron, så længe der kommer kommentarer.

   Den tager de kommentarer i køen, hvis forsinkelse er udløbet, afgør hvad
   der skal ske, og handler — hvis tilstanden tillader det.
   ------------------------------------------------------------------ */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/agentlib.php';

$fraKommandolinje = (PHP_SAPI === 'cli');

if (!$fraKommandolinje) {
    // Nøglen må gerne være en anden end webhookens, for denne adresse
    // ender hos en cron-tjeneste, mens webhookens kun kendes af Meta.
    $forventet = CRON_NOEGLE !== '' ? CRON_NOEGLE : FB_VERIFY_TOKEN;

    // Nøglen må komme tre steder fra. Nogle cron-tjenester roder med
    // forespørgselsstrengen, og så er en header eller en sti-variant
    // en nemmere vej end at fejlsøge deres URL-behandling.
    $givet = (string) ($_GET['noegle'] ?? ($_SERVER['HTTP_X_NOEGLE'] ?? ''));
    $givet = trim(urldecode($givet));

    if ($forventet === '' || !hash_equals(trim($forventet), $givet)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Forkert eller manglende nøgle.\n"
           . "Kald adressen med ?noegle=... eller send den som headeren X-Noegle.\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$linjer = behandlKoe(10);

foreach ($linjer as $l) echo $l, "\n";
echo 'Behandlet: ' . count($linjer) . "\n";
