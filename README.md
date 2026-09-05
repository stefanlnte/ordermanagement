<p align="center">
  <img src="comenzi.svg" alt="Comenzi – logo" width="420">
</p>

# Sistem de Gestionare a Comenzilor

**Aplicație PHP + MySQL pentru administrarea comenzilor unei tipografii (Color Print).** Interfață integral în limba română, fără framework și fără build — o aplicație clasică de tip LAMP: fișiere PHP care combină HTML, SQL și JavaScript, cu o singură conexiune MySQL partajată.

![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.x-4479A1?logo=mysql&logoColor=white)
![License](https://img.shields.io/badge/License-GPL%20v2-blue)

---

## Cuprins

1. [Descriere](#descriere)
2. [Funcționalități](#funcționalități)
3. [Tehnologii](#tehnologii)
4. [Cerințe preliminare](#cerințe-preliminare)
5. [Instalare](#instalare)
6. [Configurarea sesiunilor (php.ini)](#configurarea-sesiunilor-phpini)
7. [Structura proiectului](#structura-proiectului)
8. [Arhitectură](#arhitectură)
9. [Baza de date](#baza-de-date)
10. [Scripturi speciale și avertismente](#scripturi-speciale-și-avertismente)
11. [Convenții și reguli importante](#convenții-și-reguli-importante)
12. [Depanare](#depanare)
13. [Livrare în producție](#livrare-în-producție)
14. [Licență](#licență)

---

## Descriere

Acest proiect este sistemul intern de gestionare a comenzilor pentru tipografia **Color Print** (instalat în producție la `color-print.ro/magazincp/`).

Aplicația este folosită de operatorii magazinului pentru:

- înregistrarea și urmărirea comenzilor de tipăritură (statusuri, termene, SLA),
- evidența articolelor din fiecare comandă („bonul”),
- evidența clienților și a prețurilor implicite,
- comunicarea cu clienții prin mesaje WhatsApp,
- raportarea încasărilor pe operatori (statistici grafice),
- evidența comenzilor nefacturate și a arhivei de comenzi.

Interfața cu utilizatorul este **integral în limba română**.

---

## Funcționalități

### Gestionarea comenzilor

- **Dashboard** (`dashboard.php`) — tabela centrală a comenzilor, cu filtre pe status, operator, categorie, client și sortare; paginare la **18 comenzi pe pagină**; bandă de comenzi fixate (pin); antet cu saluturi în funcție de ora zilei.
- **Carduri de statistici** — funcționează drept scurtături de filtrare: un click pe card filtrează tabela (întârziate, atribuite, finalizate, de livrat azi, livrate azi).
- **Adăugare rapidă de comandă** din bara laterală a dashboard-ului.
- **Detaliu comandă** (`view_order.php`) — se deschide într-un panou glisant off-canvas: tabela articolelor („bonul”), pas cu pas de status (stepper), numărătoare SLA, formulare de editare.
- **Previzualizare la trecerea cu mouse-ul** — fragment HTML generat pe server (`order_preview.php`), afișat cu Tippy.js.
- **Comenzi nefacturate** (`unpaid_orders.php`) — listă separată pentru comenzile „nefacturate”.
- **Arhivă** (`archive.php`) — vizualizator doar-citire peste comenzile arhivate (`archived_orders`).

### Comunicații

- **Expeditor WhatsApp** în dashboard — se alege prefixul de țară (RO / IT / ES / UK / DE) sau se introduce manual; linkurile `wa.me` folosesc numere normalizate.
- **Mesaje-șablon WhatsApp** din detaliul comenzii.

### Atașamente

- Încărcare multiplă cu Dropzone, descărcare și ștergere (`upload_attachment.php`, `download_attachment.php`, `delete_attachment.php`).
- Fișierele sunt stocate pe disc în `uploads/orders/<id_comandă>/`, cu înregistrare în tabela `order_attachments`; directorul se creează automat la nevoie.

### Statistici

- `statistics.php` — grafice **ApexCharts**: încasări zilnice / săptămânale / lunare pe operator + grafic tip „pie” cu comenzile livrate. Culorile operatorilor provin din mapa `$userColors` (definită în fișier).

### Actualizare în timp real (multi-utilizator)

- Fiecare dashboard interoghează `refresh_check.php` la ~10 secunde; dacă alt operator a modificat ceva, tabela se actualizează **fără reîncărcarea paginii** (*quiet refresh*), cu View Transitions unde browserul le suportă.
- Se folosesc două semnături: `pageSig` (tabel + paginare + fixate) și `statsSig` (agregatele globale ale cardurilor) — dacă se schimbă doar agregatele, se actualizează doar bannerul de statistici.

### Autentificare

- Login cu parole bcrypt (`password_hash` / `password_verify`).
- Bifa **„Ține-mă minte”** — cookie de 30 de zile, susținut de tabela `remember_tokens`.
- Fiecare pagină își protejează accesul: dacă nu ești autentificat, ești redirecționat la `login.php`.

---

## Tehnologii

| Strat | Tehnologie |
|---|---|
| Backend | PHP (fără framework), extensia `mysqli` |
| Bază de date | MySQL / MariaDB |
| Frontend | HTML, CSS, JavaScript (jQuery) |
| Biblioteci UI (CDN) | Select2, SweetAlert2, AOS, Tippy.js, Dropzone.js, ApexCharts |
| Server | Apache (XAMPP) |

**Observații:**

- Nu există `composer.json`, `package.json`, build step, linter sau test runner — totul se rulează direct din surse.
- Toate bibliotecile frontend sunt încărcate din CDN în `<head>`-ul fiecărei pagini.
- Validarea se face deschizând paginile în browser (nu există teste automate).

---

## Cerințe preliminare

- **XAMPP** (sau echivalent: Apache + PHP 8+ + MySQL/MariaDB) — proiectul este dezvoltat și rulat pe Windows.
- Un browser modern.
- phpMyAdmin (livrat cu XAMPP) pentru crearea și importul bazei de date.

---

## Instalare

1. **Copiază proiectul** în rădăcina web a Apache-ului, de ex. `C:\xampp\htdocs\ordermanagement\`.
2. **Pornește Apache și MySQL** din XAMPP Control Panel.
3. **Importă schema** din [`schema.sql`](schema.sql) — fișierul include și `CREATE DATABASE order_management_system`:
   - **phpMyAdmin**: deschide `http://localhost/phpmyadmin` → *Import* → selectează `schema.sql`;
   - **CLI**: `C:\xampp\mysql\bin\mysql.exe -u root < schema.sql`.
   - Fișierul creează toate tabelele (detaliate la [Baza de date](#baza-de-date)) + date de pornire: un utilizator `admin` (parola `password` — **schimb-o după primul login!**) și categoria `Diverse`. Necesită MySQL 8.0.13+ / MariaDB 10.2.1+.
4. **Verifică datele de conexiune** în `db.php`:

   ```php
   $servername = "localhost";
   $username   = "root";
   $password   = "";                       // fără parolă, implicit în XAMPP
   $dbname     = "order_management_system";
   ```

5. **Drepturi de scriere pentru `uploads/`** — `upload_attachment.php` creează la cerere structura `uploads/orders/<id_comandă>/`; directorul părinte trebuie să fie inscriptibil.
6. **Configurează utilizatorii** — schema vine cu un utilizator seed `admin` (parola `password`); schimbă-i parola sau adaugă operatori noi direct în tabela `users`. Poți genera hash-uri bcrypt cu `hash.php`.
7. **Deschide aplicația**: `http://localhost/ordermanagement/login.php`.

---

## Configurarea sesiunilor (php.ini)

Fișierul `php.ini` din rădăcina repository-ului configurează sesiunile pe 30 de zile:

```ini
[Session]
session.cookie_lifetime = 2592000   ; 30 zile
session.gc_maxlifetime  = 2592000   ; 30 zile
session.cookie_secure   = 1         ; necesită HTTPS
session.cookie_httponly = 1
session.use_only_cookies = 1
```

- În **producție** aplicația rulează în spatele HTTPS, deci `cookie_secure = 1` funcționează corect.
- **Local, pe `http://localhost`**, cookie-ul `Secure` poate fi ignorat de anumite browsere; dacă sesiunea nu se păstrează după login, setează temporar `cookie_secure = 0` — **doar pe mediul local**.
- Configurația cookie-urilor de sesiune este **duplicată și în codul PHP**, pe lângă `php.ini` — nu elimina niciuna dintre ele.

---

## Structura proiectului

Nu există strat de rutare: **fiecare fișier `.php` din rădăcină este un endpoint** pe care browserul îl accesează direct.

### Pagini principale

| Fișier | Rol |
|---|---|
| `login.php` / `authenticate.php` / `logout.php` | Autentificare, verificare, delogare |
| `dashboard.php` | Pagina principală: tabelul comenzilor, filtre, paginare, comenzi fixate, carduri de statistici, bara laterală „adăugare comandă”, expeditor WhatsApp |
| `view_order.php` | Detaliul unei comenzi (se încorporează în slider prin `?embedded=1`) |
| `statistics.php` | Grafice ApexCharts (încasări per operator, livrări) |
| `unpaid_orders.php` | Lista comenzilor nefacturate |
| `archive.php` | Vizualizator doar-citire pentru comenzile arhivate |
| `order_preview.php` | Fragment HTML pentru previzualizarea comenzii la hover (Tippy) |
| `refresh_check.php` | Sondaj AJAX (~10s) pentru actualizările în timp real |

### Acțiuni (mutatori POST — câte o acțiune per fișier)

`add_order.php` · `add_article.php` · `delete_article.php` · `cancel_order.php` · `delete_order.php` · `update_order_details.php` · `update_order_status.php` · `update_achitat.php` · `toggle_pin.php` · `update_client.php` · `edit_client.php` · `update_default_price.php` · `upload_attachment.php` · `delete_attachment.php`

### Endpoint-uri JSON (căutări Select2 / widget-uri dashboard)

`fetch_orders.php` · `fetch_articles.php` · `fetch_clients.php` · `fetch_order_articles.php` · `search_orders.php` · `get_client.php` · `get_operators.php` · `get_users.php`

### Alte fișiere

| Fișier | Rol |
|---|---|
| `download_attachment.php` | Descărcarea atașamentelor |
| `db.php` | Conexiunea `mysqli` unică (`$conn`), inclusă de toate paginile |
| `schema.sql` | Schema bazei de date (se importă la instalare — vezi [Instalare](#instalare)) |
| `hash.php` | Afișează un hash bcrypt pentru o parolă placeholder |
| `archive_orders.php` | Script unic de arhivare + renumerotare (distructiv — vezi [avertismente](#scripturi-speciale-și-avertismente)) |
| `add_user.php` | Script de administrare **neterminat** — nu-l folosi |
| `script.js` | Ajutor pentru formularul de adăugare comandă |
| `styles.css`, `stylelogin.css` | Stiluri; majoritatea paginilor au și CSS inline |
| `php.ini` | Configurarea sesiunilor (30 zile, Secure, HttpOnly) |
| `comenzi.html` | Pagină statică de redirect către instalarea live |
| `comenzi.svg`, `header.webp`, `favicon.ico` | Resurse grafice |

---

## Arhitectură

- **Fără rutare**: browserul navighează direct la fișierele PHP; mutatorii sunt scripturi POST mici, câte un fișier per acțiune.
- **Acces unic la baza de date**: `db.php` creează conexiunea `mysqli` în `$conn`; aproape fiecare pagină face `include 'db.php'` și rulează SQL direct, cu `prepare()` + `bind_param()` (tipurile legate se scriu ca string `i`/`s`/`d` extins cu `...$params`).
- **Gardă de autentificare duplicată**: blocul de validare a tokenului „ține-mă minte” + redirect la `login.php` se repetă în fiecare pagină — aceasta este forma existentă, se urmează.
- **Quiet refresh**: `dashboard.php` expune `window.quietRefresh(url, { resetForm })`, care preia același URL și înlocuiește local doar `.pinned-section`, `tbody`-ul și `.pagination` — fără reîncărcare completă a paginii.
- **Actualizări multi-utilizator**: fiecare client trimite filtrele curente + un hash (`sig`) al tabelei către `refresh_check.php`; serverul rulează aceeași interogare filtrată, re-calculează hash-ul și răspunde `changed: true` doar dacă acesta diferă. `refresh_check.php` trebuie să păstreze `$limit = 18` și logica WHERE/ORDER **identică** cu `dashboard.php`.
- **Cardurile de statistici sunt filtre**: fiecare `.stat-card` poartă `data-status-filter`; mapa `$smart_status_filters` din `dashboard.php` trebuie să rămână identică cu cea din `refresh_check.php`.
- **Detaliul comenzii** se deschide în iframe-ul off-canvas `#orderSliderPanel`, cu sursa `view_order.php?order_id=...&embedded=1`.
- **Statusuri de comandă**: `assigned`, `completed`, `delivered`, `cancelled` — `delivered` și `cancelled` sunt excluse din vederea implicită a dashboard-ului. Unele fișiere folosesc un vocabular diferit (`UNASSIGNED` / `IN PROGRESS` / `FINISHED` în `fetch_orders.php`) — se urmează stilul fișierului editat, nu se „corectează”.
- **CSS pentru Select2**: blocurile cu temă galbenă sunt aproximat-duplicate în mai multe pagini — se păstrează patternul per-pagină, nu se extrage o foaie de stil comună.

---

## Baza de date

Schema completă a bazei de date se află în [`schema.sql`](schema.sql) și corespunde exact interogărilor din cod (tipuri, valori implicite și indecși justificați în comentariile fișierului). Tabelele folosite de aplicație:

| Tabelă | Rol |
|---|---|
| `orders` | Comenzile active |
| `order_articles` | Articolele din fiecare comandă („bonul”) |
| `articles` | Catalogul articolelor (cu preț implicit) |
| `categories` | Categoriile de articole |
| `clients` | Clienții |
| `users` | Operatorii / utilizatorii aplicației |
| `order_attachments` | Metadatele atașamentelor (fișierele pe disc, în `uploads/orders/<id>/`) |
| `unpaid_orders` | Comenzile nefacturate |
| `archived_orders` | Comenzile livrate/anulate, arhivate |
| `remember_tokens` | Tokenurile pentru „Ține-mă minte” (30 de zile) |

---

## Scripturi speciale și avertismente

> ⚠️ **`archive_orders.php` este DISTRUCTIV.** Mută comenzile `delivered`/`cancelled` cu `order_id > 2000` în `archived_orders`, le șterge, apoi **renumerotează toate `order_id`-urile rămase începând de la 1**. Este protejat prin parolă în query string (parola este documentată în `CLAUDE.md`) și este singurul loc din aplicație unde `order_id` este modificat. Nu-l rula din întâmplare.

- **`add_user.php`** — script de administrare **neterminat**: variabilele `$username` și `$password` sunt declarate goale, ceea ce produce eroare de sintaxă în versiunile curente de PHP. Nu-l rula; creează utilizatorii direct în tabela `users`.
- **`send_sms.php`** — este referențiat din `view_order.php`, dar **lipsește** din repo. Dacă este nevoie de SMS, fișierul trebuie adăugat.
- **`hash.php`** — afișează un hash bcrypt pentru o parolă placeholder; util pentru (re)inițializarea parolelor utilizatorilor.
- **`console.log`** (la rădăcina repo, în istoric) era un fișier gol, nefolosit de aplicație.

---

## Convenții și reguli importante

- Numerele de telefon se validează pe client cu tiparul **`0[0-9]{9}`** (10 cifre, cu 0 inițial) — regula trebuie păstrată.
- Pentru linkurile `wa.me`, numerele sunt normalizate la formatul `+4<cifre>`.
- Moneda este **lei**; totalurile se formatează cu `number_format(..., 2)`.
- Numele zilelor și ale lunilor sunt **scrise manual în română** (`Luni`, `februarie`, …) în mai multe fișiere — nu se traduc.
- Doi utilizatori sunt excluși intenționat prin `WHERE user_id NOT IN (3, 4)` (în prezent în `get_operators.php`, dropdown-ul de operatori) — nu elimina condiția fără o decizie deliberată.
- Interfața este în **română**; codul, comentariile și identificatorii rămân în **engleză**, cu excepția fișierelor care amestecă deja cele două limbi — în acele cazuri se respectă stilul local.
- Tema vizuală este **galben + negru/gri** (`#FFFF00` este accentul; clasele `.theme-yellow` / `.theme-magenta` / `.theme-cyan` / `.theme-green` / `.theme-key` conduc aspectul „heavy theme” al rândurilor din dashboard).
- Interogările se scriu cu `mysqli` + `prepare()` / `bind_param()` — nu introduce PDO, query builder sau ORM.
- Paginarea folosește o interogare paralelă `COUNT(*)` cu aceleași condiții — păstrează acest pattern.
- Nu se introduc manager de pachete, build step sau test runner — aplicația funcționează direct din surse.

---

## Depanare

| Simptom | Cauză probabilă / rezolvare |
|---|---|
| `Connection failed` la deschiderea paginilor | MySQL nu este pornit sau datele din `db.php` nu corespund |
| Nu te poți loga pe o instalare nouă | Schema creează utilizatorul `admin` cu parola `password`; schimb-o după login sau generează un hash nou cu `hash.php` |
| Login-ul „nu se ține” pe localhost | `session.cookie_secure = 1` cere HTTPS; pe `http://localhost` setează temporar `0` (doar local) |
| Atașamentele nu se încarcă | Verifică drepturile de scriere pe `uploads/orders/` |
| Comenzile adăugate nu apar la ceilalți utilizatori | Verifică sincronizarea logicii WHERE/ORDER și a `$limit = 18` între `dashboard.php` și `refresh_check.php` |
| Graficele din `statistics.php` sunt goale / fără culori | Numele operatorilor trebuie să corespundă exact cu mapa `$userColors` |
| Click pe cardul de statistici nu filtrează | Verifică `data-status-filter` pe carduri și mapa `$smart_status_filters` (identică în `dashboard.php` și `refresh_check.php`) |

---

## Livrare în producție

- Instalarea curentă rulează la **`color-print.ro/magazincp/`**, în spatele HTTPS.
- `comenzi.html` este o pagină statică de redirect către instalarea live — nu face parte din aplicația PHP.
- La livrare, verifică:
  - credentialele de producție în `db.php`,
  - `php.ini` — `session.cookie_secure = 1` (HTTPS activ),
  - drepturile de scriere pentru `uploads/` pe server,
  - faptul că nu se livrează scripturile one-shot (`archive_orders.php`, `hash.php`, `add_user.php`) dacă nu este necesar.

---

## Licență

Proiectul este distribuit sub licența **GNU General Public License v2** — vezi fișierul [LICENSE](LICENSE).