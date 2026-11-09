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
  Šablone: session affinity įjungtas (1 h), usage statistika įjungta su 1 h eile, `disable-control-panel: true`.
- Prieiga: **tik šis kompiuteris** (`127.0.0.1`). Iš kito kompiuterio – SSH tunelis
  `ssh -N -L 8317:127.0.0.1:8317 cachyos-x8664`. Tailscale galima pridėti vėliau (`tailscale serve --bg
  --https=8317 http://127.0.0.1:8317`).

## Skydelis

| Puslapis | Kas |
| --- | --- |
| Apžvalga | Paskyros su 5 val., 7 d. ir 7 d. Fable limitais (kiek panaudota, po kiek atsinaujina), 24 val. užklausos, tokenai, cache skaitymai/rašymai, session affinity, gyvas užklausų srautas (kas 5 s). |
| Paskyros | Prijungti Claude / Codex paskyrą, įjungti/išjungti, prioritetas, svoris, Codex WebSocket'ai, pastaba, rakto atnaujinimas, patikra, pašalinimas. |
| Naudojimas | 24 val. – 90 d.: per dieną, pagal paskyrą ir modelį; cache santykis; sesijos, peršokusios į kitą paskyrą. |
| Nustatymai | Strategija, session affinity, pakartojimai; soonest-reset-first; klientų raktai; visas `config.yaml`. |
| Prijungimas | Paruošti `~/.claude/settings.json` ir `~/.codex/config.toml` fragmentai su hub'o adresu ir raktu. |

Prisijungimas – vartotojas `admin`, slaptažodis `ADMIN_PASSWORD`.

### Paskyros prijungimas

Paskyros → „+ Claude paskyra“ (ar Codex) → „Atidaryti“: prisijungi **savo naršyklėje**. Pabaigoje naršyklė atsidaro
`http://localhost:54545/callback?…` (Codex – `localhost:1455/auth/callback?…`) ir **neužsikrauna – taip ir turi
būti**. Visą adresą įklijuok į laukelį ir „Prijungti“. CLIProxyAPI iškeičia kodą į raktą ir įrašo paskyrą į
`auth/`. SSH tunelio ir callback port'ų nereikia (CLIProxyAPI management OAuth be `is_webui`).

Kiekvienai paskyrai – atskiras prisijungimas (naršyklėje atsijunk nuo ankstesnės arba naudok privatų langą).

### Limitai

CLIProxyAPI pats Claude/Codex limitų nerenka. Kolektorius kas `QUOTA_REFRESH_MINUTES` (numatyta 5 min., Nustatymai)
per CLIProxyAPI `/requests/api-call` su paskyros raktu klausia tiekėjo:

- Claude – `https://api.anthropic.com/api/oauth/usage` (`five_hour`, `seven_day`, `iguana_necktie` = 7 d. Fable);
- Codex – `https://chatgpt.com/backend-api/wham/usage` (pirminis ir antrinis langas, sukaupti atstatymai).

Nepavykus – paskutinio proxy atsakymo `anthropic-ratelimit-unified-*` / `x-codex-*` antraštės (žymima „iš
antraščių“). Nuotraukos laikomos 30 d.

### Soonest-reset-first

Theo savo fork'e maršrutizuoja į paskyrą, kurios limitas atsinaujina anksčiausiai. Čia – be fork'o: CLIProxyAPI
pirmiausia naudoja didžiausio `priority` paskyras, todėl įjungus (Nustatymai) kolektorius po kiekvieno limitų
skaitymo kiekvienam tiekėjui sudeda prioritetus: anksčiausiai savaitinį limitą atnaujinanti – didžiausias,
išnaudotos (5 val. ar 7 d. ≥ 99,5 %) – 0. Vykstančią sesiją session affinity palieka jos paskyroje. Įjungus rankiniai
prioritetai perrašomi.

### Kolektorius

`php bin/console app:collect --duration=55` (cron kas minutę; vienu metu – vienas, `symfony/lock`):

1. kas 5 s ima usage įrašus iš CLIProxyAPI eilės (`/observability/usage/queue`; paimti įrašai iš eilės dingsta,
   todėl skaitytojas – tik jis) į `usage_record` (90 d.);
2. kai laikas – limitus (`quota_snapshot`) ir prioritetus;
3. kartą per valandą – senų įrašų valymas.

Eilė laiko įrašus valandą (`redis-usage-queue-retention-seconds: 3600`), todėl trumpas kolektoriaus ar DB
nebuvimas duomenų nepraranda. Rankiniu būdu: `make -C app shell`, tada `php bin/console app:collect --quotas`.

## Įrankių nukreipimas į hub'ą

Skydelio „Prijungimas“ puslapis duoda tikslius fragmentus. Trumpai:

```json
// ~/.claude/settings.json
{ "env": { "ANTHROPIC_BASE_URL": "http://127.0.0.1:8317", "ANTHROPIC_AUTH_TOKEN": "<raktas>" } }
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

Prod skydelis – `hub.cliproxy.local` per nginx-proxy `127.0.0.1:18327` (HTTPS `18328`); proxy – `127.0.0.1:8317`.

**Paskyrų raktai** yra `runtime/cliproxy/prod/data/prod/cliproxy/auth/`. Jie į DB kopijas nepatenka – jei kompiuteris
būtų perrašytas, paskyras prijunk iš naujo. Nekopijuok jų į kitus kompiuterius ir nekelk į git.

## Kai kas nors lūžta

- Paskyrą išimti iš rotacijos – Paskyros → „Išjungti“ (`disabled: true`, be restart'o).
- Codex `token_invalidated` (401) – paskyra pašalinama ir prijungiama iš naujo.
- Viena paskyra tiekėjui – pasiekus limitą užklausoms nėra kur eiti.
- Cache rašymų daugiau nei skaitymų / daug sesijų „peršoko“ (Naudojimas) – tikrink session affinity.
- Skydelis rodo „CLIProxyAPI nepasiekiamas“ – `docker logs cliproxy-<env>-cliproxy`; 5 neteisingi management raktai
  iš vieno adreso jį užblokuoja 30 min. (padeda `docker restart`).
