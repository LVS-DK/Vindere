<?php
/* ------------------------------------------------------------------
   Vindersedlen — indstillinger
   Ret de to koder herunder FØR du lægger filerne op.
   ------------------------------------------------------------------ */

// Koden A taster ind én gang på sin telefon.
define('KODE_A', 'skift-mig-a');

// Din egen kode til gennemgangssiden. Gør den lang og svær at gætte.
define('KODE_BRIAN', 'skift-mig-brian');

// Hvor mange dage indsendelser bliver liggende, før de slettes automatisk.
define('OPBEVARING_DAGE', 14);

// Mappen hvor billeder og navne gemmes. Ligger som standard ved siden af
// api.php og er lukket af med .htaccess. Ligger dit webhotel sådan, at du
// kan pege uden for public_html, er det endnu bedre — f.eks.:
// define('DATA_DIR', dirname(__DIR__) . '/vindersedlen-data');
define('DATA_DIR', __DIR__ . '/data');

// Største enkeltbillede, der tages imod (bytes).
define('MAX_BILLEDE_BYTES', 8 * 1024 * 1024);

/* ==================================================================
   Kommentar-agenten
   Alt herunder skal først udfyldes, når agenten skal i luften.
   Systemet kører fint uden.
   ================================================================== */

// Tilstand: 'skygge' = agenten vurderer og foreslår, men rører aldrig Facebook.
//           'likes'  = den må like, men ikke svare.
//           'fuld'   = den må like og svare.
// Start på 'skygge'. Skift først, når du har læst dens forslag i en uge.
define('TILSTAND', 'skygge');

// Fra din Meta-app: Indstillinger → Grundlæggende.
define('FB_APP_SECRET', '');

// En streng du selv finder på. Den samme skal skrives i Metas webhook-opsætning.
define('FB_VERIFY_TOKEN', 'skift-mig-verify');

// Sidens ID og et langtidsholdbart sidetoken.
define('FB_PAGE_ID', '');
define('FB_PAGE_TOKEN', '');

// Graph API-version. v26.0 er aktuel; brug den, din app-oversigt viser.
define('FB_API', 'https://graph.facebook.com/v26.0');

// API-nøgle fra console.anthropic.com.
define('CLAUDE_API_KEY', '');
define('CLAUDE_API_URL', 'https://api.anthropic.com/v1/messages');
define('CLAUDE_MODEL', 'claude-haiku-4-5-20251001');

// Svar lægges tilfældigt mellem disse to, så tråden ikke ser maskinel ud.
define('FORSINKELSE_MIN', 4);
define('FORSINKELSE_MAKS', 14);

// Højst så mange svar fra agenten i samme tråd, uanset hvor mange der skriver.
define('MAKS_SVAR_PR_TRAAD', 6);

// Hvor længe efter et opslag agenten overhovedet reagerer (timer).
define('OVERVAAGES_TIMER', 48);

// Nøgle til at kalde agent.php som adresse — brug den, hvis dit webhotel
// ikke har cron, og du i stedet lader en tjeneste udefra kalde den.
// Lad den stå tom for at genbruge FB_VERIFY_TOKEN, men sæt hellere en
// selvstændig: så kommer webhookens token aldrig ud af huset.
define('CRON_NOEGLE', '');

define('KOE_DIR', DATA_DIR . '/kommentarer');
