# cliproxy – Claude ir Codex paskyrų hub'as

Visos Claude ir Codex prenumeratos prijungiamos **viename** visada įjungtame namų kompiuteryje (šiame,
`cachyos-x8664`, Telia – gyvenamasis IP). Jame veikia [CLIProxyAPI](https://github.com/router-for-me/CLIProxyAPI):
jis palaiko paskyrų raktus gyvus ir duoda vieną API adresą, kuriuo naudojasi Claude Code ir Codex. Šalia – savas
valdymo skydelis (Symfony): paskyros ir jų 5 val. / savaitės limitai, naudojimas, cache, session affinity,
soonest-reset-first prioritetai.

Pagal Theo gidą: <https://gist.github.com/t3dotgg/59a521d2ee8d70aaf90e280c175072af>
(vaizdo įrašas <https://www.youtube.com/watch?v=D8PikZ1KhUo>).

**Dvi taisyklės** (gist'o): savo prenumeratomis neaptarnauk kitų žmonių srauto; Claude prenumeratą per hub'ą naudok
**tik per Claude Code**. Ban'o rizika yra – ji priimta.

## Architektūra

DDE monorepo, kaip `zarskis.lt`: `app/` – setup aplinka (generic profilis, PHP 8.5, MySQL 8.4), `app/public/` –
Symfony 8.1 skydelis.

| Konteineris | Kas |
| --- | --- |
| `cliproxy` | `eceasy/cli-proxy-api` (versija – `CLIPROXY_VERSION`, `app/env/common.env`). Kompiuteryje – tik `127.0.0.1:${CLIPROXY_PORT}` (prod 8317, local 18317). Būsena – `data/<env>/cliproxy/` (`config.yaml`, `auth/` – paskyrų raktai, `logs/`). |
| `php-fpm`, `webserver`, `nginx-proxy` | Skydelis. Su CLIProxyAPI kalbasi projekto tinkle `http://cliproxy:8317/v8/management`. |
| `cron` | `app:collect` kas minutę (`app/config/cron/crontab`): žr. „Kolektorius“. |
| `database` | Naudojimo istorija, limitų nuotraukos, skydelio nustatymai. |

- **Management raktas** (`CLIPROXY_MANAGEMENT_KEY`) – tik konteinerio aplinkoje (`MANAGEMENT_PASSWORD`), į failą
  nepatenka. `config.yaml` `secret-key` tuščias, `allow-remote: true` (skydelis jungiasi iš kito konteinerio; port'as
  publikuojamas tik `127.0.0.1`).
- **`config.yaml`** sukuriamas tik pirmą kartą iš `app/config/cliproxy/config.template.yaml` (raktas –
  `CLIPROXY_API_KEY`). Toliau jį keičia skydelis (Nustatymai) ir CLIProxyAPI pats; `entrypoint.sh` esamo neliečia.
  Šablone: session affinity įjungtas (1 h, `rebind-on: quota`), usage statistika su 1 h eile,
  `model-level-cooling`, `disable-control-panel: true`.
- **Konfigūracijos bazė** (`src/CliProxy/ConfigBaseline.php`): kadangi šablonas esamo failo nebepasiekia, kolektorius
  kiekvieną paleidimą per management API užtikrina duomenų politiką (`usage-statistics-enabled`, eilės 1 h,
  `logs-max-total-size-mb: 0`, `error-logs-max-files: 0`), o vieną kartą – `session-affinity-rebind-on: quota` ir
  `upstream.claude.model-level-cooling: true` (toliau – tavo pasirinkimas).
- **`CLIPROXY_CONFIG_REV`** (`env/common.env`) – `app/config/cliproxy/*` maiša; ji yra konteinerio aplinkoje, todėl
  pakeitus `entrypoint.sh` ar šabloną deploy'us konteinerį perkuria. CI (`checks.yml`, lint) atmeta pasenusią reikšmę;
  komanda jai apskaičiuoti – `env/common.env` komentare.
- **Health** (`/_health`) tikrina DB ir CLIProxyAPI; kuriam nors neveikiant – 503, todėl deploy'us grąžinamas.
- Prieiga: **tik šis kompiuteris** (`127.0.0.1`). Iš kito kompiuterio – SSH tunelis
  `ssh -N -L 8317:127.0.0.1:8317 cachyos-x8664`. Tailscale galima pridėti vėliau (`tailscale serve --bg
  --https=8317 http://127.0.0.1:8317`).

## Skydelis

Tamsi tema (šviesi – mygtukas viršuje), šoninė navigacija; el. paštai paslėpti, kol nepaspaudi „akies“ (patogu
dalintis ekranu). Išdėstymas – pagal Theo gist'o kvotų puslapį.

| Puslapis | Kas |
| --- | --- |
| Kvotos | Kiekvieno tiekėjo paskyros kaip vienas pool'as (kiekviena paskyra – 100 %): likutis „X % iš N×100 %“, grafikas „užfiksuota“ (iš limitų istorijos) ir „prognozė“ (punktyras) per dvi savaites su atsinaujinimų šuoliais (+X %), „Dabar“ linija; „Pool ištuštės“, „Pirma ištuštės“, „Kitas atsinaujinimas“, „Burn“ per 1/3/6 val. Rodiniai: likutis, sąnaudos (%/val.), paskyros atskirai; langai 7 d., 5 val., 7 d. Fable; savaitės perjungimas. |
| Apžvalga | Paskyros su 5 val., 7 d. ir 7 d. Fable limitais (kiek panaudota, po kiek atsinaujina), 24 val. užklausos, tokenai, cache skaitymai/rašymai, session affinity, gyvas užklausų srautas (kas 5 s). |
| Paskyros | Prijungti Claude / Codex paskyrą, įjungti/išjungti, prioritetas, svoris, Codex WebSocket'ai, pastaba, rakto atnaujinimas, patikra, pašalinimas. |
| Naudojimas | 24 val. – visas laikas: per dieną, pagal paskyrą ir modelį; cache santykis; sesijos, peršokusios į kitą paskyrą. |
| Nustatymai | Strategija, session affinity, pakartojimai; soonest-reset-first; klientų raktai; visas `config.yaml`. |
| Žurnalas | Kiekviena užklausa: įvestis (žmogaus prompt'as ar įrankių rezultatai), mąstymas, atsakymas, įrankių kvietimai su rezultatu (pavyko / klaida), tokenai, trukmė, HTTP būsena. Filtrai, paieška, žalio log'o atsisiuntimas, JSONL eksportas. |
| Sesijos | Sesijų sąrašas ir kiekvienos laiko juosta: prompt'ai, mąstymas, atsakymai, įrankiai. |
| Analizė | Įrankių klaidų dalis, kaip baigėsi atsakymai, užklausų skaičius vienam prompt'ui, mąstymo dalis, naujausios įrankių klaidos. |
| Prijungimas | Paruošti `~/.claude/settings.json` ir `~/.codex/config.toml` fragmentai su hub'o adresu ir raktu. |

Prisijungimas – vartotojas `admin`, slaptažodis `ADMIN_PASSWORD`.

### Paskyros prijungimas

Paskyros → „+ Claude paskyra“ (ar Codex) → „Atidaryti“: prisijungi **savo naršyklėje**. Pabaigoje naršyklė atsidaro
`http://localhost:54545/callback?…` (Codex – `localhost:1455/auth/callback?…`) ir **neužsikrauna – taip ir turi
būti**. Visą adresą įklijuok į laukelį ir „Prijungti“. CLIProxyAPI iškeičia kodą į raktą ir įrašo paskyrą į
`auth/`. SSH tunelio ir callback port'ų nereikia (CLIProxyAPI management OAuth be `is_webui`).

Kiekvienai paskyrai – atskiras prisijungimas (naršyklėje atsijunk nuo ankstesnės arba naudok privatų langą).

Paskyrai, kuriai **reikia prisijungti iš naujo** (`token_invalidated`, `requires_sign_in`) – „Prisijungti iš naujo“:
CLIProxyAPI tikslinis prisijungimas (`/oauth/auth-url?…&name=<failas>`) atnaujina tą patį failą, todėl lieka
prioritetas, pastaba ir istorija. „Atšaukti pauzę“ nutraukia CLIProxyAPI cooldown'ą po 429 (tiekėjo limito ne).

### Limitai

CLIProxyAPI pats Claude/Codex limitų nerenka. Kolektorius kas `QUOTA_REFRESH_MINUTES` (numatyta 5 min., Nustatymai)
per CLIProxyAPI `/requests/api-call` su paskyros raktu klausia tiekėjo:

- Claude – `https://api.anthropic.com/api/oauth/usage` (`five_hour`, `seven_day`, `iguana_necktie` = 7 d. Fable);
- Codex – `https://chatgpt.com/backend-api/wham/usage` (pirminis ir antrinis langas, sukaupti atstatymai).

Užklausų antraštėse jau yra `x-…` ir `anthropic-ratelimit-unified-*` reikšmės: usage įrašo `response_headers` iš
kiekvieno atsakymo duoda **pasyvią** nuotrauką (ne dažniau nei kartą per minutę paskyrai, be papildomo kvietimo
tiekėjui; Fable langas perimamas iš paskutinės pilnos). Taip prioritetai atnaujinami vos keičiantis limitams, o
aktyvus skaitymas lieka kas 5 min. su naujausio tikro kliento User-Agent. Nepavykus aktyviam skaitymui – paskutinio
atsakymo antraštės su jų pačių laiku. Langas, kurio `resets_at` jau praėjo, laikomas tuščiu. Nuotraukos laikomos
visam laikui.

### Kvotų prognozė

`src/Quota/PoolForecast.php`. Burn – kiek per pasirinktą langą (1/3/6 val.) paaugo visų paskyrų panaudota dalis
(atsinaujinimų kritimai neskaičiuojami), %/val. Prognozė tą tempą leidžia iš paskyros, kuri atsinaujina anksčiausiai
(kaip soonest-reset-first), o atsinaujinimo metu paskyrai grąžina visą langą ir nustato kitą atsinaujinimą po
lango ilgio (7 d. arba 5 val.). „Pool ištuštės“ – kada visų likutis pasiektų 0, „Pirma ištuštės“ – kuri paskyra
pirmoji.

### Srauto žurnalas

CLIProxyAPI `observability.logs.request-log: true` kiekvienai užklausai rašo failą
`data/<env>/cliproxy-traffic/logs/` (užklausa, upstream užklausos ir atsakymai, atsakymas klientui; slapti
header'iai užmaskuoti). Kolektorius (`src/Traffic/`) kas 5 s paima baigtus failus (senesnius nei 5 s):

- `/v1/messages` (Claude Code) – paskutinė user žinutė (prompt'as ar `tool_result`), SSE atsakymas sudedamas
  atgal: `thinking`, `text`, `tool_use` (su `input_json_delta`), `stop_reason`, `usage`;
- `/v1/responses` (Codex) – įvestis po paskutinio modelio išvesties elemento (`function_call_output` ir pan.),
  `response.completed`: `reasoning` santrauka, `message`, `function_call`, `usage`;
- įrankio rezultatas ateina **kitoje** užklausoje: Claude – `tool_result.is_error`, Codex – komandos `exit_code`
  (nėra – „neaišku“); jis pažymimas prie to kvietimo (`tool_call`);
- Codex per **WebSocket** (`supports_websockets = true`): CLIProxyAPI visam ryšiui rašo vieną failą, kai ryšys
  užsidaro, su `=== WEBSOCKET TIMELINE ===` (`websocket.request` / `response` / `disconnect` įvykiai). Kiekvienas
  `response.create` su po jo einančiais įvykiais tampa atskiru įrašu (`<failas>#N`); paskyra – pagal sesiją ir laiką;
- paskyra, sesija, tipas ir CLIProxyAPI trukmė – iš usage įrašo su ta pačia request id pabaiga (failo vardo
  paskutiniai 8 simboliai). **Tipas**: `main`, `subagent` (`parent_session_id`), `compaction`, `fork` (CLIProxyAPI
  `node_kind`), `side` (kliento patikros, `max_tokens ≤ 1`); „prompt'ai“ skaičiuojami tik `main`;
- iš prompt'o išimami klientų įterpti blokai (`<system-reminder>`, `<environment_context>` ir pan.); originalas –
  archyve;
- failas, kurio nepavyksta perskaityti, nestabdo kitų: jis perkeliamas į `cliproxy-traffic/quarantine/` su `.error`
  šalia ir parodomas Įspėjimuose; archyvas rašomas per laikiną failą, o originalas trinamas tik po archyvo ir DB.
  Tą patį failą paėmus dar kartą jo įrašai pakeičiami, ne pridedami;
- paieška – MySQL FULLTEXT (prompt, atsakymas, mąstymas, klaida); eksportas – pagal id (keyset).

Lentelėse `exchange` ir `tool_call` ilgi tekstai trumpinami (prompt'as 64 KB, mąstymas ir atsakymas 256 KB); pilnas
tekstas lieka žaliame faile, kuris suspaudžiamas į `cliproxy-traffic/archive/<diena>/` ir iš `logs/` pašalinamas.
**Automatinio šalinimo nėra**: nei DB įrašai (usage, limitai, žurnalas), nei archyvas netrinami, CLIProxyAPI log'ų
valytojas išjungtas (`logs-max-total-size-mb: 0`). Vietą stebėk pats (`du -sh data/<env>/cliproxy-traffic`). Skydelis archyvą tik skaito; `auth/` su paskyrų raktais nei php-fpm, nei cron nemato.
Žurnale – visas kodas ir pokalbiai: jie lieka tik šiame kompiuteryje.

### Soonest-reset-first

Theo savo fork'e maršrutizuoja į paskyrą, kurios limitas atsinaujina anksčiausiai. Čia – be fork'o: CLIProxyAPI
pirmiausia naudoja didžiausio `priority` paskyras, todėl įjungus (Nustatymai) kolektorius po kiekvieno limitų
skaitymo kiekvienam tiekėjui sudeda prioritetus: anksčiausiai savaitinį limitą atnaujinanti – didžiausias,
išnaudotos (5 val. ar 7 d. ≥ 99,5 %) – 0. Vykstančią sesiją session affinity palieka jos paskyroje. Įjungus rankiniai
prioritetai perrašomi.

### Kolektorius

`php -d memory_limit=2G bin/console app:collect --duration=55` (cron kas minutę; vienu metu – vienas,
`symfony/lock`). Kiekvienas žingsnis atskiras: vieno klaida (CLIProxyAPI persikrauna, DB nepasiekiama) parodoma
Apžvalgoje ir Įspėjimuose, kiti dirba toliau.

1. Kas 5 s – usage įrašai iš CLIProxyAPI eilės (`/observability/usage/queue`; paimti iš eilės dingsta). Kiekvienas
   paketas **pirma** prirašomas į spool'ą `cliproxy-traffic/usage/YYYY-MM-DD.jsonl` (fsync), tik tada į DB. Įrašas
   saugomas visas (`usage_record.raw`, plius `ttft_ms`, `user_agent`, `reasoning_effort`, `parent_session_id`,
   `node_kind`, …), `record_key` (sha1) neleidžia dublikatų. Nepavykus DB įrašymui diena pažymima ir kitas paleidimas
   ją įrašo iš spool'o; ranka – `php bin/console app:usage:replay <diena>|--all`. „Užklausos“ skaičiuoja skirtingus
   request id (pakartojimas ar kita paskyra – tas pats `request_id`), „bandymai“ – visus įrašus.
2. Kas 5 s – request log'ai į žurnalą ir archyvą; kartą per paleidimą – paskyrų susiejimas.
3. Kai laikas arba paspaudus „Perskaityti“ – limitai (`quota_snapshot`) ir prioritetai.
4. Kartą per paleidimą – konfigūracijos bazė, CLIProxyAPI versija (naujausia – kas 6 val.), įspėjimai, heartbeat.

Rankiniu būdu – cron konteineryje (bendras lock'as): `docker exec -u "$(id -u)" -w /var/www/html
cliproxy-<env>-cron php -d memory_limit=2G bin/console app:collect --quotas`.

### Įspėjimai

`src/Alert/AlertMonitor.php`, puslapis „Įspėjimai“ (skaičius šoninėje juostoje): paskyrai reikia prisijungti iš naujo,
paskyros klaida, tiekėjas be nė vienos laisvos paskyros, CLIProxyAPI neatsako, usage nepavyko įrašyti, failai
karantine, diske < 10 % vietos, nauja CLIProxyAPI versija. Įspėjimas atsidaro ir užsidaro pats; istorija lieka.
Nustatymuose įrašius **ntfy** temos adresą (`https://ntfy.sh/<tema>`) kiekvienas naujas (ir išspręstas) klaidos ar
dėmesio įspėjimas siunčiamas į telefoną. Kolektoriui sustojus (> 3 min. be heartbeat) kiekvienas puslapis rodo
juostą.

## Įrankių nukreipimas į hub'ą

Skydelio „Prijungimas“ puslapis duoda tikslius fragmentus. Trumpai:

```json
// ~/.claude/settings.json
{ "env": { "ANTHROPIC_BASE_URL": "http://127.0.0.1:8317", "ANTHROPIC_AUTH_TOKEN": "<raktas>" }, "promptCacheTtl": "1h" }
```

```toml
# ~/.codex/config.toml
model_provider = "cliproxy"
[model_providers.cliproxy]
name = "CLIProxyAPI"
base_url = "http://127.0.0.1:8317/v1"
wire_api = "responses"
requires_openai_auth = false
supports_websockets = true
experimental_bearer_token = "<raktas>"
```

Nekeičiant įprastų nustatymų – `CLAUDE_CONFIG_DIR=~/.claude-hub claude`, `CODEX_HOME=~/.codex-hub codex`.
T3 Code naudoja aplinkos kompiuterio CLI nustatymus; Codex – „Use existing CLI“.

### Prompt cache TTL

Gist'o komentarų (EtanHey, mmmattG) pastaba: su savu `ANTHROPIC_BASE_URL` ir token'u Claude Code (pastebėta 2.1.312)
prompt cache rašo **5 min.**, ne 1 val., kaip prenumeratos prisijungime. Po kiekvienos ilgesnės nei 5 min. pauzės
cache rašomas iš naujo, todėl 5 val. ir savaitės limitai tirpsta greičiau. Todėl kiekvieno kliento
`~/.claude/settings.json` turi `"promptCacheTtl": "1h"` (subagentams – atskiras `subagentPromptCacheTtl`). Ar tai
veikia, rodo Apžvalgos ir Naudojimo kortelė „Cache rašymai 1 val. / 5 min.“: CLIProxyAPI usage įrašai skiria
`cache_creation_5m_tokens` ir `cache_creation_1h_tokens`; vyraujant 5 min. kortelė pažymima.

T3 Code dvigubiems usage įrašams (mmmattG): `requires_openai_auth = true` po `[model_providers.cliproxy]`, jei
kompiuteryje yra ChatGPT prisijungimas ta pačia hub'o paskyra. Nepatikrinta.

## Paleidimas iš švaraus clone'o (local)

```bash
cd ~/Projects/cliproxy/app
cp env/local.env.example env/local.env   # užpildyk raktus: openssl rand -hex 24
make setup
make shell   # php bin/console doctrine:migrations:migrate
```

Skydelis – <https://cliproxy.local>, kūrimo CLIProxyAPI – `127.0.0.1:18317` (be tikrų paskyrų).

Testai (SQLite, netikras CLIProxyAPI `tests/Support/FakeCliProxy.php`):

```bash
docker exec -u "$(id -u)" -w /var/www/html cliproxy-local-php-fpm php bin/phpunit
```

## Diegimas (prod)

setup-server (šis kompiuteris, `SETUP_SERVER_ROOT=/home/tomas/Projects/runtime/setup-server`):

- build katalogas `projects/cliproxy` (`add-project --monorepo`), taikinys `prod=local:/home/tomas/Projects/runtime/cliproxy/prod`;
- deploy key `github-bigsant.cliproxy` (tik skaitymui), runner'is `setup-server-github-runner@bigsant.cliproxy`
  (`cachyos-x8664-cliproxy`, žyma `setup.build`).

Actions → **Deploy** → Run workflow (master). Workflow patikrina, kad `phpunit`, `lint`, `release` žali tame commit'e,
paleidžia setup `pipeline-deploy` (su DB kopija), `deploy-health` (`/login`, `/_health`) ir nepavykus grąžina
ankstesnį release'ą. Paslaptys – repo Actions secrets `PROD_APP_SECRET`, `PROD_ADMIN_PASSWORD`,
`PROD_CLIPROXY_MANAGEMENT_KEY`, `PROD_CLIPROXY_API_KEY`, `PROD_DATABASE_PASSWORD`, `PROD_DATABASE_ROOT_PASSWORD`
(`make -C app deploy-variables ENV=prod names=1`).

Prod skydelis – <https://hub.cliproxy.local> (vartotojas `admin`, slaptažodis – `~/.config/cliproxy/admin-password`,
0600; tas pats yra secret `PROD_ADMIN_PASSWORD`); proxy – `127.0.0.1:8317`. Kompiuterio nginx
(`/etc/nginx/sites-available/hub.cliproxy.local.conf`, mkcert sertifikatas `~/.config/cliproxy/tls/`) jį leidžia tik
iš `127.0.0.1`/`::1` ir perduoda prod nginx-proxy `127.0.0.1:18327`; `/etc/hosts` – `127.0.0.1 hub.cliproxy.local`.

Paruoštos atskiros įrankių konfigūracijos, kurios įprastų nekeičia: `CLAUDE_CONFIG_DIR=~/.claude-hub claude` ir
`CODEX_HOME=~/.codex-hub codex` (abi rodo į `127.0.0.1:8317` su prod raktu). Kai paskyros prijungtos ir veikia, tą
patį galima perkelti į `~/.claude/settings.json` ir `~/.codex/config.toml`.

**Paskyrų raktai** yra `runtime/cliproxy/prod/data/prod/cliproxy/auth/`. Jie į DB kopijas nepatenka – jei kompiuteris
būtų perrašytas, paskyras prijunk iš naujo. Nekopijuok jų į kitus kompiuterius ir nekelk į git.

**Atsarginės kopijos.** `app/database/table-registry.txt` visas lenteles žymi `import`, todėl setup-server naktinės
kopijos (`setup-server-backup.timer`) turi ir duomenis, ne tik struktūrą. Jų rotaciją (7 d. + 4 sav.) valdo
setup-server. Archyvo ir spool'o (`data/prod/cliproxy-traffic`) jos neapima – juos kopijuoti į kitą diską reikia
atskirai (pvz. `rsync -a --ignore-existing` – tik pridedant).

**Host'as.** Miego režimai užmaskuoti (`systemctl mask sleep.target suspend.target hibernate.target
hybrid-sleep.target`), kaip reikalauja Theo gidas; atšaukti – `systemctl unmask …`.

## Kai kas nors lūžta

- Paskyrą išimti iš rotacijos – Paskyros → „Išjungti“ (`disabled: true`, be restart'o).
- Codex `token_invalidated` (401) – Paskyros → „Prisijungti iš naujo“ (ne šalinti).
- Viena paskyra tiekėjui – pasiekus limitą užklausoms nėra kur eiti.
- Cache rašymų daugiau nei skaitymų / daug sesijų „peršoko“ (Naudojimas) – tikrink session affinity.
- Skydelis rodo „CLIProxyAPI nepasiekiamas“ – `docker logs cliproxy-<env>-cliproxy`; 5 neteisingi management raktai
  iš vieno adreso jį užblokuoja 30 min. (padeda `docker restart`).
