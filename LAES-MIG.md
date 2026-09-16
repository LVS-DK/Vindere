# Vindersedlen

To sider og en lille PHP-fil. A registrerer dagens vindere på sin telefon,
du gennemgår dem, beskærer billederne og henter en pakke, der er klar til
Facebook. Ingen database, ingen konti hos andre, ingen API-nøgler.

---

## Sådan kommer du i gang

**1. Ret de to koder.**
Kopiér `config.eksempel.php` til `config.php` (den ligger ikke i repoet, fordi
den indeholder dine koder og nøgler). Åbn `config.php` og udskift `skift-mig-a` og `skift-mig-brian` med dine egne.
A's kode skal hun kunne taste på en telefon — tre almindelige ord med
bindestreger imellem er bedre end noget kort og kryptisk. Din egen må gerne
være lang; du taster den kun én gang pr. enhed.

**2. Læg mappen op.**
Send hele indholdet op i en undermappe på dit webhotel, f.eks.
`https://ditdomæne.dk/vindersedlen/`. Filerne skal ligge sådan her:

    vindersedlen/
      index.html          ← A's side
      gennemgang.html     ← din side
      api.php
      config.php
      stil.css
      .htaccess
      lib/jszip.min.js
      data/               ← oprettes automatisk, hvis den mangler
        .htaccess

**3. Tjek at `data` kan skrives til.**
På de fleste webhoteller virker det uden videre. Gør det ikke, sætter du
rettighederne på mappen til 755 (eller 775) i din FTP-klient.

**4. Prøv det.**
Åbn `gennemgang.html` i din egen browser og log ind. Åbn `index.html` på
A's telefon, log ind med hendes kode, og gem siden på hjemmeskærmen —
så ligner den en app, og hun skal aldrig finde adressen frem igen.

---

## Krav til webhotellet

PHP 7.4 eller nyere, og HTTPS. Det er det. Ingen database, ingen
udvidelser ud over det, der altid er med. Der er ikke brug for
`ZipArchive`, `gd` eller lignende — al billedbehandling sker i browseren.

---

## Sådan bruges det

**A, efter arrangementet.** Fotograferer sedlen (den følger med, så du kan
tjekke navnene mod hendes egen skrift). Taster vinderne ind i rækkefølge og
vælger et billede til hver. Skriver én linje om stemningen. Sætter flueben i,
at alle på billederne har sagt ja. Trykker send.

**Dig, bagefter.** Åbner `gennemgang.html`, klikker dagens indsendelse frem,
retter stavefejl, beskærer hvert billede ved at trække i det, og trykker
"Hent pakke og godkend". Du får en zip med billederne som 1080 × 1080 px
JPEG, nummereret i galleriets rækkefølge, plus `opslag.json` med navne,
væresteder, fællesskabspokalen og stemningslinjen.

Billeder, der ikke er beskåret, bliver skåret på midten med et let løft
opad. Siden advarer dig, inden den gør det.

---

## Om sikkerhed og data

Datamappen er lukket af med `.htaccess`, og billeder kan kun hentes gennem
`api.php`, når man er logget ind som dig. A kan sende ind, men ikke se
listen over indsendelser.

Indsendelser slettes automatisk efter det antal dage, der står i
`config.php` — 14 som standard. Oprydningen kører, hver gang A sender noget
nyt ind. Du kan også slette en indsendelse manuelt nederst på
gennemgangssiden.

Ligger dit webhotel sådan, at du kan pege uden for `public_html`, er det
bedre at flytte datamappen derud. Der står en færdig linje til det i
`config.php`.

Siden henter skrifttyper fra Google Fonts. Vil du undgå det helt, kan
`<link>`-linjen i toppen af de to HTML-filer bare slettes — så bruger den
telefonens egne skrifter i stedet.

---

## Hvis noget driller

**"Datamappen kan ikke skrives til"** — ret rettighederne på `data` til 755.

**A's billeder kommer ikke med** — nogle Android-telefoner sender HEIC.
Siden fortæller det, hvis et billede ikke kan åbnes; hun vælger det så fra
kamerarullen i stedet for at fotografere direkte.

**"Filen lib/jszip.min.js mangler"** — `lib`-mappen er ikke kommet med op.

**Hun bliver logget ud hele tiden** — telefonen rydder sessionen. Koden
ligger gemt lokalt, så siden logger selv ind igen, næste gang hun åbner
den.

---

## Kommandoer uden kommandolinje

Der følger to små tjek-værktøjer med til kommentar-agenten. Har du ikke SSH på
webhotellet, kan begge åbnes i browseren med din `FB_VERIFY_TOKEN` bagefter:

    https://ditdomæne.dk/vindersedlen/tjek-token.php?noegle=DIN_VERIFY_TOKEN
    https://ditdomæne.dk/vindersedlen/test-regler.php?noegle=DIN_VERIFY_TOKEN

Uden nøglen svarer de ikke.
