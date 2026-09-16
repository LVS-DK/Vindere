# Kommentar-agenten

Agenten holder øje med kommentarsporet under jeres opslag og placerer hver
kommentar i ét af fire spor: svar, like, til dig, eller ignorér. Den starter i
**skyggetilstand**, hvor den vurderer alt, men aldrig rører Facebook. Du læser
dens forslag på `skygge.html` og siger, om den ramte rigtigt. Først når det tal
ser fornuftigt ud, giver du den lov til at handle.

Filerne kører på samme webhotel som resten. Der er ingen Make, ingen kø-service
og ingen database — kun to nye PHP-filer og et cronjob.

---

## Hvad der skal på plads

### 1. En app hos Meta — og det rigtige brugsscenarie

Gå til developers.facebook.com og opret en app. Du skal være administrator af
både appen og siden.

**Her er den fælde, alle falder i.** Tilladelserne hænger ikke på appen som
sådan — de hænger på det *brugsscenarie* (use case), du vælger undervejs. Vælger
du et scenarie om beskeder, er `pages_messaging` det eneste, der dukker op
senere i listen over tilladelser, og så er `pages_read_engagement` og
`pages_manage_engagement` slet ikke til at finde.

Du skal bruge scenariet **"Manage everything on your Page"**. Vælg det, når
appen oprettes.

Har du allerede oprettet appen med noget andet, kan du tilføje scenariet
bagefter: gå ind i **Dashboard** i menuen til venstre, find "Manage everything
on your Page", og klik **Customize** ud for den. Der ligger listen over
tilladelser, og du trykker **Add** ud for `pages_manage_metadata`,
`pages_read_engagement`, `pages_manage_engagement` og
`pages_read_user_content`. Først derefter dukker de op i Graph API Explorer.

Kan scenariet ikke tilføjes til den app, du har, så opret hellere en ny med det
rigtige scenarie fra start. Du mister ingenting — der er ikke andet i appen
endnu end det, du selv har tastet ind.

Under **Indstillinger → Grundlæggende** finder du appens hemmelighed. Den skal
ind i `config.php` som `FB_APP_SECRET`. Uden den afviser `webhook.php` alle kald
— og det skal den, for ellers kan hvem som helst fodre agenten med opdigtede
kommentarer.

### 2. Tilladelser og token

Der er fire tilladelser i spil, og de gør hver sin ting:

`pages_manage_metadata` er den, der overhovedet giver appen lov til at abonnere
på sidens webhooks. Uden den kommer der aldrig et eneste kald til
`webhook.php`, uanset hvor rigtigt alt andet er sat op.

`pages_read_engagement` lader agenten læse sidens egne opslag. Den bruges til at
hente opslagsteksten, så modellen ved, hvad tråden handler om, når den vurderer
en kommentar.

`pages_manage_engagement` er den, der giver lov til at like og svare som siden.
Uden den kan agenten kun se på.

`pages_read_user_content` dækker det, brugerne selv skriver — kommentarer,
opslag på væggen, anmeldelser. Tag den med. Vores agent læser som udgangspunkt
kommentarteksten direkte ud af det, Meta sender i webhook-kaldet, så i teorien
kan den klare sig uden. Men Meta udelader felter i webhook-kald, når
tilladelserne ikke er der, og en tom kommentartekst er den slags fejl, der er
ærgerlig at lede efter bagefter. Den koster ikke noget ekstra at tage med, når
du alligevel står i listen.

Skal du på et tidspunkt skære ned for at komme lettere gennem App Review, er
`pages_read_user_content` den, du prøver at undvære først — og skyggetilstanden
fortæller dig med det samme, om det gik: står kommentarerne i
`data/kommentarer/` med tekst, virker det.

Selve tokenet er den del, der driller folk, fordi det token man får udleveret
med det samme kun holder et par timer. Du skal igennem tre skridt for at ende
med ét, der ikke udløber. Det tager ti minutter, og du skal kun gøre det én
gang.

**Skridt 1 — et token til dig selv**

Åbn Graph API Explorer på developers.facebook.com/tools/explorer. Vælg din app
i menuen øverst til højre. Under "User or Page" vælger du **User Token**, og i
listen over tilladelser sætter du flueben ved `pages_show_list`,
`pages_manage_metadata`, `pages_read_engagement`, `pages_manage_engagement` og
`pages_read_user_content`. Tryk **Generate Access Token** og godkend i vinduet,
der popper op.

Står `pages_*`-tilladelserne slet ikke i listen, er du ikke nået forbi punkt 1:
appen mangler brugsscenariet "Manage everything on your Page". Gå tilbage og
tilføj det først — der er intet at hente her, før det er på plads.

Det token, der nu står i feltet, holder kun et par timer. Kopiér det alligevel
— det er råstoffet til de næste to skridt.

**Skridt 2 — byt det til et, der holder to måneder**

Forlad Graph API Explorer her. Explorer sætter selv sit eget `access_token` på
kaldet og spænder ben for netop denne forespørgsel — derfor sker der ingenting,
når man trykker Submit.

Brug i stedet en helt almindelig browserfane. Sæt denne adresse sammen med dine
egne værdier, og indsæt hele molevitten i adresselinjen:

    https://graph.facebook.com/v25.0/oauth/access_token?grant_type=fb_exchange_token&client_id=DIT_APP_ID&client_secret=DIN_APP_HEMMELIGHED&fb_exchange_token=TOKENET_FRA_SKRIDT_1

Brug det versionsnummer, din egen app-oversigt viser — står der v26.0 hos dig,
så skriv v26.0. Det samme nummer skal stå i `FB_API` i config.php.

App-ID og hemmelighed finder du under **Indstillinger → Grundlæggende** i din
app. App-ID er det lange tal øverst — ikke appens navn. Hemmeligheden er skjult,
indtil du trykker "Vis".

Browseren viser svaret som JSON. Deri står et nyt `access_token`. Det er nu dit
brugertoken, og det holder omkring 60 dage.

Får du `"Invalid Client ID"` (fejlkode 101), er `client_id` ikke et rigtigt
app-ID. Det sker, hvis man kommer til at indsætte appens navn, indsætter
hemmeligheden begge steder, eller får et mellemrum eller et linjeskift med, når
man kopierer. Tjek at der kun står cifre.

**Skridt 3 — hent sidens eget token**

Samme fremgangsmåde, ny adresse i browseren:

    https://graph.facebook.com/v25.0/me/accounts?access_token=TOKENET_FRA_SKRIDT_2

Svaret er en liste over de sider, du administrerer. Find jeres side i listen.
Der står både et `id` og et `access_token`.

`id` skal ind i `FB_PAGE_ID` i config.php. Det bruges til at genkende sidens
egne kommentarer, så agenten ikke ender i en samtale med sig selv.

`access_token` skal ind i `FB_PAGE_TOKEN`. Og det er pointen ved hele øvelsen:
et sidetoken hentet med et langtidsholdbart brugertoken **udløber ikke**. Det
bliver først ugyldigt, hvis du skifter adgangskode på Facebook, fjerner appens
adgang, eller mister din administratorrolle på siden.

**Tjek at det virker**

Der er to måder at køre tjekket på, alt efter hvad dit webhotel tilbyder.

Har du adgang til en kommandolinje (SSH), står du i mappen og skriver:

    php tjek-token.php

Har du ikke det — og det har man sjældent på et almindeligt webhotel — så åbn
den i browseren i stedet, med din egen `FB_VERIFY_TOKEN` bagefter:

    https://ditdomæne.dk/vindere/tjek-token.php?noegle=DIN_VERIFY_TOKEN

Nøglen skal med, for ellers kunne enhver forbipasserende se, hvad der er sat op
på din server. Samme fremgangsmåde virker for `test-regler.php`.

Den fortæller, om tokenet hører til den rigtige side, om det udløber, om alle
tilladelserne er med, og om den kan læse sidens opslag. Står der, at tokenet
udløber en bestemt dato, er du kommet til at bruge tokenet fra skridt 1 eller 2
— gå tilbage og tag skridt 3 igen.

**Hvis Meta spærrer**

Så længe du selv er administrator af både app og side, kan det normalt køre på
Standard Access, uden godkendelse. Afviser Meta det, skal appen igennem App
Review med en skærmoptagelse af hele flowet plus virksomhedsverificering i
Business Manager. Det er den eneste del af projektet, der kan tage uger, og det
er ikke noget, vi kan skynde på.

### 3. Webhooken

Under **Webhooks** i appen tilføjer du en Page-abonnering:

- Callback-URL: `https://ditdomæne.dk/vindere/webhook.php`
- Verify token: den samme streng, du har skrevet i `FB_VERIFY_TOKEN`
- Felt: `feed`

Meta kalder adressen med det samme for at verificere den. Går det galt, så tjek
at `FB_VERIFY_TOKEN` er ens begge steder, og at siden svarer over HTTPS.

### 4. Gør appen Live

**Det her er den, der standser alle.** Så længe appen står i Development mode,
sender Meta ingen rigtige webhooks overhovedet — heller ikke for dig selv, selv
om du er administrator af både app og side. Kun Test-knappen i konsollen virker.
Din opsætning kan altså være helt korrekt og stadig aldrig modtage en eneste
kommentar.

Øverst i app-konsollen er der en kontakt, der står på **Development**. Den skal
over på **Live**.

Meta kræver typisk to ting, før den lader sig skifte: at appen har en
**privatlivspolitik** med en offentlig adresse, og at der er valgt en kategori.
Har I en privatlivspolitik på jeres hjemmeside, kan du bare pege på den.

Bemærk forskellen på at gøre appen Live og at gå gennem App Review. Live er en
kontakt, du selv kan slå. App Review er en sagsbehandling, og den skal du kun
igennem, hvis Meta afviser den adgang, du bruger — se afsnittet i punkt 2.

Test-knappen på webhook-siden virker i begge tilstande, og den er stadig den
hurtigste måde at afgøre, om rørføringen mellem Meta og din server er i orden.
Kommer der en `MODTAGET`-linje i loggen, når du trykker på den, mangler du kun
at gøre appen Live.

### 5. Nøgle til Claude

Opret en API-nøgle på console.anthropic.com og læg den i `CLAUDE_API_KEY`.
Modellen er sat til den hurtigste og billigste, som er rigelig til den her
opgave. Nogle hundrede kommentarer om måneden koster småpenge.

Er nøglen tom, kører agenten stadig — den sender bare alt videre til dig uden
vurdering. Det er en brugbar tilstand at starte i, hvis du vil se mængden af
kommentarer, før du beslutter dig.

### 6. Cronjobbet

Agenten arbejder ikke af sig selv. Den skal kaldes hvert minut:

    * * * * * /usr/bin/php /sti/til/vindere/agent.php >/dev/null 2>&1

Kan dit webhotel kun kalde en URL i stedet for en fil, virker det også:

    https://ditdomæne.dk/vindere/agent.php?noegle=DIN_FB_VERIFY_TOKEN

Kører cron kun hvert femte minut, gør det ikke noget. Svarene er alligevel
forsinket 4 til 14 minutter med vilje.

**Har dit webhotel slet ikke cron?**

Kig først efter den under et andet navn. Danske webhoteller kalder det ofte
"Planlagte opgaver", "Scheduled tasks" eller "Cron jobs", og det gemmer sig
gerne under et punkt som Avanceret eller Værktøjer. Er det ikke i
kontrolpanelet, er det nogle gange noget, supporten kan slå til.

Findes det virkelig ikke, så lad en gratis tjeneste udefra kalde adressen for
dig. cron-job.org er den nemmeste; du opretter en konto, indsætter
adressen, og sætter intervallet til hvert minut eller hvert femte:

    https://ditdomæne.dk/vindere/agent.php?noegle=DIN_CRON_NOEGLE

Sæt i det tilfælde en selvstændig `CRON_NOEGLE` i config.php frem for at lade
den stå tom. Så ligger webhookens verify token ikke hos en tredjepart, og du
kan skifte den ene uden at røre den anden.

Og skulle begge dele glippe, står systemet ikke helt stille: hver gang
`webhook.php` modtager en ny kommentar, tager den samtidig et par modne
kommentarer fra køen. Det gør agenten brugbar helt uden cron, så længe der
kommer kommentarer i tråden. Ulempen er, at den allersidste kommentar i en
tråd, der falder til ro, bliver liggende ubehandlet — der er ikke noget til at
vække den. Derfor er en rigtig cron stadig det, du skal stræbe efter.

---

## Hvad agenten reagerer på

Agenten reagerer på **nye kommentarer**, ikke på nye opslag. En kommentar
skrevet i dag på et opslag fra i forgårs bliver altså fanget.

Den kan derimod ikke se noget, der skete, før appen blev gjort Live. Webhooks
har ikke tilbagevirkende kraft, så kommentarer fra før det tidspunkt findes
ikke for agenten.

`OVERVAAGES_TIMER` i config.php sætter grænsen for, hvor gammelt **opslaget**
må være. Står den på 48, lader agenten kommentarer på opslag ældre end to døgn
ligge og noterer dem som "ignorer" med begrundelsen. Alderen slås op hos
Facebook, første gang en kommentar på det opslag skal vurderes.

## De tre tilstande

I `config.php` står `TILSTAND`. Den kan være:

`skygge` — agenten vurderer alt og skriver udkast, men rører aldrig Facebook.
Det er her, du starter, og det er her, du bliver et par uger.

`likes` — den må like, men ikke svare. Et like kan ikke rammes forkert, så det
er et lille skridt at tage. Svarene ligger stadig som udkast, du kan læse.

`fuld` — den må både like og svare.

Skift én ad gangen, og kun når tallet på `skygge.html` siger, at du har været
enig med den mange gange i træk.

---

## Det agenten aldrig gør

Nogle kommentarer bliver aldrig besvaret automatisk, uanset hvad modellen mener.
De fanges af faste mønstre i `agentlib.php`, før modellen overhovedet spørges:

Alt der handler om at få fjernet et billede eller et navn, om manglende
samtykke, om GDPR, eller som nævner advokat eller politi, bliver markeret
**akut** og lagt på dit bord med det samme. Agenten skriver ikke et ord.

Alt der rører ved en persons situation — sygdom, dødsfald, misbrug, ensomhed,
psykiatri — går til dig som **til dig**. Det er ikke noget, en maskine skal
svare varmt og hurtigt på.

Derudover: den svarer aldrig på sidens egne kommentarer, aldrig to gange på
samme kommentar, og højst seks gange i samme tråd. Er modellen under 70 procent
sikker på et svar, bliver det til dig i stedet.

Du kan afprøve mønstrene uden at røre Facebook — enten fra en kommandolinje:

    php test-regler.php

eller i browseren:

    https://ditdomæne.dk/vindere/test-regler.php?noegle=DIN_VERIFY_TOKEN

Møder I i virkeligheden en kommentar, agenten placerede forkert, så skriv den
ind i den fil med det spor, den burde have fået. Så kan vi rette mønsteret og se
med det samme, at intet andet gik i stykker.

---

## To ting du skal vide

EU's AI-forordning artikel 50 trådte i kraft 2. august 2026 og kræver, at folk
får at vide, når de er i kontakt med et AI-system. Der er en undtagelse, når det
er åbenlyst, men den bør man ikke læne sig for hårdt op ad — særligt ikke over
for mennesker, der skriver i god tro. En linje i sidens "Om"-tekst er nok.

Agenten gemmer kommentartekster med navne på. De slettes automatisk efter det
antal dage, der står som `OPBEVARING_DAGE` i `config.php`, ligesom billederne.

---

## Manglende indstillinger

Siger `tjek-token.php`, at din `config.php` mangler indstillinger, er den fra
før kommentar-agenten kom til. Systemet kører videre på standardværdier, så
intet går i stykker — men agenten virker først, når linjerne står i din egen fil
med dine egne værdier.

Du behøver ikke overskrive din `config.php` og taste alt ind igen. Åbn den, og
sæt denne blok ind nederst, efter den sidste linje der er der i forvejen:

```php
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

// Graph API-version. v25.0 er aktuel; brug den, din app-oversigt viser.
define('FB_API', 'https://graph.facebook.com/v25.0');

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

define('KOE_DIR', DATA_DIR . '/kommentarer');
```

Ret derefter `FB_APP_SECRET`, `FB_VERIFY_TOKEN`, `FB_PAGE_ID`, `FB_PAGE_TOKEN`
og `CLAUDE_API_KEY` til dine egne værdier — og `FB_API` til den Graph-version,
din app bruger.

Står nogle af linjerne allerede i din fil, så tag dem ikke med igen. PHP brokker
sig over at få defineret det samme to gange.

---

## Hvis noget driller

**Webhooken bliver ikke verificeret** — `FB_VERIFY_TOKEN` skal være ens i
`config.php` og hos Meta, og siden skal svare over HTTPS.

**Du får 403, når du prøver at åbne agent.log i browseren** — det er meningen.
Datamappen er lukket af udefra, fordi der ligger navne og billeder i den. Loggen
læser du nederst på `skygge.html`, hvor der er en Log-sektion med en Vis-knap.
Eller du henter filen med FTP.

**Der kommer ingenting i køen** — kig i `data/kommentarer/agent.log`. Står der
"AFVIST kald uden gyldig signatur", passer `FB_APP_SECRET` ikke.

**Tjekket stopper midt i, eller siden bliver bare tom** — så mangler serveren
cURL. Systemet klarer sig nu uden (det falder tilbage på almindelige streams),
men er hverken cURL eller `allow_url_fopen` slået til, kan serveren slet ikke
kalde ud på nettet, og så siger tjekket det med rene ord i første linje. Er det
tilfældet, skal dit webhotel slå en af delene til.

**Alt havner som "til dig"** — så mangler `CLAUDE_API_KEY`, eller også svarer
API'et ikke. Begrundelsen på hvert kort fortæller hvad der gik galt.

**Den svarer ikke, selvom tilstanden er `fuld`** — tjek `handling_fejl` på
kortet. Er det en 403 fra Meta, mangler tokenet
`pages_manage_engagement`.
