<div align="center">

# MessageGlobe Sync per PrestaShop

**Il modulo ufficiale PrestaShop per [MessageGlobe](https://messageglobe.com)** —
sincronizza i tuoi clienti nelle liste contatti MessageGlobe e recapita ogni email del negozio via SMTP.

[English](README.md) · **Italiano**

[![Ultima release](https://img.shields.io/github/v/release/Message-Globe/messageglobe-presta?sort=semver)](https://github.com/Message-Globe/messageglobe-presta/releases/latest)
[![CI](https://github.com/Message-Globe/messageglobe-presta/actions/workflows/ci.yml/badge.svg)](https://github.com/Message-Globe/messageglobe-presta/actions/workflows/ci.yml)
[![PrestaShop](https://img.shields.io/badge/PrestaShop-8.1%2B-df0067?logo=prestashop&logoColor=white)](https://www.prestashop.com)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php&logoColor=white)](https://www.php.net)

</div>

---

MessageGlobe Sync collega il tuo negozio alla piattaforma di messaggistica
[MessageGlobe](https://messageglobe.com) e svolge due compiti, ciascuno opzionale:

- **Sincronizzazione contatti** — aggiunge i tuoi clienti (email + telefono + nome) a una lista
  contatti MessageGlobe, in automatico quando si registrano o cambiano, e in blocco per il catalogo
  esistente.
- **Email affidabili** — instrada ogni email in uscita di PrestaShop (conferme d'ordine, email
  account, ecc.) attraverso il relay SMTP di MessageGlobe per una migliore deliverability.

Il layer di sincronizzazione contatti è costruito sull'
[SDK PHP ufficiale di MessageGlobe](https://github.com/Message-Globe/messageglobe-php) — incluso in
`vendor/`, quindi non serve Composer sul server — e le sincronizzazioni vengono messe in coda per
l'elaborazione in background, così front office e back office non aspettano mai la rete. Il recapito
email usa il client SMTP dedicato del modulo, senza dipendenze, quindi nessun PHPMailer viene incluso.

## Indice

- [Funzionalità](#funzionalità)
- [Requisiti](#requisiti)
- [Installazione](#installazione)
- [Configurazione](#configurazione)
- [Sincronizzazione contatti](#sincronizzazione-contatti)
- [Sincronizzazione in blocco](#sincronizzazione-in-blocco)
- [Coda cron](#coda-cron)
- [Instradamento email (SMTP)](#instradamento-email-smtp)
- [Sicurezza e privacy](#sicurezza-e-privacy)
- [Come funziona](#come-funziona)
- [Sviluppo](#sviluppo)
- [FAQ](#faq)
- [Licenza](#licenza)

## Funzionalità

| Funzionalità | Cosa fa |
|---|---|
| **Sincronizzazione contatti** | Aggiunge i clienti (email, telefono, nome e cognome) a un gruppo contatti MessageGlobe quando vengono creati o aggiornati. |
| **Sincronizzazione in blocco** | **Sincronizza tutti i clienti** con un clic, con richieste AJAX parallele a lotti e barra di avanzamento. |
| **Coda cron** | Coda in background con retry/backoff e registro delle esecuzioni recenti — riconcilia cataloghi grandi senza timeout. |
| **Takeover email SMTP** | Instrada tutte le email in uscita di PrestaShop attraverso il relay MessageGlobe, con fallback automatico al mailer di PrestaShop se un invio fallisce. |
| **Test connessione SMTP** | Verifica host e credenziali senza inviare un'email. |
| **Costruito sull'SDK** | Il REST (Contatti) usa l'SDK PHP ufficiale di MessageGlobe; l'email usa un client SMTP senza dipendenze — nessun PHPMailer incluso. |

## Requisiti

- PrestaShop **8.1 → attuale**
- PHP **7.4+**
- Un [account MessageGlobe](https://dashboard.messageglobe.com) e un access token
- Un **gruppo contatti** (lista) MessageGlobe (UID) in cui sincronizzare

## Installazione

### Da una release (consigliato)

1. Scarica **`messageglobesync-x.y.z.zip`** dall'
   [ultima release](https://github.com/Message-Globe/messageglobe-presta/releases/latest) — **non**
   l'archivio "Source code" (ha come cartella radice `messageglobe-presta-x.y.z/` e PrestaShop
   rifiuta il nome della cartella).
2. Nel back office vai su **Moduli → Gestione moduli → Carica un modulo**, scegli lo zip, quindi
   installalo e configuralo.

Lo zip della release include l'SDK (`vendor/`): niente Composer sul server.

### Da sorgente (sviluppatori)

```bash
git clone https://github.com/Message-Globe/messageglobe-presta.git modules/messageglobesync
```

La cartella **deve** chiamarsi `messageglobesync`. Poi installa **Message Globe Contact Sync** dalla
Gestione moduli. Nessun passaggio Composer richiesto — l'SDK è incluso in `vendor/`.

## Configurazione

Apri la pagina di configurazione del modulo e imposta:

- **Access token** — il tuo bearer token dell'API MessageGlobe.
- **Group ID** — l'UID della lista contatti (gruppo) MessageGlobe in cui sincronizzare.
- **Cron token** — un segreto che protegge l'URL cron. Lascia vuoto e salva per generarne uno
  automaticamente.

## Sincronizzazione contatti

I clienti vengono sincronizzati nel tuo gruppo MessageGlobe con questi campi, quando disponibili:

- `email` (dal record cliente)
- `phone` (dall'indirizzo più recente non eliminato del cliente, preferendo `phone_mobile` a `phone`)
- `first_name` / `last_name`

La sincronizzazione avviene automaticamente quando un **cliente viene creato** o **aggiornato**, e su
richiesta tramite [sincronizzazione in blocco](#sincronizzazione-in-blocco) o la
[coda cron](#coda-cron). Il modulo mantiene una mappatura locale tra ogni cliente PrestaShop e l'UID
del suo contatto MessageGlobe.

Poiché l'API Contatti di MessageGlobe espone endpoint di creazione ed eliminazione, un aggiornamento
viene applicato come **elimina il contatto remoto precedente, poi crea un nuovo contatto** con i dati
correnti. Se un cliente rimane senza email né telefono, il suo contatto remoto viene rimosso per
mantenere pulita la lista.

## Sincronizzazione in blocco

Usa **Sincronizza tutti i clienti** nella pagina di configurazione per inviare i clienti esistenti a
MessageGlobe. Per evitare richieste troppo lunghe, il lavoro è suddiviso in piccoli lotti AJAX
inviati dal browser con una barra di avanzamento in tempo reale.

Impostazioni predefinite:

- Dimensione lotto: `10` clienti per richiesta
- Richieste parallele: `4`

Se Cloudflare o il tuo host bloccano le richieste, riduci la dimensione del lotto o il numero di
richieste parallele; aumentali con cautela se il server regge bene.

## Coda cron

Per cataloghi grandi, usa la coda in background invece del browser.

- Clicca **Accoda tutti i clienti per la sincronizzazione cron** per accodare ogni cliente.
- Copia l'**URL cron** generato e chiamalo a intervalli (ogni minuto è consigliato).

```bash
curl -s "https://tuo-negozio.example.com/module/messageglobesync/cron?token=YOUR_TOKEN"
```

Override opzionale della dimensione del lotto:

```bash
curl -s "https://tuo-negozio.example.com/module/messageglobesync/cron?token=YOUR_TOKEN&limit=25"
```

Ogni esecuzione elabora un piccolo lotto ed esce rapidamente. I clienti nuovi e aggiornati vengono
accodati dagli hook, così le richieste di front e back office restano veloci. I job falliti vengono
ritentati automaticamente con backoff esponenziale fino al massimo configurato, e la pagina di
configurazione mostra le esecuzioni cron più recenti.

## Instradamento email (SMTP)

Il modulo può facoltativamente **prendere il controllo di tutte le email in uscita di PrestaShop** e
recapitarle attraverso il relay SMTP di MessageGlobe. Attiva **Instrada le email in uscita tramite
Message Globe** nel pannello *Takeover email* e configura:

- **Host** (default `dashboard.messageglobe.com`)
- **Porta** (`465` per SSL/SMTPS, `587` per STARTTLS)
- **Cifratura** (SSL, STARTTLS o nessuna)
- **Username** e **password / API token**
- **From** forzato (indirizzo e nome) opzionale (molti relay richiedono che il mittente corrisponda al
  dominio autenticato)

Usa **Test connessione** per collegarti e autenticarti al server **senza inviare un'email** e
confermare le credenziali prima di attivare il takeover — lascia vuota la password per testare quella
salvata.

Quando è attivo, il modulo intercetta l'hook `actionEmailSendBefore`, ri-renderizza il template email
di PrestaShop (sia la parte HTML sia la corrispondente `.txt`, in un messaggio
`multipart/alternative`) e lo invia attraverso il relay. **Se un invio fallisce per qualsiasi motivo,
il modulo torna automaticamente al mailer predefinito di PrestaShop** così nessun messaggio va perso,
e l'errore viene registrato tramite `PrestaShopLogger`.

## Sicurezza e privacy

- Il **cron token** protegge l'endpoint cron; un'esecuzione con token mancante o errato viene
  rifiutata.
- La **password SMTP** non viene mai ristampata nel form — un invio con campo vuoto mantiene il valore
  salvato.
- Il modulo invia a MessageGlobe soltanto i dati che configuri (i campi contatto sopra e le email che
  PrestaShop stava già per inviare). Nulla viene condiviso con altre terze parti.
- Gli errori di API e di recapito vengono registrati tramite `PrestaShopLogger`.

## Come funziona

- **SDK PHP per il REST.** Le chiamate di creazione/eliminazione contatto passano dall'SDK PHP di
  MessageGlobe incluso (`vendor/messageglobe/sdk`), caricato da un piccolo autoloader PSR-4 — nessun
  cURL grezzo nel modulo.
- **Email senza dipendenze.** Il takeover SMTP usa il client SMTP dedicato del modulo, così PHPMailer
  non viene mai incluso e non può collidere con la libreria email di PrestaShop (Swift Mailer su 8.x,
  Symfony Mailer su 9.x).
- **Asincrono per default.** I clienti nuovi/aggiornati vengono scritti in una tabella di coda e
  smaltiti dal worker cron con retry/backoff, così le richieste di pagina ritornano subito.
- **Mappatura.** Una tabella locale mappa ogni cliente PrestaShop all'UID del suo contatto
  MessageGlobe.

## Sviluppo

```bash
php -l messageglobesync.php   # lint (ripeti per file, o usa un linter)
```

La CI ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)) esegue `php -l` sul modulo e sull'SDK
incluso su PHP 7.4–8.3 e verifica la struttura del modulo e la coerenza della versione a ogni push e
pull request.

Le release sono automatiche: il push di un tag `vX.Y.Z` esegue
[`.github/workflows/release.yml`](.github/workflows/release.yml), che costruisce lo zip installabile
nativamente `messageglobesync-X.Y.Z.zip` (un singolo albero con cartella radice `messageglobesync/` e
l'SDK incluso) e lo allega alla GitHub Release. Un'esecuzione manuale del workflow carica lo stesso
zip come artifact per i test.

Per aggiornare l'SDK incluso, copia l'albero `src/` upstream (escludendo `Email/` e
`MessageGlobe.php`, che richiamano PHPMailer) su `vendor/messageglobe/sdk/src`.

## FAQ

**Serve un account MessageGlobe?**
Sì — creane uno e copia il tuo access token e l'UID del gruppo (lista) di destinazione dalla dashboard.

**Si sincronizza quando un cliente viene eliminato o quando un indirizzo cambia?**
No. La sincronizzazione contatti avviene quando un cliente viene **creato** o **aggiornato**.
L'eliminazione di un cliente o la modifica di un indirizzo non attivano più una sincronizzazione —
esegui una [sincronizzazione in blocco](#sincronizzazione-in-blocco) o la [coda cron](#coda-cron) per
riconciliare la lista quando serve.

**Perché preferire lo zip della release al download "Source code" di GitHub?**
PrestaShop richiede che la cartella del modulo si chiami esattamente `messageglobesync`. L'asset della
release ha quella radice; l'archivio "Source code" generato automaticamente no.

**I contatti non compaiono subito.**
Le sincronizzazioni automatiche sono messe in coda ed elaborate dall'endpoint cron. Configura un cron
di sistema reale che chiami l'URL cron ogni minuto per un'elaborazione tempestiva.

## Licenza

Costruito sull'[SDK PHP di MessageGlobe](https://github.com/Message-Globe/messageglobe-php),
rilasciato con licenza MIT. Vedi il repository per i termini di licenza del modulo.
