# Specyfikacja: tablica RLCD (Waveshare ESP32-S3-RLCD-4.2)

Dokument opisuje kod w `server/`, `firmware/`, `hook/` i `tools/`. Gdy opis różni się od kodu, rozstrzygają kod i testy. Wdrożenie krok po kroku opisuje [INSTRUKCJA.md](INSTRUKCJA.md).

## Założenia

- Serwer działa na zwykłym hostingu PHP. Nie ma bazy SQL: stan leży w plikach JSON, a pliki trafiają na serwer przez FTP.
- Płytka nie łączy się z komputerem. Pracuje w osobnej sieci Wi-Fi dla urządzeń IoT (2,4 GHz, z izolacją klientów) i sama łączy się przez HTTPS z serwerem w internecie. USB służy tylko do wgrania programu.
- Płytka ma trzy tryby pracy: nasłuch, czyli pytanie serwera w stałym odstępie (domyślnie co 10 s; w aplikacji można wpisać własny, od 5 s do 1 h), synchronizacja na żądanie przyciskiem i praca offline.
- Wiadomości może wysyłać kilka osób, każda z własnym tokenem. Płytka pokazuje, kto wysłał wiadomość.
- Do wiadomości można dołączyć dźwięk: wbudowany ton albo dźwięk z biblioteki na serwerze. Bibliotekę uzupełnia się plikami WAV albo linkami z myinstants.com.
- Zestaw widoków da się rozszerzać. Nowy widok potrzebuje nowego id w firmware i w serwerze.
- Płytka może powiadamiać o pracy sesji Claude Code w trzech trybach: Cichy, Gotowy na kolejne zadania i Prymus, tylko na widoku Sesje Claude albo na każdym widoku. Powiadomienia „Gotowe” i „Czeka na Ciebie” mają własny dźwięk i własną animację (sekcja „Powiadomienia o sesjach Claude”).
- Wpis `app_lock` w `config.php` może ograniczyć aplikację do kilku przeglądarek. Miejsca zajmują pierwsze przeglądarki, w których ktoś się zaloguje, a każda inna dostaje pod adresem aplikacji 404 (sekcja „Blokada urządzeń”).

## Elementy

| Element | Kod | Rola |
|---|---|---|
| Płytka | `firmware/` (C, ESP-IDF 5.5, czyli oficjalne narzędzia Espressif) | pokazuje widoki, gra dźwięki, pyta serwer o zmiany |
| Serwer | `server/api.php`, `server/sounds.php`, `server/gate.php`, `server/applock.php` (PHP 8.1+) | przechowuje stan, wiadomości, tapetę, dźwięki i pogodę; wydaje pliki aplikacji i pilnuje blokady urządzeń |
| Hook | `hook/rlcd-hook.sh` (sh i curl) | zgłasza serwerowi stan sesji Claude Code z Maca |
| Konwerter tapet | `tools/img2rlcd.py` (Python 3 i `sips` z macOS) | zamienia obraz na tapetę 400×300 |
| Aplikacja PWA | `server/index.html`, `server/app.js`, `server/lib/` (HTML, CSS, JS bez bibliotek) | wysyła wiadomości, tapety i dźwięki oraz wybiera widok; sekcja „Aplikacja PWA” |
| Inni klienci | curl | to samo co aplikacja, z wiersza poleceń |

### Sprzęt płytki

- Moduł ESP32-S3-WROOM-1-N16R8: 16 MB pamięci flash i 8 MB PSRAM, czyli dodatkowej pamięci RAM. W PSRAM siedzą tapeta i pobrany dźwięk.
- Ekran RLCD 400×300 ze sterownikiem ST7305. RLCD to odblaskowy LCD: jest czytelny w świetle dziennym bez podświetlenia i ma dwa kolory, czarny i biały.
- Czujnik SHTC3 mierzy temperaturę i wilgotność w pokoju.
- Zegar RTC PCF85063 to układ z własnym zasilaniem, który trzyma czas po wyłączeniu płytki. Przechowuje czas uniwersalny UTC.
- Kodek audio ES8311 z głośnikiem.
- Pomiar napięcia baterii.
- Program obsługuje przyciski KEY i BOOT. Trzeci przycisk (PWR) działa sprzętowo i program go nie używa. Kod producenta też obsługuje tylko KEY i BOOT.
- NVS to część pamięci flash na pary klucz–wartość, która przetrwa restart i odłączenie zasilania. Płytka trzyma w niej ustawienia i ostatnie dane.

### Pliki danych na serwerze

Pliki leżą w katalogu `data_dir` z `config.php`, domyślnie `server/data/`.

| Plik | Zawartość |
|---|---|
| `state.json` | numer wersji stanu, widok, wiadomość, sesje, ustawienia powiadomień i liczniki zdarzeń sesji, biblioteka dźwięków, ostatni raport płytki |
| `wallpaper.bin` | tapeta, 15000 B |
| `sounds/<id>.wav` | dźwięki z biblioteki |
| `weather.json` | ostatni odczyt pogody z godziną pobrania |
| `devices.json` | miejsca blokady urządzeń: skrót SHA-256 ciasteczka, imię i data zajęcia; tylko przy włączonej blokadzie (sekcja „Blokada urządzeń”) |
| `.lock` | plik blokady, który ustawia zapisy w kolejce |

## Tryby pracy

| Tryb | Wi-Fi | Kiedy płytka pyta serwer |
|---|---|---|
| NASŁUCH | stale włączone | co `settings.poll_s` (domyślnie 10 s albo własny odstęp: pełne sekundy od 5 do 3600) i od razu po krótkim BOOT |
| NA ŻĄDANIE | wyłączone | tylko po krótkim BOOT |
| OFFLINE | wyłączone | nigdy; ekran pokazuje ostatnie zapisane dane, a płytka po restarcie bierze czas z RTC |

- **NASŁUCH.** Po wejściu w ten tryb płytka daje Wi-Fi 20 s na połączenie, zanim zgłosi błąd. Potem pyta serwer w odstępie `settings.poll_s` z ostatniego `poll`. Nowy odstęp działa od następnego zaplanowanego pytania. Nieudana synchronizacja dopisuje w pasku górnym „· błąd sieci”, a ekran stanu pokazuje powód. Udana synchronizacja czyści błąd.
- **NA ŻĄDANIE.** Krótki BOOT pokazuje toast „Łączę z serwerem...” (toast to krótki komunikat w ramce u dołu ekranu, znika po 4 s). Potem płytka włącza Wi-Fi, czeka na nie do 15 s, synchronizuje się, wyłącza Wi-Fi i pokazuje wynik.
- **OFFLINE.** Krótki BOOT pokazuje „Tryb offline: sieć wyłączona”.
- **Bez konfiguracji.** Gdy w `secrets.h` brakuje nazwy sieci, hasła, adresu API albo tokenu, Wi-Fi nie startuje w żadnym trybie. Pulpit pokazuje „Uzupełnij firmware/main/secrets.h, by włączyć sieć”, a krótki BOOT „Sieć nieskonfigurowana: secrets.h”.
- **Po restarcie.** Z NVS wracają tryb, widok, licznik ustawień widoku `view_seq`, ostatnia wiadomość, ostatni odczyt pogody, tapeta z numerem wersji oraz ustawienia: język i odstęp nasłuchu. Pierwsza synchronizacja wysyła `rev=-1`, więc serwer odsyła wszystko. Listę sesji Claude płytka trzyma tylko w RAM.

Po krótkim BOOT płytka pokazuje wynik synchronizacji:

- „Bez zmian”, gdy serwer nie ma nic nowego;
- „Zaktualizowano”, gdy przyszły zmiany;
- powód błędu, np. „Wi-Fi: złe hasło?”, albo „Błąd synchronizacji”, gdy powód jest nieznany.

Automatyczna synchronizacja w trybie NASŁUCH nie pokazuje wyniku. Wyjątkiem jest dźwięk wiadomości, którego nie udało się pobrać.

### Komunikaty błędów płytki

Płytka pokazuje je na ekranie stanu i w toaście po BOOT, w swoim języku (niżej wersja polska; angielska ma te same treści, np. „Wi-Fi: wrong password?”).

| Komunikat | Przyczyna |
|---|---|
| Wi-Fi: nie widzę sieci | sieć poza zasięgiem, zła nazwa albo niezgodne zabezpieczenia |
| Wi-Fi: złe hasło? | sieć odrzuciła uwierzytelnienie |
| Wi-Fi: brak połączenia | inny błąd Wi-Fi |
| brak połączenia z serwerem | błąd DNS (zamiany nazwy serwera na adres), błąd TLS (szyfrowania HTTPS) albo brak odpowiedzi w 8 s |
| serwer: zły token urządzenia | HTTP 401 |
| serwer: zły adres API_URL | HTTP 404 |
| serwer: błąd N | inny kod HTTP |
| zła odpowiedź serwera | odpowiedź nie jest poprawną odpowiedzią `poll` |
| zła odpowiedź serwera (za duża) | odpowiedź `poll` ma ponad 8 KB |
| tapeta: zły rozmiar pliku | plik tapety ma inny rozmiar niż 15000 B |
| tapeta: brak pamięci | zabrakło miejsca w PSRAM |
| dźwięk: nie ma go w bibliotece | ktoś usunął dźwięk po wysłaniu wiadomości |
| dźwięk: zły format pliku | plik nie jest WAV-em w formacie płytki |
| dźwięk: brak pamięci | zabrakło miejsca w PSRAM |

## Przyciski

| Przycisk | Krótkie naciśnięcie | Przytrzymanie 2 s |
|---|---|---|
| KEY | następny tryb: NASŁUCH → NA ŻĄDANIE → OFFLINE → NASŁUCH; toast „Tryb: …”; czyści błąd sieci | otwiera ekran stanu; drugie przytrzymanie go zamyka |
| BOOT | synchronizacja teraz, zależnie od trybu | następny widok; toast „Widok: …”; zamyka ekran stanu |
| PWR (środkowy) | sprzętowo: włącza płytkę; program go nie używa | sprzętowo: wyłącza płytkę |

Gdy płytka pokazuje powiadomienie o sesjach Claude, pierwsze naciśnięcie KEY albo BOOT, krótkie czy przytrzymane, tylko je zamyka (sekcja „Zachowanie płytki”, podsekcja „Powiadomienia o sesjach”).

Ekran „Stan urządzenia” pokazuje:

- tryb i widok; w trybie NASŁUCH także odstęp, np. „Tryb: NASŁUCH, co 30 s”;
- stan Wi-Fi: nieskonfigurowane, połączone, łączę się albo wyłączone;
- baterię w procentach i woltach albo „Zasilanie: USB, bez akumulatora”;
- godzinę ostatniej synchronizacji albo „jeszcze nie było”;
- ostatni błąd.

Na dole ma ściągę przycisków. Zamyka się sam po 10 s.

## Widoki

| id | Nazwa na płytce | Zawartość |
|---|---|---|
| `dashboard` | Pulpit | zegar, data, klimat w pokoju, pogoda, podsumowanie sesji Claude |
| `message` | Wiadomość | nadawca, godzina wysłania i tekst ostatniej wiadomości |
| `wallpaper` | Tapeta | obraz 400×300 na cały ekran, bez paska górnego |
| `sessions` | Sesje Claude | do 8 sesji Claude Code ze stanem, jego wiekiem i postępem zadań |

Przytrzymanie BOOT przełącza widoki po kolei: Pulpit → Wiadomość → Tapeta → Sesje Claude → Pulpit.

Pasek górny jest w każdym widoku oprócz tapety. Z lewej pokazuje tryb, a po nieudanej synchronizacji także „· błąd sieci” (poza trybem OFFLINE). Z prawej pokazuje godzinę i poziom baterii w procentach, a bez akumulatora napis „USB”.

Płytka nie wykrywa akumulatora sama. Bez akumulatora ładowarka trzyma na jego złączu ok. 4,1 V, więc odczyt wygląda jak pełny akumulator. Dlatego o tym decyduje `BATTERY_FITTED` w `firmware/main/board.h`: przy 0 płytka nie mierzy baterii, pokazuje „USB” i zgłasza serwerowi `bat=0`, a przy 1 mierzy napięcie.

### Pasek limitu 5 h

Na dole widoku Sesje Claude leży pasek o wysokości 14 px (od y = 285). Pokazuje limit planu Claude w oknie 5 h: obramowanie, wypełnienie proporcjonalne do zużycia i tekst w środku (rysowany XOR, więc czytelny na czarnym i na białym). Tekst: „5 h 63% · reset za 1 h 20 min · odczyt sprzed 2 min” (po angielsku „5 h 63% · resets in 1 h 20 min · read 2 min ago”).

Pasek jest fail-closed (reguły po stronie serwera: sekcja „API serwera”, podsekcja „Limity”):

- Bez odczytu paska nie ma. Nie ma go też bez znanego czasu, gdy odczyt jest starszy niż okno 5 h albo z przyszłości.
- Po `reset` okna tekst brzmi „5 h odnowiony · odczyt sprzed N min”, bez procentu i z pustym wypełnieniem.
- Wiek odczytu jest zawsze w tekście.
- Gdy ostatnia tura zatrzymała się na limicie (`hit`), pasek jest pełny i mówi „5 h limit wyczerpany” z czasem do resetu, jeśli odczyt go zna.
- Płytka liczy czas do resetu i wiek odczytu sama z czasów bezwzględnych (`usage.five.left` i `age` zamienione w czasie serwera z `poll`), więc pasek żyje między synchronizacjami; odświeża się co minutę z zegarem.

Pasek zabrał miejsce: dolny wiersz pulpitu stoi o 12 px wyżej, wiersze sesji mają 27 px, a tekst wiadomości kończy się 4 px nad paskiem. Ekran stanu ma stopkę wyżej, a komunikat chwilowy (toast) leży nad paskiem.

### Który widok jest aktywny

- Serwer liczy każde ustawienie widoku w polu `view_seq`, także ponowne ustawienie tego samego widoku. Widok ustawiają `POST view`, nowa wiadomość (widok `message`) i nowa tapeta (widok `wallpaper`).
- Płytka przyjmuje widok z serwera tylko wtedy, gdy `view_seq` różni się od zapisanego u niej. Widok zmieniony przyciskiem zostaje więc do następnego ustawienia widoku na serwerze.
- Nieznane id widoku, np. z nowszego serwera, płytka pomija.
- Zmiana widoku z serwera zamyka ekran stanu i powiadomienie o sesjach.

### Pulpit

- Duży zegar „HH:MM” albo „--:--”, dopóki płytka nie zna czasu. Zegar uznaje za ustawiony od roku 2025.
- Data, np. „piątek, 6 listopada”, albo „czekam na czas z sieci”.
- „W pokoju”: temperatura z czujnika („--” przy błędzie czujnika) i wilgotność.
- Pogoda w prawej kolumnie zależy od wieku odczytu:
  - odczyt do 3 h ma nagłówek „Na zewnątrz · <miejsce>”;
  - starszy ma nagłówek „Pogoda z HH:MM”, a z innego dnia „Pogoda z 5.11, 18:30”; postać z datą jest też wtedy, gdy płytka nie zna czasu;
  - pod nagłówkiem: temperatura, opis z kodu WMO (wyjaśniony w sekcji „Pogoda”) oraz „od X do Y°C, opady Z%”;
  - odczytu starszego niż doba płytka nie pokazuje: wyświetla „Pogoda”, „brak danych” i „ostatni odczyt ponad dobę temu”;
  - gdy po udanej synchronizacji nie było żadnego odczytu, płytka wyświetla „brak danych” i podpowiedź „sprawdź location w config.php”.
- Dolny wiersz, nad paskiem limitu: „Claude: brak aktywnych sesji” albo „Claude: N pracuje · N czeka · N gotowe”. Na płytce bez konfiguracji jest tu podpowiedź o `secrets.h`.

### Wiadomość

U góry jest „Od: <nadawca>” i godzina wysłania. Tekst zawija się w największej czcionce, która go mieści. Kolejno próbowane są czcionki biblioteki grafiki u8g2: helvB24, helvB18, helvB14 (pogrubione), potem helvR12 i helvR10 (zwykłe). W najmniejszej nadmiar tekstu zostaje ucięty. Czcionki mają polskie litery, ° i ·, ale nie mają emoji, — ani …, więc płytka takich znaków nie narysuje. Bez wiadomości widok pokazuje „Brak wiadomości”.

### Tapeta

Tapeta zajmuje cały ekran, bez paska. Bez tapety widok pokazuje „Brak tapety” i „Wgraj ją z aplikacji”.

Obraz tapety płytka pokazuje tak jak podgląd w aplikacji: czarne piksele na białym tle. Ekran ma włączone odwrócenie kolorów (komenda `0x21` w `firmware/components/u8g2_st7305/u8g2_st7305.c`), więc pozostałe widoki, także „Brak tapety”, są jasne na ciemnym tle. Dlatego płytka rysuje tapetę odwrotnie niż resztę ekranu: najpierw cały ekran jasny, potem ciemne piksele tam, gdzie tapeta ma bit 1 (sekcja „Format tapety i konwerter”).

Rysowanie tapety sprawdza test na Macu, z katalogu repozytorium: `cc -std=c11 -Wall -Wextra -Werror -pedantic -I firmware/components/u8g2/csrc -o <plik> firmware/tests/wallpaper_test.c`, potem uruchomienie `<plik>`. Test rysuje tapety syntetyczne i sprawdza każdy piksel: czarny piksel tapety ma wyjść na ekranie ciemny, a biały jasny. Jak ekran pokazuje kolory, test odczytuje z komendy odwrócenia w sterowniku ekranu. Po zmianie `0x21` na `0x20` (odwrócenie wyłączone) test nie przejdzie, bo wtedy tapetę trzeba rysować bez odwracania.

### Sesje Claude

Pod nagłówkiem „Sesje Claude Code” jest do 8 wierszy, po jednym na sesję. Każdy ma z lewej tytuł sesji taki jak w aplikacji Claude, a gdy tytułu brak, alias projektu. Z prawej jest krótki stan („pracuje”, „czeka” albo „gotowe”) i jego wiek, a gdy sesja ma listę zadań, na początku „2/5” (zrobione z wszystkich), np. „2/5 · pracuje · 3 min”. Listy nie ma, gdy hook jej nie zgłosił: płytka nie pokazuje wtedy „0/0”. Wiek to „teraz” poniżej minuty, potem „N min”, a od godziny „N h”. Tytuł szerszy niż miejsce przed stanem płytka przycina na granicy znaku i kończy wielokropkiem; czcionki helv z polskimi literami nie mają znaku „…”, więc są to trzy kropki. Wiersz „czeka” jest odwrócony, czyli biały tekst na czarnym pasku, więc sesję czekającą na Ciebie widać z daleka. Pusta lista pokazuje „Brak aktywnych sesji” albo „Sieć nieskonfigurowana”. W prawym dolnym rogu, tuż nad paskiem limitu, gra mała animacja stanu listy (sekcja „Zachowanie płytki”, podsekcja „Powiadomienia o sesjach”).

### Nowy widok

1. Dodaj id do `app_view_t` w `firmware/main/app.h`.
2. Dodaj wpis z funkcją rysującą do `VIEWS` w `firmware/main/views.c`.
3. Dodaj to samo id do `VIEWS` w `server/api.php`.

Starsza płytka pominie nieznane id.

## API serwera

Adres: `https://<host>/<katalog>/api.php?a=<akcja>`. Każde zapytanie niesie token w nagłówku `X-Token`. Nagłówek `Authorization` odpada, bo niektóre serwery WWW (np. LiteSpeed na hostingu współdzielonym) potrafią go wyciąć.

### Role i tokeny

| Rola | Token w `config.php` | Kto używa |
|---|---|---|
| `device` | `tokens.device` | płytka |
| `hook` | `tokens.hook` | hook Claude Code |
| `user` | `users`: imię i token każdej osoby | curl, klient w przeglądarce |

- Każdy token ma co najmniej 24 znaki. Krótszy token daje błąd 500 przy każdym zapytaniu.
- Serwer porównuje tokeny funkcją `hash_equals`, w stałym czasie: czas odpowiedzi nie zdradza, ile znaków się zgadza.
- Imię osoby (do 20 znaków) trafia do pola `from` wiadomości i pola `by` dźwięku.
- Serwer czyta konfigurację z pliku ze zmiennej środowiskowej `RLCD_CONFIG`, a bez niej z `config.php` obok `api.php`.
- Przy włączonej blokadzie urządzeń przeglądarka potrzebuje oprócz tokenu osoby miejsca w `devices.json` (sekcja „Blokada urządzeń”). Płytki, hooka i curl to nie dotyczy.

### Akcje

| Metoda i akcja | Rola | Wejście | Odpowiedź |
|---|---|---|---|
| `GET poll` | device | `rev`, `mode` (`listen`, `ondemand` albo `offline`), `bat` (mV, 0–6000) | stan do wyświetlenia, niżej |
| `GET wallpaper` | device, user | — | 15000 B, `application/octet-stream` |
| `GET sound` | device, user | `id`, np. `s12` | plik WAV, `audio/wav` |
| `GET me` | user | — | `{"ok":true,"name":"Ja"}` |
| `GET state` | user | — | pełny stan dla klienta, niżej |
| `POST message` | user | JSON `{"text":"Obiad gotowy","sound":"dinner"}` | `{"ok":true,"rev":2,"id":1}` |
| `POST view` | user | JSON `{"view":"sessions"}` | `{"ok":true,"rev":3}` |
| `POST settings` | user | JSON `{"lang":"pl","poll_s":45}`; każde pole opcjonalne, ale co najmniej jedno; `"poll_s":null` wraca do odstępu domyślnego (podsekcja „Ustawienia płytki”) | `{"ok":true,"rev":7,"settings":{"lang":"pl","poll_s":45,"poll_default":10}}` |
| `POST wallpaper` | user | surowe 15000 B tapety | `{"ok":true,"rev":4,"wallpaper_rev":1}` |
| `POST session` | hook | JSON `{"alias":"blog","sid":"9829fa3f","title":"Nowy wpis","state":"working","turns":3,"agents":1,"kind":"question","tasks":{"d":2,"t":5}}`; `sid`, `title`, `turns`, `agents`, `kind` i `tasks` są opcjonalne (podsekcja „Sesje”) | `{"ok":true,"rev":5}` |
| `POST usage` | hook | JSON `{"five":{"p":63,"r":1793996400},"week":{"p":21,"r":1794250000},"sid":"9829fa3f","model":"Opus 5.5","ctx":41}` albo `{"hit":true}`; podsekcja „Limity” | `{"ok":true}` |
| `POST notify` | user | JSON `{"mode":"ready","scope":"all","done":{"sound":"bell","anim":"happy"},"wait":{"sound":"s1","anim":"away"}}`; wszystkie pola są wymagane | `{"ok":true,"rev":7}` |
| `POST sound` | user | `name` w adresie, plik WAV w treści | wpis biblioteki z `"ok":true` |
| `POST sound_delete` | user | JSON `{"id":"s1"}` | `{"ok":true}` |
| `GET sound_source` | user | `url`, czyli link z myinstants.com | plik MP3, `audio/mpeg` |

### Zasady wspólne

- Każda odpowiedź ma nagłówki `Cache-Control: no-store` i `X-Content-Type-Options: nosniff`.
- JSON wychodzi jako `application/json; charset=utf-8`. Polskie litery zostają literami, bez kodów `\u`.
- Błąd ma postać `{"ok":false,"error":"<opis po angielsku>"}` z kodem HTTP. Odpowiedzi `poll` i `state` nie mają pola `ok`.
- Treść JSON ma najwyżej 4096 B i musi być obiektem.
- Serwer nie wysyła nagłówków CORS, czyli zgody na zapytania ze stron w innej domenie. Klient w przeglądarce musi więc działać na tym samym hoście co `api.php`.
- Każdy zapis bierze wyłączną blokadę `flock` na `data/.lock`, pisze do pliku tymczasowego i podmienia plik przez `rename`. Nikt nie przeczyta połowy pliku.
- Uszkodzony `state.json` daje błąd 500 „state.json is corrupt”. Serwer go nie nadpisuje.

### Numer wersji `rev`

- `rev` startuje od 1.
- Rośnie przy nowej wiadomości, nowej tapecie, ustawieniu widoku, zapisie ustawień powiadomień (`POST notify`) i zmianie ustawień płytki (`POST settings` z tymi samymi wartościami nie podbija `rev`).
- Przy sesjach rośnie tylko wtedy, gdy zmieni się lista sesji (wiersz, jego alias, tytuł, stan albo kolejność) albo licznik zdarzeń `ev`.
- Nie rośnie przy `poll` ani przy dodaniu czy usunięciu dźwięku.
- Płytka wysyła swój `rev`. Gdy różni się od numeru na serwerze, odpowiedź ma `"changed":true` i całą treść.

### Odpowiedź `poll`

```json
{"rev":6,"time":1793991502,"changed":true,
 "weather":{"place":"Dom","temp":7,"code":3,"max":9,"min":2,"rain":40,"at":1793990862},
 "view":"message","view_seq":4,
 "message":{"id":2,"text":"Kawa?","from":"Ja","sound":"s1","ts":1793991462},
 "wallpaper_rev":1,"sessions":[{"id":"s:9829fa3f","alias":"blog","title":"Nowy wpis","state":"working","turns":3,"agents":1,
   "tasks":{"d":2,"t":5},"model":"Opus 5.5","ctx":41,"turn_age":200,"age":40}],
 "settings":{"lang":"pl","poll_s":30,"poll_default":10},
 "usage":{"five":{"p":63,"r":1793996400,"left":4898,"expired":false,"age":120},"hit":false},
 "notify":{"mode":"ready","scope":"all","done":{"sound":"bell","anim":"happy"},"wait":{"sound":"s1","anim":"away"}},
 "ev":{"done":12,"done_at":1793991462,"done_id":"s:9829fa3f","all":5,"all_at":1793991462,
       "wait":7,"wait_at":1793991000,"wait_id":"s:9829fa3f"}}
```

- Przy `"changed":false` odpowiedź ma tylko `rev`, `time`, `changed`, `weather`, `settings` i `usage`. Ustawienia płytki i limit idą w każdej odpowiedzi, więc płytka przyjmuje je przy najbliższym kontakcie. `usage` w `poll` ma tylko okno `five` i `hit`; zmiana limitu nie podnosi `rev`.
- `notify` to ustawienia powiadomień, a `ev` liczniki zdarzeń sesji (podsekcja „Powiadomienia”).
- `time` to czas uniksowy serwera, czyli sekundy od 1970 roku.
- `age` sesji to sekundy od jej ostatniej zmiany.
- Wiersz sesji ma też, gdy hook je zgłosił: `turns`, `agents`, `kind`, `tasks`, `model`, `ctx` i, przy pracującej sesji, `turn_age` (sekundy od początku tury); podsekcja „Sesje”.
- `id` sesji to klucz wiersza: `s:` i `sid` albo, dla zgłoszenia bez `sid`, sam alias. `title` to tytuł sesji albo `""`.
- `poll` zapisuje też raport płytki: czas ostatniego kontaktu, tryb i napięcie baterii (`bat_mv` równe 0 znaczy zasilanie z USB bez akumulatora). Klient widzi go w polu `device` odpowiedzi `state`.

### Odpowiedź `state`

```json
{"rev":6,"view":"message","view_seq":4,
 "message":{"id":2,"text":"Kawa?","from":"Ja","sound":"s1","ts":1793991462},
 "wallpaper_rev":1,
 "sessions":[{"id":"s:9829fa3f","alias":"blog","title":"Nowy wpis","state":"working","turns":3,"agents":1,
   "tasks":{"d":2,"t":5},"model":"Opus 5.5","ctx":41,"turn_age":200,"age":40}],
 "settings":{"lang":"pl","poll_s":30,"poll_default":10},
 "usage":{"five":{"p":63,"r":1793996400,"left":4898,"expired":false,"age":120},
          "week":{"p":21,"r":1794250000,"left":257000,"expired":false,"age":120},"hit":false},
 "device":{"last_seen":1793991502,"mode":"listen","bat_mv":4012},
 "notify":{"mode":"ready","scope":"all","done":{"sound":"bell","anim":"happy"},"wait":{"sound":"s1","anim":"away"}},
 "ev":{"done":12,"done_at":1793991462,"done_id":"s:9829fa3f","all":5,"all_at":1793991462,
       "wait":7,"wait_at":1793991000,"wait_id":"s:9829fa3f"},
 "sounds":{"builtin":["none","chime","bell","dinner","alert"],
           "library":[{"id":"s1","name":"Bajo jajo","ms":1650,"bytes":52846,"by":"Ja","ts":1793991462}]}}
```

`usage` zawsze ma `hit`, a okna `five` i `week` tylko wtedy, gdy odczyt jest świeży (podsekcja „Limity”). `device.mode` i `device.bat_mv` mają wartość `null`, dopóki płytka się nie zgłosi. Pola `notify` i `ev` są takie same jak w `poll`.

### Kody błędów

| Kod | Komunikaty |
|---|---|
| 400 | `text must be a UTF-8 string`; `text must have 1-280 chars`; `sound must be a built-in tone (none, chime, bell, dinner, alert) or an id from the sound library`; `wallpaper must be exactly 15000 bytes`; `view must be one of: dashboard, message, wallpaper, sessions`; `lang must be one of: en, pl`; `poll_s must be null (default) or whole seconds from 5 to 3600`; `settings need lang or poll_s`; `alias must match [a-z0-9-]{1,24}`; `sid must match [a-z0-9]{8}`; `state must be one of: working, waiting, done, end`; `name must have 1-24 chars`; `id must look like s12`; `url must be a myinstants.com instant page or a /media/sounds/*.mp3 link`; `mode must be one of: quiet, ready, each`; `scope must be one of: sessions, all`; `done must be an object`; `done.sound must be a built-in tone (none, chime, bell, dinner, alert) or an id from the sound library`; `done.anim must be one of: away, alert, happy, typing, idle, sleep` (trzy ostatnie tak samo dla `wait`); `body must be a JSON object` |
| 400 (WAV) | `not a WAV file`; `WAV fmt chunk is broken`; `WAV data chunk comes before fmt`; `WAV data chunk is truncated`; `WAV needs a fmt and a data chunk`; `WAV must be PCM 16-bit mono 16000 Hz, got …`; `WAV has no whole 16-bit samples` |
| 401 | `bad token` (brak tokenu albo token innej roli) |
| 404 | `unknown action`; `no wallpaper`; `no such sound`; `app_lock: all N device places are taken by other browsers` (przeglądarka bez miejsca, sekcja „Blokada urządzeń”) |
| 409 | `sound library is full (50): delete a sound first` |
| 413 | `body too large` (JSON ponad 4096 B); `file larger than 600000 bytes`; `sound is longer than 15 s` |
| 500 | `config.php missing: copy config.example.php and fill it in`; `token 'device' missing or shorter than 24 chars` (tak samo dla `hook`); `config needs at least one entry in users`; `each user needs a name and a token of 24+ chars`; `app_lock must be ['devices' => N] with N a whole number (0 = off)`; `state.json is corrupt`; `devices.json is corrupt: fix it or delete it to free every place` (tylko dla przeglądarek); `cannot create data dir`; `cannot create sounds dir`; `cannot lock state`; `write failed` |
| 501 | `cURL is not enabled on this hosting: download the sound and upload the file instead` |
| 502 | `myinstants did not answer: …`; `myinstants answered HTTP N`; `myinstants refused the request (HTTP 403): download the sound and upload the file instead` (tak samo dla 429); `myinstants redirected to another site`; `too many redirects from myinstants`; `myinstants file is larger than N bytes`; `no sound found on that page`; `myinstants did not return an MP3 file` |

### Wiadomość

- `text` to tekst UTF-8. Serwer usuwa znaki sterujące oprócz nowej linii, obcina spacje z brzegów i wymaga od 1 do 280 znaków.
- `sound` to `none` (domyślnie), wbudowany ton (`chime`, `bell`, `dinner`, `alert`) albo id z biblioteki. Serwer sprawdza id pod blokadą, bo ktoś mógł właśnie usunąć ten dźwięk.
- `from` to imię nadawcy z `config.php`, `ts` to czas serwera, a `id` rośnie o 1.
- Nowa wiadomość ustawia widok `message`. Serwer trzyma tylko ostatnią wiadomość.

### Ustawienia płytki

- `POST settings` zmienia ustawienia wspólne dla całej płytki. Każde podane pole musi być poprawne, inaczej serwer nic nie zapisuje.
- `lang` to `en` albo `pl`.
- `poll_s` to `null`, czyli odstęp domyślny, albo własny odstęp: liczba całkowita JSON od 5 do 3600 (sekundy). Serwer odrzuca `"30"`, `30.0`, `1e3` i `true`. Wpisane 10 zapisuje jako `null`, bo to ten sam odstęp co domyślny.
- Zapis bez zmiany wartości, np. drugi raz to samo albo 10 przy odstępie domyślnym, nie podbija `rev`.
- W odpowiedziach `poll`, `state` i `POST settings` pole `settings.poll_s` to odstęp, który obowiązuje: własny albo domyślny. `settings.poll_default` to odstęp domyślny, który aplikacja pokazuje przy opcji „Domyślne”. Własny odstęp to `poll_s` różne od `poll_default`. Płytka czyta tylko `lang` i `poll_s`.
- Granice 5 i 3600 (`POLL_MIN_S`, `POLL_MAX_S`) są w `server/api.php`, `firmware/main/app.h` i `server/lib/api.js`. Odstęp domyślny 10 s (`POLL_DEFAULT_S`) jest w dwóch pierwszych plikach, a aplikacja bierze go z `poll_default`. Test aplikacji sprawdza, że te liczby są wszędzie równe.
- `state.json` z odstępem spoza granic albo z wartością, która nie jest liczbą całkowitą, daje odstęp domyślny. Odstęp zapisany przed własnymi odstępami (30, 60, 300 albo 900 s) zostaje jako własny.
- **Dlaczego od 5 s do 1 h.** Każde pytanie płytki to połączenie HTTPS i proces PHP z blokadą `state.json`, więc odstęp krótszy niż 5 s niepotrzebnie obciąża zwykły hosting. Odstęp dłuższy niż godzina to w praktyce tryb NA ŻĄDANIE, który płytka już ma. Następne pytanie płytka planuje dopiero po skończonej synchronizacji, więc pytania się nie nakładają, nawet gdy synchronizacja trwa dłużej niż odstęp.
- Program płytki sprzed własnych odstępów (194df24, wersja z językiem płytki) zna tylko 10, 30, 60, 300 i 900 s. Inną wartość pomija i zostaje przy poprzednim odstępie, dopóki nie dostanie nowego programu.

### Sesje

- `alias` ma od 1 do 24 znaków `a-z`, `0-9` i `-`.
- `sid` (opcjonalny) to 8 znaków `a-z0-9`: początek identyfikatora sesji Claude Code bez myślników. Z `sid` każda sesja ma własny wiersz, także kilka sesji w jednym folderze. Bez `sid` wierszem jest projekt (alias), jak w starszym hooku. Pierwsze zgłoszenie z `sid` usuwa wiersz projektu o tym samym aliasie, zapisany bez `sid`.
- `title` (opcjonalny) to tytuł sesji z aplikacji Claude. Serwer zamienia znaki sterujące, znaki formatu i podziały wierszy na spacje, scala białe znaki, obcina do 60 znaków (nie bajtów) i przycina spacje z brzegów. Tytuł, który nie jest napisem, zapisuje jako `""`. Treść z nieprawidłowym UTF-8 nie jest poprawnym JSON, więc dostaje 400.
- `state` to `working`, `waiting`, `done` albo `end`. `end` usuwa wiersz z listy.
- Każde zgłoszenie zapisuje stan i tytuł z bieżącym czasem. Hook wysyła tylko zmiany stanu albo tytułu, więc `age` to czas od ostatniej takiej zmiany.
- Serwer trzyma najwyżej 8 najświeższych sesji. Sesje bez zgłoszenia od 12 h znikają, np. po zamkniętym terminalu, który nie zgłosił końca.
- Liczby i rodzaje (wszystkie opcjonalne, fail-closed: pole, którego hook nie wysłał, nie trafia do wiersza, bez „0/0” i bez zgadywania):
  - `turns`: liczba tur sesji, całkowita od 0 do 100000. Serwer stempluje własnym zegarem początek tury, gdy liczba rośnie. Wiersz pracującej sesji ma wtedy `turn_age`, czyli sekundy od początku tury. `turn_age` nie liczy się do zmiany `rev`, bo rośnie co sekundę;
  - `agents`: ile subagentów pracuje, 0–99;
  - `kind`: na co czeka sesja `waiting`: `question` (AskUserQuestion), `plan` (ExitPlanMode) albo `permission` (prośba o zgodę);
  - `tasks`: `{"d":2,"t":5}`, czyli zrobione z wszystkich zadań listy: liczby całkowite, `1 ≤ t ≤ 999`, `0 ≤ d ≤ t`. Inne pola w `tasks` serwer pomija. Nazw kroków nie ma, bo to treść;
  - `model` i `ctx` (zapełnienie okna kontekstu w %, 0–100) przychodzą z paska stanu przez `POST usage` z `sid` i trafiają do istniejącego wiersza, który zgłoszenie sesji zachowuje. `model` ma 1–32 znaków `A-Za-z0-9 ._-`. Wiersza z samego `POST usage` serwer nie tworzy.
  - Pole o złym typie albo spoza zakresu dostaje 400 z całym zgłoszeniem. Zgłoszenie bez tych pól usuwa liczby z wiersza (poza `model` i `ctx`), więc `tasks` znika, gdy hook przestaje je wysyłać.
  - Każda zmiana tych liczb podnosi `rev`, a powtórzenie tych samych nie.

### Limity

`POST usage` (rola hook) zapisuje limity planu jednego konta, wspólne dla wszystkich sesji. Pola są opcjonalne, ale co najmniej jedno musi być:

- `five` i `week`: `{"p":63,"r":1793996400}`, czyli zużycie w procentach (0–100, serwer zaokrągla do całych) i czas resetu w sekundach uniksowych. `r` może być najwyżej 1 dobę w przeszłości i najwyżej o długość okna plus godzinę w przyszłości (5 h, 7 dni). Okna idą osobno i każde ma własny wiek, więc zgłoszenie z jednym nie odświeża drugiego.
- `hit`: tylko `true`; hook `StopFailure` z `rate_limit` mówi tak, że tura zatrzymała się na limicie. Nowy odczyt okna `five` kasuje to powiadomienie.
- `sid`, `model`, `ctx`: model i zapełnienie kontekstu sesji (podsekcja „Sesje”); `model` i `ctx` wymagają `sid`.
- Czas odczytu nadaje zegar serwera. Najnowsze zgłoszenie wygrywa.

`GET state` i `GET poll` dają `usage` przetworzone fail-closed: okno starsze niż jego długość (5 h, 7 dni) znika z odpowiedzi, a okno po resecie zostaje z `"expired":true`, żeby klient napisał „odnowiony” zamiast procentu. Każde okno ma `p`, `r`, `left` (sekundy do resetu wg zegara serwera, więc telefon z błędnym zegarem też pokaże dobry czas), `expired` i `age` (sekundy od odczytu). `hit` jest prawdą do pięciu godzin od zgłoszenia albo do nowego odczytu okna 5 h. `poll` dostaje tylko `five` i `hit`. Uszkodzony blok `usage` w `state.json` daje odpowiedź bez odczytu.

Limity przychodzą z paska stanu Claude Code (`rate_limits.five_hour` i `rate_limits.seven_day`; sekcja „Panel sesji Claude Code (hook)”), tylko z planów Pro, Max i Ultra i dopiero po pierwszej odpowiedzi sesji. Aplikacja Claude (desktop) nie uruchamia skryptu paska stanu, więc jej sesje limitów nie zgłaszają; liczy się odczyt z sesji w terminalu.

### Powiadomienia

Tryby, zasięg i znaczenie ustawień opisuje sekcja „Powiadomienia o sesjach Claude”.

- `POST notify` zapisuje ustawienia powiadomień, jedne dla całej płytki. Zmienić je może każda osoba z rolą `user`. Treść musi mieć wszystkie pola:
  - `mode`: `quiet` (Cichy), `ready` (Gotowy na kolejne zadania) albo `each` (Prymus);
  - `scope`: `sessions` (tylko widok Sesje Claude) albo `all` (każdy widok);
  - `done` („Gotowe”) i `wait` („Czeka na Ciebie”): obiekty z polami `sound` i `anim`. `sound` wybiera się jak w wiadomości: `none`, ton wbudowany albo id z biblioteki, które serwer sprawdza pod blokadą. `anim` to `away`, `alert`, `happy`, `typing`, `idle` albo `sleep`.
- Zapis podnosi `rev`, ale nie zmienia widoku ani `view_seq`. Płytka przyjmuje ustawienia przy następnej pełnej odpowiedzi `poll`.
- Ustawienia domyślne: `{"mode":"quiet","scope":"all","done":{"sound":"bell","anim":"happy"},"wait":{"sound":"chime","anim":"away"}}`.
- **Liczniki `ev`.** Płytka pyta serwer co `settings.poll_s` (od 5 s do 1 h), więc mogłaby przegapić krótki stan, np. koniec odpowiedzi, po którym od razu przyszło następne polecenie. Dlatego zdarzenia liczy serwer przy każdym `POST session`:
  - `done` rośnie o 1, gdy wiersz przechodzi z `working` albo `waiting` w `done`. Nowy wiersz, który od razu zgłasza `done`, np. z `rlcd-hook.sh check`, się nie liczy. Wiersz po 12 h bez zgłoszenia też liczy się jako nowy;
  - `all` rośnie o 1, gdy takie przejście w `done` zostawia listę bez aktywnych wierszy, czyli bez `working` i `waiting`. Zamknięcie sesji (`end`) i wygaśnięcie wiersza po 12 h się nie liczą, bo sesja nie skończyła zadania;
  - `wait` rośnie o 1, gdy wiersz przechodzi w `waiting` z innego stanu albo pojawia się od razu jako `waiting`;
  - przy każdym wzroście serwer zapisuje czas zdarzenia (`done_at`, `all_at`, `wait_at`), a przy `done` i `wait` także id wiersza (`done_id`, `wait_id`) w postaci `id` z listy sesji;
  - liczniki tylko rosną, a każdy wzrost podnosi `rev`.
- `state.json` bez pól `notify` i `ev`, np. sprzed powiadomień, daje ustawienia domyślne i zera. Brakujące pola wewnątrz `notify` i `ev` serwer też uzupełnia wartościami domyślnymi.

### Biblioteka dźwięków

- `POST sound?name=<nazwa>` przyjmuje w treści plik WAV PCM 16-bit, mono, 16 kHz. PCM to nieskompresowane próbki dźwięku.
- Plik ma najwyżej 600000 B, a dźwięk najwyżej 15 s.
- Serwer pomija dodatkowe bloki pliku i zapisuje go z prostym 44-bajtowym nagłówkiem.
- Nazwa ma od 1 do 24 znaków. Serwer zwija wielokrotne spacje i usuwa znaki sterujące.
- Id to litera `s` i kolejny numer. Numery nie wracają po usunięciu, więc stara wiadomość nigdy nie zagra nowszego dźwięku.
- Biblioteka mieści 50 dźwięków.
- Wpis ma pola `id`, `name`, `ms` (długość), `bytes` (rozmiar), `by` (kto dodał) i `ts` (kiedy).
- `POST sound_delete` usuwa wpis i plik. Gdy wiadomość wskazuje usunięty dźwięk, płytka gra ton `chime` i pokazuje „dźwięk: nie ma go w bibliotece”.
- `POST sound_delete` nie zmienia `notify`. Powiadomienie z usuniętym dźwiękiem płytka gra tonem `chime`. Ponowny zapis ustawień z tym id dostaje 400 `done.sound must be …` albo `wait.sound must be …`, więc aplikacja prosi o wybór innego dźwięku.
- Tony `chime`, `bell`, `dinner` i `alert` płytka generuje sama. `none` oznacza ciszę.

### Import z myinstants.com

- `GET sound_source?url=<link>` pobiera MP3 i odsyła je klientowi. Klient zamienia MP3 na WAV (sekcja „Format dźwięku”) i wgrywa go przez `POST sound`. Serwer nie zapisuje MP3.
- Serwer przyjmuje dwa rodzaje linków:
  - stronę dźwięku, np. `https://www.myinstants.com/en/instant/gadu-gadu-powiadomienie-84239/`, z kodem języka albo bez i z `www` albo bez;
  - bezpośredni link `/media/sounds/<plik>.mp3`.
- Parametry po `?` i `#` serwer pomija.
- Zasady pobierania:
  - tylko przez https i tylko z myinstants.com;
  - najwyżej 3 przekierowania, każde sprawdzone;
  - strona do 1 MB, MP3 do 2 MB;
  - 5 s na połączenie, 10 s na całość;
  - jedno zapytanie na jedno działanie użytkownika.
- User-Agent mówi wprost, kto pyta: `rlcd-board/1.0 (self-hosted; imports one sound per user request)`.
- Serwer sprawdza, czy plik to MP3. Plik musi zaczynać się znacznikiem ID3 (opisem utworu) albo nagłówkiem ramki MPEG, czyli początkiem bloku dźwięku.
- Import wymaga cURL, czyli modułu PHP do pobierania plików z innych stron. Gdy myinstants odmówi (HTTP 403 albo 429), trzeba pobrać plik samemu i wgrać go jako WAV.

### Pogoda

- Źródłem jest Open-Meteo, darmowe API pogodowe bez klucza (`api.open-meteo.com/v1/forecast`). Serwer pyta o współrzędne `location.lat` i `location.lon` z `config.php`.
- Serwer pobiera:
  - bieżącą temperaturę i kod pogody;
  - dzienne maksimum i minimum temperatury;
  - największe prawdopodobieństwo opadów w ciągu dnia.

  Pyta w strefie Europe/Warsaw z limitem 4 s.
- Kod pogody to kod WMO, czyli międzynarodowy numer rodzaju pogody Światowej Organizacji Meteorologicznej. Płytka zamienia go na opis: bezchmurnie, małe zachmurzenie, pochmurno, mgła, mżawka, deszcz, śnieg, przelotny deszcz, przelotny śnieg albo burza.
- Bez poprawnych współrzędnych `weather` ma wartość `null`.
- Serwer pyta Open-Meteo najwyżej co 30 min i trzyma odczyt w `data/weather.json`.
- `poll` dostaje pola `place`, `temp`, `code`, `max`, `min`, `rain` i `at`. `at` to czas uniksowy zapytania do Open-Meteo.
- Gdy Open-Meteo nie odpowiada, serwer wysyła ostatni odczyt z jego dawnym `at`. Gdy odczytu nie było, wysyła `null`.
- Płytka przyjmuje tylko pełny odczyt z `at`. Liczy jego wiek od `at`, a przyszłe `at` przycina do bieżącej chwili, bo jej zegar może być do 2 s za serwerem.
- `null` nie kasuje ostatniego odczytu na płytce. Pulpit pokazuje jego wiek, a po dobie go chowa.
- Do NVS płytka zapisuje odczyt, gdy się zmieni, a poza tym najwyżej co 30 min, żeby oszczędzać pamięć flash.

## Zachowanie płytki

- **Zegar.**
  - Płytka bierze czas z pola `time` każdej odpowiedzi `poll`.
  - Poprawia zegar tylko wtedy, gdy różni się o więcej niż 2 s, i zapisuje czas w RTC.
  - Czas sprzed 14 listopada 2023 (`CLOCK_MIN_VALID` = 1700000000) pomija jako niewiarygodny.
  - Po starcie bierze czas z RTC.
  - Nie potrzebuje NTP, czyli protokołu synchronizacji czasu, więc sieć IoT musi przepuszczać tylko DNS i HTTPS.
  - Strefa czasowa to Polska, z automatyczną zmianą czasu.
- **Synchronizacja**, po kolei:
  1. ustawia zegar i godzinę ostatniej synchronizacji;
  2. przyjmuje pogodę;
  3. kończy, gdy `changed` ma wartość `false`;
  4. przyjmuje widok według zasad z sekcji „Który widok jest aktywny”;
  5. przyjmuje wiadomość, gdy zmieniło się jej `id` albo `ts`; `id` 0 czyści wiadomość na płytce;
  6. pobiera tapetę, gdy `wallpaper_rev` jest większe od 0 i różni się od zapisanego albo płytka nie ma tapety; gdy pobranie się nie uda, płytka ustawia `rev` na -1 i następna synchronizacja przynosi wszystko jeszcze raz;
  7. przyjmuje listę sesji;
  8. porównuje liczniki `ev` z ostatnio widzianymi i pokazuje powiadomienie o sesjach, gdy pozwalają na to ustawienia (podsekcja „Powiadomienia o sesjach”).
- **Dźwięk wiadomości.**
  - Nowa wiadomość najpierw pojawia się na ekranie, potem gra dźwięk.
  - Wiadomość starsza niż 30 min według czasu serwera przychodzi bez dźwięku, np. po długim trybie offline.
  - Dźwięk z biblioteki płytka pobiera do PSRAM za każdym razem. Gdy pobranie się nie uda, gra `chime` i pokazuje powód.
- **Czujniki.** Co 60 s płytka mierzy temperaturę, wilgotność i baterię.
- **HTTP.**
  - Zapytanie ma limit 8 s.
  - Certyfikat HTTPS płytka sprawdza pakietem certyfikatów z ESP-IDF.
  - Token idzie w nagłówku `X-Token`.
  - Odpowiedź `poll` musi się zmieścić w 8 KB.
- **Głośność.** Kodek jest ustawiony na 85 ze 100 (`VOLUME` w `firmware/main/audio.c`). Tony wbudowane grają z 35% pełnej skali.

### Powiadomienia o sesjach

Tryby, zasięg, ustawienia i animacje opisuje sekcja „Powiadomienia o sesjach Claude”. Ustawienia `notify` i liczniki `ev` przychodzą w pełnej odpowiedzi `poll` (sekcja „API serwera”, podsekcja „Powiadomienia”).

- **Kiedy gra.** Po każdej pełnej odpowiedzi `poll` płytka porównuje `ev` z licznikami, które widziała ostatnio. Powiadomienie gra, gdy spełnione są wszystkie warunki:
  1. płytka jest w trybie NASŁUCH, a tryb z ustawień to nie `quiet`;
  2. urósł właściwy licznik: `wait` dla „Czeka na Ciebie”, a dla „Gotowe” `all` w trybie `ready` albo `done` w trybie `each`;
  3. zdarzenie ma najwyżej 120 s plus odstęp `settings.poll_s` według pola `time` z odpowiedzi (`NOTIFY_MAX_AGE_S` w `firmware/main/notify.h`), więc nawet przy odstępie 1 h płytka nie gubi zdarzenia sprzed ostatniego pytania; starsze, np. po przerwie w sieci, przechodzi bez powiadomienia;
  4. stan nadal trwa: wiersz `wait_id` dalej czeka, wiersz `done_id` dalej jest gotowy, a przy `all` żaden wiersz nie jest aktywny;
  5. pozwala na to zasięg: `all` albo bieżący widok `sessions`.
- **Pierwsza synchronizacja** po starcie przyjmuje liczniki bez powiadomienia. Liczniki płytka trzyma tylko w RAM, tak jak listę sesji. W trybie NA ŻĄDANIE też przyjmuje je bez powiadomienia.
- Po każdym porównaniu płytka zapamiętuje nowe liczniki, także gdy powiadomienie nie zagrało. Pominięte zdarzenie nie wraca więc np. po przejściu na widok Sesje Claude.
- **Starszy serwer.** Odpowiedź bez poprawnych pól `notify` i `ev`, np. ze starszego `api.php`, wyłącza powiadomienia, a reszta synchronizacji działa. Następna odpowiedź z tymi polami działa jak pierwsza synchronizacja. Nieznany tryb albo zasięg też wyłącza powiadomienia. Nieznana animacja, np. nowsza od programu płytki, daje animację domyślną: `happy` dla „Gotowe”, `away` dla „Czeka na Ciebie”.
- **Jedno powiadomienie na synchronizację.** „Czeka na Ciebie” ma pierwszeństwo przed „Gotowe”. Nowa wiadomość z dźwiękiem w tej samej synchronizacji wygrywa z powiadomieniem.
- **Ekran.** Powiadomienie zajmuje cały ekran na 6 s. U góry, na środku, jest animacja w skali 2 (128×128 px, każdy piksel klatki to kwadrat 2×2). Pod nią tytuł „Gotowe” albo „Czeka na Ciebie” (helvB24) i szczegół (helvR14). Teksty idą za językiem płytki (`settings.lang`); po angielsku to „Done”, „Waiting for you”, „All sessions finished”, „N sessions” i „KEY or BOOT closes”:
  - przy „Czeka na Ciebie” i przy „Gotowe” w trybie `each`: tytuł sesji, a bez tytułu alias, przycięty wielokropkiem tak jak w liście sesji; gdy od ostatniej synchronizacji zdarzeń było kilka, „N sesje” albo „N sesji” według polskiej odmiany (po angielsku „1 session” albo „N sessions”);
  - przy „Gotowe” w trybie `ready`: „Wszystkie sesje skończyły”.
- Na dole ekranu jest „KEY albo BOOT zamyka” (helvR10). Pierwsze naciśnięcie KEY albo BOOT, krótkie czy przytrzymane, tylko zamyka powiadomienie. Zmiana widoku z serwera też je zamyka. Potem płytka wraca do tego, co pokazywała.
- **Dźwięk** gra zaraz po pojawieniu się ekranu, z tą samą głośnością co dźwięk wiadomości. Dźwięk z biblioteki płytka pobiera do PSRAM za każdym razem. Gdy pobranie się nie uda, gra `chime` i zapisuje powód jako ostatni błąd na ekranie stanu.
- **Róg widoku Sesje Claude.** W prawym dolnym rogu, z marginesem 4 px, gra animacja 64×64 px. Działa we wszystkich trybach pracy i przy każdych ustawieniach powiadomień. Animację wybiera stan listy, od najważniejszego:
  1. któryś wiersz czeka: `alert`;
  2. któryś wiersz pracuje: róg jest pusty, bez animacji;
  3. wszystkie wiersze są gotowe: `idle`;
  4. lista jest pusta: `sleep`.
- Lista ma do 8 wierszy po 27 px od y = 60 (ósmy kończy się na 276, nad paskiem limitu), więc róg pokazuje się tylko przy najwyżej 6 wierszach (`NOTIFY_CORNER_MAX_ROWS` w `firmware/main/notify.h`). Przy 7 i 8 to miejsce należy do listy.
- Płytka rysuje kolejne klatki tylko wtedy, gdy widać róg albo powiadomienie. Wtedy pętla czeka na przycisk najwyżej do zmiany klatki i nie dłużej niż zwykłe 250 ms.
- Klatki leżą w `firmware/main/anim_frames.c` i `anim_frames.h`. Oba pliki zapisuje `node tools/anim2c.mjs` (podsekcja „Animacje” w sekcji „Powiadomienia o sesjach Claude”).
- Decyzję „czy grać” podejmuje czysta funkcja `notify_decide()` w `firmware/main/notify.c`, bez sprzętu i sieci. Test działa na Macu, z katalogu repozytorium: `cc -std=c11 -Wall -Wextra -Werror -pedantic -o <plik> firmware/tests/notify_test.c firmware/main/notify.c`, potem uruchomienie `<plik>`.

## Panel sesji Claude Code (hook)

Claude Code uruchamia `hook/rlcd-hook.sh` przy zdarzeniach z `~/.claude/settings.json`. Gotowy fragment konfiguracji jest w `hook/settings-snippet.json`.

| Zdarzenie Claude Code | Wysyłany stan |
|---|---|
| UserPromptSubmit (wysłanie polecenia) | `working` (pracuje) |
| PreToolUse dla AskUserQuestion i ExitPlanMode (pytanie albo plan do akceptacji) | `waiting` (czeka na Ciebie) |
| PostToolUse (po każdym narzędziu) | `working` |
| Notification (prośba o zgodę i inne powiadomienia) | `waiting`, oprócz powiadomień `idle_prompt` i `auth_success` |
| Stop (koniec odpowiedzi) | `done` (gotowe) |
| SubagentStart i SubagentStop | ten sam stan co ostatnio, z liczbą pracujących subagentów |
| StopFailure z `rate_limit` (tura zatrzymana na limicie planu) | `done` oraz `POST usage` z `hit` |
| SessionEnd (zamknięcie sesji) | `end`, czyli usunięcie z listy |

- **Konfiguracja.** Hook czyta `~/.config/rlcd/env` z `RLCD_URL` i `RLCD_HOOK_TOKEN`. Gdy ustawiono `XDG_CONFIG_HOME`, szuka tam zamiast w `~/.config`.
- **Adres.** `RLCD_URL` musi zaczynać się od `https://`, żeby token nie szedł otwartym tekstem. Zwykłe `http://` działa tylko dla 127.0.0.1 i localhost, czyli w testach.
- **Projekt.** Projektem jest `CLAUDE_PROJECT_DIR`, a bez tej zmiennej bieżący folder.
- **Alias.**
  - Hook szuka aliasu w `~/.config/rlcd/aliases`: jeden wiersz `<ścieżka> <alias>` na projekt.
  - Ścieżka może zaczynać się od `~/`. Puste wiersze i wiersze z `#` hook pomija. Gdy pasuje kilka wierszy, wygrywa ostatni.
  - Alias ma od 1 do 24 znaków `a-z`, `0-9` i `-`. Niepoprawny alias hook pomija, a w trybie `check` wypisuje ostrzeżenie.
  - Bez aliasu hook wysyła `p-` i 4 cyfry szesnastkowe z sumy kontrolnej `cksum` ścieżki.
  - Ścieżka projektu nie opuszcza Maca.
- **Sesja i tytuł.**
  - Hook czyta `session_id` i `transcript_path` z pierwszych 4096 bajtów zdarzenia JSON na standardowym wejściu. Jako `sid` wysyła pierwsze 8 znaków `session_id` bez myślników.
  - Tytułem jest `customTitle` z ostatniego wpisu `{"type":"custom-title",…}` w transkrypcie, czyli nazwa sesji widoczna w aplikacji Claude. Hook przekazuje go w postaci z transkryptu, z ucieczkami JSON, bez dekodowania.
  - Tytuł bez zamykającego cudzysłowu, z inną ucieczką niż `\" \\ \/ \b \f \n \r \t \uXXXX`, z surowym znakiem sterującym albo dłuższy niż 600 bajtów hook pomija. Wtedy, tak jak bez transkryptu i bez `session_id`, płytka pokazuje alias.
  - Cytat wpisu z tytułem w treści rozmowy nie liczy się, bo w transkrypcie jego cudzysłowy mają ucieczki.
- **Liczby i rodzaje (nigdy treść).** Poza stanem hook wysyła tylko liczby i rodzaje:
  - `turns`: liczba zdarzeń UserPromptSubmit sesji;
  - `agents`: liczba pracujących subagentów (SubagentStart plus 1, SubagentStop minus 1, nie poniżej 0; zerowana przy nowej turze i przy `done`). Zdarzenia narzędzi wewnątrz subagenta (z `agent_id`) nie zmieniają listy zadań sesji;
  - `kind`: `question` przy AskUserQuestion, `plan` przy ExitPlanMode, `permission` przy powiadomieniu `permission_prompt`; kasowany przy następnym `working`;
  - `tasks`: `{"d","t"}` czyli zrobione z wszystkich. Hook liczy je z narzędzi list zadań w PostToolUse i trzyma tablicę statusów (`pending`, `in_progress`, `completed`, `deleted`) w `~/.cache/rlcd/s-<sid>.t`: `TodoWrite` zastępuje całą listę (`todos[].status`); `TaskCreate` dodaje zadanie `pending` (id to numer w kolejności utworzenia); `TaskUpdate` zmienia status zadania o `taskId`, a zadanie `deleted` nie liczy się do `t`; `mcp__ccd_turn__update_plan` z aplikacji Claude zastępuje listę z `items[].status` albo stosuje `advance` (kończy bieżący krok i zaczyna następny), `done` (numery od 1) i `start`. Wywołanie, które wskazuje nieznane zadanie, wyłącza postęp tej sesji (plik `null`) zamiast pokazywać zły: wraca przy następnym `TodoWrite` albo `update_plan` z `items`. Lista w całości zrobiona znika przy następnym poleceniu. Bez listy nie ma pola, nigdy „0/0”. Nazwy kroków (treść) zostają na Macu. Liczenie wymaga `jq`; bez niego sesja po prostu nie ma postępu;
  - liczniki leżą w `~/.cache/rlcd/s-<sid>.x`, a zdarzenia równoległych narzędzi mogą się w nich ścigać (najwyżej błąd o 1, naprawiany następnym zdarzeniem).
- **Tylko zmiany.** Ostatni stan, tytuł i liczby każdej sesji hook zapisuje w `~/.cache/rlcd/s-<sid>` (bez `sid`: w `~/.cache/rlcd/<alias>`), dopiero gdy serwer je przyjął. `end` kasuje ten plik.
- **Praca w tle.** Hook wysyła w tle z limitem 3 s, niczego nie wypisuje i zawsze kończy się kodem 0. Wyjście przy UserPromptSubmit trafiłoby do kontekstu Claude. `RLCD_FOREGROUND=1` każe czekać na serwer, na potrzeby testów.
- **Token.** Do curl trafia przez standardowe wejście (`-H @-`), więc nie widać go na liście procesów.
- **Sprawdzenie.** `rlcd-hook.sh check` uruchomiony w folderze projektu wypisuje projekt, alias i serwer. Potem wysyła `done` z limitem 10 s i pokazuje odpowiedź serwera.
- **Ograniczenia.**
  - Po przerwaniu odpowiedzi klawiszem Esc wiersz zostaje na „pracuje”, aż Claude skończy następną odpowiedź albo sesja się zamknie. Claude Code nie zgłasza przerwania.
  - Sesja zamknięta bez SessionEnd, np. przez zabity terminal, znika po 12 h.
  - Płytka pokazuje najwyżej 8 sesji.

### Pasek stanu (`hook/rlcd-statusline.sh`)

Claude Code uruchamia skrypt po każdej odpowiedzi i podaje mu JSON na standardowym wejściu; skrypt wypisuje jedną krótką linię paska („5h 63% 7d 21% ctx 42%”) i zgłasza liczby serwerowi przez `POST usage`. Wpis `statusLine` jest w `hook/settings-snippet.json`. Wymaga `jq`.

- Czyta `rate_limits.five_hour.used_percentage` i `resets_at`, to samo dla `seven_day`, `context_window.used_percentage`, `model.display_name` i `session_id`. Pola `rate_limits` są tylko w planach Pro, Max i Ultra i dopiero po pierwszej odpowiedzi sesji, a starsze Claude Code (CLI 2.1.50) ich nie ma. Bez nich skrypt nie wysyła limitów, ale wypisuje to, co ma.
- Wysyła tylko liczby: procenty zaokrąglone do całych, czasy resetu, model i zapełnienie kontekstu. Nazw projektów, ścieżek i treści nie czyta do wysyłki.
- Wartość spoza zakresu (procent poza 0–100, model z innymi znakami niż `A-Za-z0-9 ._-`) pomija.
- Zgłasza tylko zmiany, w tle (`curl -m 3`), z cache w `~/.cache/rlcd/usage-<sid>`. Token jak w hooku, przez standardowe wejście. Zawsze kończy się kodem 0, a na serwer idzie tylko adres `https://` albo `http://127.0.0.1`.
- Aplikacja Claude (desktop) nie uruchamia skryptu paska stanu, choć zna pole `rate_limits`. Limity przychodzą więc tylko z sesji w terminalu (`claude update` do wersji z polem `rate_limits`). Dla sesji z aplikacji, gdy zatrzyma je limit, działa hook `StopFailure` z matcherem `rate_limit`: pasek na płytce i w aplikacji pokazuje wtedy „limit wyczerpany” bez procentu.

## Format tapety i konwerter

- Tapeta ma 400×300 pikseli, 1 bit na piksel, razem 15000 B.
- Wiersze idą od góry, po 50 B na wiersz.
- Najstarszy bit bajtu to piksel najbardziej z lewej. Bit 1 oznacza czarny.

`python3 tools/img2rlcd.py <obraz> <tapeta.bin>` przyjmuje każdy format, który czyta `sips` z macOS, np. JPEG, PNG i HEIC. Opcje:

- `--fit cover` (domyślnie) wypełnia ekran i przycina brzegi;
- `--fit contain` pokazuje cały obraz na białym tle;
- domyślnie działa dithering Floyda–Steinberga: błąd zaokrąglenia każdego piksela przechodzi na sąsiednie, więc gęstość kropek udaje odcienie szarości;
- `--no-dither` daje zwykły próg, lepszy do tekstu i rysunków kreskowych;
- `--preview <podgląd.bmp>` zapisuje też podgląd BMP, który otwiera Quick Look.

Zdjęcia z telefonu konwerter obraca według znacznika obrotu EXIF, czyli opisu zapisanego przez aparat. Przezroczyste piksele kładzie na białym tle.

## Format dźwięku

Serwer przechowuje dźwięki jako WAV PCM 16-bit, mono, 16 kHz, najwyżej 15 s, z nagłówkiem 44 B.

Konwersja w przeglądarce (Web Audio API):

1. `decodeAudioData` dekoduje plik.
2. `OfflineAudioContext(1, ceil(min(długość_s, 15) × 16000), 16000)` daje jeden kanał, 16 kHz i najwyżej 15 s.
3. Normalizacja ustawia szczyt na −1 dBFS. dBFS to decybele względem pełnej skali, więc najgłośniejsza próbka ma ok. 89% maksimum.
4. Próbki zamieniają się na liczby 16-bitowe.
5. Do danych dochodzi 44-bajtowy nagłówek WAV.
6. Plik idzie na serwer przez `POST sound?name=<nazwa>`.

Na Macu:

```bash
afconvert -f WAVE -d LEI16@16000 -c 1 wejscie.mp3 wyjscie.wav
```

`afconvert` nie skraca nagrania. Dźwięk dłuższy niż 15 s trzeba najpierw przyciąć.

## Aplikacja PWA

Pliki statyczne w `server/`, test `node server/tests/app_test.mjs`. Aplikacja ma wygląd Liquid Glass (podsekcja „Wygląd”), panel „Powiadomienia Claude” na karcie Płytka (sekcja „Powiadomienia o sesjach Claude”), dwa języki interfejsu: angielski, domyślny, i polski (podsekcja „Język”), oraz kartę Dashboard z limitami planu i postępem zadań (podsekcja „Ekrany”). PWA to strona WWW, którą telefon może zainstalować jak aplikację: ma ikonę, pełny ekran i działa po zamknięciu przeglądarki.

### Założenia

- Statyczne pliki w `server/`, obok `api.php`, wgrywane przez FTP. Bez budowania, bez npm, bez zewnętrznych bibliotek, fontów i CDN. Czysty HTML, CSS i JavaScript (moduły ES).
- Ten sam host co `api.php`, bo serwer nie wysyła nagłówków CORS. Aplikacja otwiera się pod `https://<host>/<katalog>/`.
- Aplikacja używa API z sekcji „API serwera”. Jedyna akcja dodana dla niej to `POST notify` (sekcja „Powiadomienia o sesjach Claude”). Aplikacja nie odpowiada na pytania Claude: to robi Remote Control.
- Podpis nadawcy daje serwer: pole `from` to imię przypisane do tokenu w `config.php`. Aplikacja nie wysyła imienia i nie da się go podrobić.
- Użytkowników dodaje się w `config.php` przez FTP: imię i token. Aplikacja nie ma panelu administratora, bo API go nie ma.

### Pliki

| Plik | Rola |
|---|---|
| `server/index.html` | szkielet aplikacji, wszystkie ekrany |
| `server/app.css` | wygląd, motyw jasny i ciemny |
| `server/app.js` | logika ekranów, nawigacja, odświeżanie stanu |
| `server/lib/api.js` | wywołania API, token w `X-Token`, tłumaczenie błędów |
| `server/lib/wav.js` | dekodowanie dźwięku i zapis WAV 16 kHz mono |
| `server/lib/dither.js` | obraz → tapeta 15000 B (cover/contain, Floyd–Steinberg albo próg) |
| `server/lib/text.js` | sprawdzanie znaków, których płytka nie narysuje |
| `server/lib/anim.js` | klatki animacji powiadomień, reguła rogu listy sesji na płytce i reguła Clawda na Dashboardzie; źródło dla `tools/anim2c.mjs` |
| `server/lib/dash.js` | dane Dashboardu: pasek limitu (fail-closed), czas, fakty i postęp zadań sesji |
| `server/lib/i18n.js` | słownik napisów po angielsku i po polsku, język tego urządzenia |
| `server/sw.js` | service worker: pamięć podręczna plików aplikacji |
| `server/manifest.webmanifest` | nazwa, kolory, ikony; po angielsku, w języku domyślnym |
| `server/manifest-pl.webmanifest` | ten sam manifest po polsku; różni się tylko nazwą, krótką nazwą, opisem i językiem |
| `server/icons/` | `icon.svg`, `icon-192.png`, `icon-512.png`, `apple-touch-icon.png` (180 px) |
| `server/tests/app_test.mjs` | testy `wav.js`, `dither.js`, `text.js`, `api.js`, `anim.js`, `dash.js` i `i18n.js` w Node, bez sieci; do tego zgodność `index.html` i obu manifestów ze słownikiem |

`server/.htaccess` dostaje typ MIME dla `.webmanifest` (działa dla obu manifestów), nagłówek CSP i brak cache dla `sw.js`. CSP (Content Security Policy) to lista źródeł, z których strona może ładować kod: `default-src 'self'; img-src 'self' blob: data:; media-src 'self' blob:; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'`. Nagłówki są w `<IfModule mod_headers.c>`, więc hosting bez tego modułu dalej działa.

Pliki aplikacji wydaje `server/gate.php`: `.htaccess` kieruje do niego każde zapytanie poza `api.php` (sekcja „Blokada urządzeń”). Nowy plik aplikacji trzeba więc dopisać w dwóch miejscach: do `FILES` w `sw.js` i do `APPLOCK_FILES` w `server/applock.php`. Bez tego `gate.php` odda dla niego 404, a test `php server/tests/lock_test.php` nie przejdzie.

### Wygląd

- **Archetyp: płynne szkło (Liquid Glass).** To język wizualny Apple z iOS 26: półprzezroczyste tafle z rozmyciem tła, odblaskiem na krawędziach i sterowaniem unoszącym się nad treścią. Aplikacja ma dwie warstwy:
  - **warstwa treści:** tło z dwiema nieruchomymi poświatami i szklane panele z treścią ekranów;
  - **warstwa nawigacji:** nagłówek, pływający pasek kart w kształcie kapsuły, okna potwierdzeń i komunikaty. Unosi się nad treścią, a przewijane panele przesuwają się pod nią i prześwitują przez szkło.
- **Paleta 3 tonów.** Szkło i poświaty powstają tylko z tych tonów i bieli, z podanym kryciem:

| Ton | Jasny | Ciemny | Użycie |
|---|---|---|---|
| tło | `#E4EAED` | `#0A1013` | podkład pod szkłem |
| tusz | `#0D171B` | `#EEF3F5` | tekst, ikony, delikatna poświata |
| akcent | `#A33508` | `#FF8F57` | przycisk główny, aktywna karta i wybór, poświata, ostrzeżenia |

  - Tekst pomocniczy: `#46555B` (jasny) i `#9DACB2` (ciemny).
  - Szkło: biel z kryciem 0,62 (jasny) albo `#1C252A` z kryciem 0,62 (ciemny). Wyjątek: warstwa nawigacji w ciemnym motywie ma krycie 0,70. Pływa nad treścią, więc może leżeć nad białym podglądem ekranu płytki; tam tusz na szkle 0,62 miałby kontrast 4,01:1, a na szkle 0,70 ma 5,18:1. Rozmycie `blur(20px) saturate(180%)` w panelach i `blur(28px) saturate(180%)` w warstwie nawigacji. Zawsze w parze z `-webkit-backdrop-filter` dla Safari.
  - Poświaty: akcent z kryciem 0,26 u góry z prawej i tusz z kryciem 0,10 u dołu z lewej. W ciemnym motywie krycie wynosi 0,24 i 0,07. Poświaty leżą na `body::before` z `position: fixed`, są nieruchome i bez animacji.
  - Krawędź szkła: obwódka 1 px z bieli z kryciem 0,7 (jasny) albo 0,16 (ciemny). Do tego odblask u góry `inset 0 1px 0` z bieli z kryciem 0,9 albo 0,16 i cień `0 10px 30px` z tuszu z kryciem 0,14 (jasny) albo z czerni z kryciem 0,45 (ciemny).
- **Kontrast WCAG** jest policzony wzorem WCAG 2.1 dla szkła nałożonego na tło z kryciem z palety, w najgorszym miejscu, czyli nad poświatą akcentu:

| Para | Jasny | Ciemny |
|---|---|---|
| tusz na szkle | 14,68 | 12,98 |
| tekst pomocniczy na szkle | 6,26 | 6,21 |
| akcent na szkle | 5,53 | 6,44 |
| napis na przycisku akcentu (`#FFFFFF` / `#0A1013`) | 6,84 | 8,50 |

  Wszystko spełnia WCAG AA, czyli 4,5 dla zwykłego tekstu. Warunek: **tekst leży zawsze na szkle**, nigdy wprost na tle z poświatą. W jasnym motywie tekst pomocniczy na samej poświacie miałby 4,25, a akcent 3,75. Wewnątrz panelu pola i przyciski nie dostają drugiej warstwy szkła, tylko wypełnienie z tuszu z kryciem do 0,08. Na takim wypełnieniu tekst pomocniczy ma co najmniej 4,9 (najgorszy przypadek z obu motywów). Tekst w kolorze akcentu leży tylko na zwykłym szkle.
- **Kształty.** Przyciski, pola wyboru dźwięku i pasek kart są kapsułami (`border-radius: 999px`). Panele mają promień 22 px. Promienie są współśrodkowe: element w panelu ma promień panelu minus odstęp od jego krawędzi. Bez kanciastych kart i bez fioletu.
- **Ruch.** Zaznaczenie aktywnej karty to „kropla”, czyli wypełnienie z akcentu, które przesuwa się między kartami z lekkim odbiciem (`transform`, 380 ms, `cubic-bezier(.3, 1.4, .5, 1)`). Naciśnięty przycisk zmniejsza się do 0,97. Przy `prefers-reduced-motion: reduce` animacji nie ma. Podglądy animacji płytki na karcie Płytka mają własne zasady, opisane przy panelu „Powiadomienia Claude”.
- **Wersje zastępcze:**
  - bez `backdrop-filter` (`@supports`) i przy `prefers-reduced-transparency: reduce` szkło jest nieprzezroczyste: `#FDFDFE` (jasny) albo `#1B2328` (ciemny);
  - przy `prefers-contrast: more` szkło też jest nieprzezroczyste, a obwódka to 1 px tuszu z kryciem 0,5.
- **Wydajność.** Rozmycie mają tylko nagłówek, pasek kart, panele, okna i komunikaty. Wiersze list i pola w panelu nie mają rozmycia. Podgląd tapety to nieprzezroczysty `canvas` w czerni i bieli, bez nakładki szkła.
- **Czcionki systemowe**, nic nie jest pobierane. Tekst ma `system-ui, -apple-system, "Segoe UI", Roboto, sans-serif`, 17 px z interlinią 1,4. Nagłówki mają `ui-rounded, system-ui, -apple-system, sans-serif` w grubości 700. Liczby, godziny i liczniki mają `font-variant-numeric: tabular-nums`, czyli cyfry równej szerokości.
- **Motyw** idzie za ustawieniem systemu (`prefers-color-scheme`). Strona deklaruje `color-scheme: light dark` i `theme-color` dla obu motywów.
- **Układ.** Jedna kolumna, najwyżej 640 px szerokości, marginesy 16 px.
  - Na telefonie pasek kart unosi się 12 px nad dolną krawędzią z uwzględnieniem wcięcia iPhone'a (`env(safe-area-inset-bottom)`). Treść ma u dołu tyle odstępu, żeby ostatni panel dało się przewinąć nad pasek.
  - Od 768 px pasek kart unosi się na środku pod nagłówkiem.
  - Cele dotyku mają co najmniej 44×44 px.
  - Fokus klawiatury to obwódka 2 px w kolorze akcentu z odstępem 2 px.
- **CSP.** Style są tylko w `app.css`. Atrybuty `style` i bloki `<style>` są zablokowane przez CSP, więc kod ustawia style przez `element.style` albo klasy.

### Profile i logowanie

- Profil to para: imię z `GET me` i token. Profile leżą w `localStorage` przeglądarki. Jedno urządzenie może trzymać kilka profili, np. tablet w kuchni.
- **Ekran „Dodaj osobę”** pokazuje się, gdy nie ma żadnego profilu. Wtedy nad tytułem ma wybór języka aplikacji (podsekcja „Język”, punkt „Pierwsze uruchomienie”). Ma jedno pole: token (typ `password`, z przyciskiem „Pokaż”). Aplikacja wywołuje `GET me`:
  - sukces zapisuje profil i ustawia go jako aktywny;
  - 401 pokazuje „Ten token nie pasuje do nikogo w config.php”;
  - 500 pokazuje komunikat konfiguracji serwera w języku aplikacji.
- **Ekran „Osoby”** (ikona w nagłówku z imieniem aktywnej osoby) pokazuje listę profili, przełączanie, „Dodaj osobę” i „Usuń z tego urządzenia”. Pod listą jest sekcja „Ustawienia” z językiem aplikacji (podsekcja „Język”). Usunięcie profilu kasuje tylko wpis w przeglądarce, bez zmian na serwerze.
- 401 przy jakimkolwiek zapytaniu, np. po zmianie tokenu w `config.php`, oznacza profil jako nieważny i otwiera „Dodaj osobę” z komunikatem „Token <imię> przestał działać”.
- Token nigdy nie trafia do adresu strony ani do logów konsoli.
- Przy włączonej blokadzie urządzeń pierwsze udane logowanie zajmuje tej przeglądarce miejsce (sekcja „Blokada urządzeń”). Aplikacja nic przy tym nie robi: ciasteczko ustawia i odnawia serwer.

### Język

Interfejs ma dwa języki: angielski i polski. Cytaty napisów w tej specyfikacji podają wersję polską; wersja angielska leży pod tym samym kluczem w `lib/i18n.js`.

- **Domyślnie po angielsku.** Bez zapisanego wyboru aplikacja otwiera się po angielsku (`en`). Tak samo, gdy przeglądarka blokuje dane strony albo zapisany kod jest nieznany.
- **Kody i nazwy** są te same co w ustawieniu języka płytki: `en` „English” i `pl` „Polski”. Każda nazwa jest napisana w swoim języku, więc w obu wersjach interfejsu wygląda tak samo.
- **Przełącznik.** Okno „Osoby” ma pod listą profili sekcję „Ustawienia” z grupą „Język aplikacji”: dwie opcje (English, Polski) i uwagę „Zmiana języka odświeża aplikację. Dotyczy tylko tego urządzenia; język płytki jest wspólny dla wszystkich i ustawisz go na karcie Płytka, w Ustawieniach płytki.”. Zmiana języka aplikacji nie zmienia języka płytki, bo z aplikacji korzysta kilka osób i każda może mieć inny język. Wybór zapisuje się w `localStorage` tej przeglądarki pod kluczem `tablica.lang`, osobno od profili. Jedno urządzenie ma więc jeden język dla wszystkich swoich profili.
- **Odświeżenie po zmianie.** Po zapisie aplikacja przeładowuje stronę (`location.reload()`). Jedno załadowanie strony ma jeden język, więc żaden napis wstawiony wcześniej przez kod (błąd, komunikat, lista sesji, nazwa animacji) nie zostaje w starym języku. Koszt: niewysłany tekst w polach może zniknąć, dlatego uwaga pod przełącznikiem mówi o odświeżeniu, zanim ktoś wybierze. Gdy przeglądarka nie zapisze wyboru, aplikacja się nie odświeża, pokazuje „Przeglądarka nie zapisała języka.” i zostawia zaznaczony dotychczasowy język.
- **Strona i instalacja.** `index.html` ma w `<head>` wartości angielskie. Przy starcie `app.js` ustawia według języka: `<html lang>`, tytuł karty, opis strony, nazwę pod ikoną na iPhonie (`apple-mobile-web-app-title`) i manifest: `manifest.webmanifest` („Noticeboard”) albo `manifest-pl.webmanifest` („Tablica”). Telefon czyta manifest przy instalacji, więc zainstalowana aplikacja może zachować dawną nazwę na ekranie głównym aż do ponownej instalacji.
- **Napisy wstawia kod.** Elementy w `index.html` są puste i mają klucz w atrybucie `data-i18n` (tekst), `data-i18n-aria-label` (opis dla czytnika ekranu) albo `data-i18n-placeholder` (podpowiedź w polu). `app.js` wpisuje napisy tylko przez `textContent` i `setAttribute`, także w szablonach `<template>`, bo CSP zabrania `innerHTML`. Brak klucza w polskim słowniku daje napis angielski, a brak w obu sam klucz, żeby błąd był widoczny.
- **Liczba mnoga, daty i liczby.** Liczniki mają formy według reguł języka (`Intl.PluralRules`), np. „1 znak”, „2 znaki”, „5 znaków”, po angielsku „1 character”, „2 characters”. Godziny, daty i liczby mają format `en-GB` albo `pl-PL`.
- **Łamanie wierszy.** Po polsku jednoliterowe słowa („w”, „i”, „z”) łączą się z następnym słowem twardą spacją (`glue` w `lib/text.js`), jak dotąd. Po angielsku napisy interfejsu nie są łączone. Treść od ludzi (tekst wiadomości, nazwy dźwięków, tytuły sesji) nie jest tłumaczona i jest łączona w obu językach, tak jak przed zmianą.
- **Bez tłumaczenia zostają:**
  - nazwy animacji w `lib/anim.js`, bo z tego pliku `tools/anim2c.mjs` buduje firmware; aplikacja pokazuje nazwy ze słownika, a test pilnuje, że polskie nazwy w słowniku są równe tym z `anim.js`;
  - nieznany komunikat serwera, np. `write failed`: aplikacja cytuje go dosłownie w zdaniu w swoim języku (podsekcja „Błędy”);
  - błędy programisty z `lib/dither.js` (złe wymiary obrazu): nie powinny się zdarzyć, a gdyby się zdarzyły, idą po angielsku jako szczegół zdania „Nieoczekiwany błąd aplikacji: …”;
  - `lib/text.js` nie ma napisów interfejsu, tylko reguły znaków płytki.
- **Pierwsze uruchomienie.** Przycisk „Osoby” jest ukryty, dopóki na urządzeniu nie ma profilu. Dlatego ekran „Dodaj osobę” bez żadnego profilu ma nad tytułem tę samą grupę „Język aplikacji” co okno „Osoby”: te same dwie opcje, tę samą uwagę o odświeżeniu i ten sam błąd zapisu. Grupa stoi nad polem tokenu, bo zmiana języka przeładowuje stronę i wymazałaby wklejony token. Gdy na urządzeniu jest choć jeden profil, także nieważny, tej grupy na ekranie nie ma, a język zmienia się w oknie „Osoby”. Grupy mają osobne nazwy pól, `app-lang` i `first-lang`, więc wybór w jednej nie odznacza drugiej.
- **Język płytki to osobne ustawienie.** Język płytki (`POST settings` z polem `lang`, panel „Ustawienia płytki” na karcie Płytka) leży na serwerze, jest wspólny dla wszystkich i zmienia napisy na płytce. Język aplikacji należy do jednego urządzenia i zmienia tylko napisy w aplikacji. Jedno nie zmienia drugiego: telefon po angielsku może ustawić płytkę po polsku.
- **Kontrast.** Uwaga pod przełącznikiem ma kolor tuszu, jak reszta tekstu w oknach: nad przyciemnionym tłem okna tekst pomocniczy spadłby poniżej 4,5:1.

### Ekrany

Nawigacja ma 5 kart w tej kolejności: **Wyślij**, **Płytka**, **Dashboard**, **Dźwięki**, **Tapeta**. Pasek kart ma same ikony: każda karta ma `aria-label` i `title` z nazwą w języku aplikacji (`tab.send`, `tab.board`, `tab.dash`, `tab.sounds`, `tab.wall`), a cel dotyku ma co najmniej 44 px. Nagłówek ma nazwę aplikacji („Noticeboard” po angielsku, „Tablica” po polsku), imię aktywnej osoby i przycisk odświeżania.

**1. Wyślij**

- Pole tekstu z licznikiem „N/280”. Licznik liczy znaki tak jak serwer (punkty kodowe Unicode, po obcięciu spacji z brzegów).
- Pod polem ostrzeżenie, gdy tekst ma znaki, których płytka nie narysuje (emoji, —, … i inne spoza zestawu czcionek). Ostrzeżenie wymienia te znaki i ma przycisk „Zamień” (— → -, … → ..., „ ” → ", reszta usunięta). Wysłanie z ostrzeżeniem jest dozwolone.
- Wybór dźwięku: pozioma lista przycisków. Najpierw „Cisza”, potem tony wbudowane (Dzwonek, Gong, Obiad, Alarm dla `chime`, `bell`, `dinner`, `alert`), potem biblioteka. Domyślnie „Cisza”. Dźwięk z biblioteki ma przycisk odsłuchu. Tonów wbudowanych przeglądarka nie odtworzy, bo generuje je płytka, więc mają tylko opis.
- Przycisk „Wyślij na płytkę” → `POST message` `{"text","sound"}`. Sukces czyści pole i pokazuje „Wysłane jako <imię>”.
- Pod formularzem „Teraz na płytce”: ostatnia wiadomość z `state.message` (nadawca, godzina, tekst, dźwięk).

**2. Płytka**

- **Aktywny widok.** Wybór jednej z czterech opcji, każda z nazwą z płytki i opisem: Pulpit, Wiadomość, Tapeta, Sesje Claude. Zaznaczony jest `state.view`. Wybór → `POST view`. Pod spodem uwaga: „Widok zmieniony przyciskiem na płytce zostaje do następnej zmiany z aplikacji”.
- **Stan urządzenia** z `state.device`:
  - ostatni kontakt: „N s temu”, „N min temu”, godzina albo „jeszcze się nie zgłosiła”;
  - przy ostatnim kontakcie starszym niż 2 min (a przy dłuższym odstępie nasłuchu niż dwa odstępy i 30 s) w trybie NASŁUCH ostrzeżenie „Płytka nie odpowiada od N min”;
  - tryb: Nasłuch, Na żądanie, Offline;
  - zasilanie: „USB, bez akumulatora” przy `bat_mv` = 0, inaczej napięcie w woltach.
  - Uwaga: w trybie Na żądanie i Offline płytka pobierze zmiany dopiero po naciśnięciu BOOT.
  - **Sprawdzanie serwera w trybie Nasłuch**, czyli odstęp `poll_s` z `state.settings`. Dwie opcje:
    - „Domyślne (10 s)”: liczbę bierze z `poll_default`. Wybór od razu zapisuje `{"poll_s":null}`, a gdy odstęp domyślny już obowiązuje, nic nie wysyła;
    - „Własne” z opisem „Pełne sekundy od 5 do 3600”: wybór tylko odsłania pole sekund z jednostką „s” i przyciskiem „Zapisz”. Puste pole dostaje obecny odstęp. Enter w polu działa jak „Zapisz”.
  - Pole przyjmuje 1–4 cyfry ASCII, po obcięciu spacji z brzegów (`pollSeconds` w `lib/api.js`). Inna wartość nie idzie do serwera: pole dostaje `aria-invalid="true"` i fokus, a pod panelem pojawia się „Wpisz pełne sekundy od 5 do 3600.”.
  - Udany zapis pokazuje toast w języku aplikacji, np. „Nasłuch: serwer co 45 s”, a przy pełnych minutach „co 5 min”, tak jak pisze ekran stanu płytki. W czasie zapisu przycisk ma napis „Zapisuję…”.
  - Błąd zapisu własnego odstępu zostawia wpisaną liczbę do poprawki. Błąd powrotu do domyślnego wraca zaznaczenie do `state.settings`. Komunikat pojawia się pod panelem.
  - Dopóki pole ma niezapisane zmiany, nie idzie za serwerem, np. przy odświeżaniu co 30 s.
  - Pod opcjami uwaga: dłuższy odstęp opóźnia wiadomość, tapetę i powiadomienie o sesji Claude, ale płytka rzadziej łączy się z serwerem; nowy odstęp przyjmie przy najbliższym kontakcie.
  - Starszy `api.php` bez `poll_default`: opcja ma napis „Domyślne” bez liczby i żadna opcja nie jest zaznaczona.
- **Ustawienia płytki** z `state.settings`:
  - język płytki: English albo Polski (`lang` = `en` / `pl`); to osobny wybór od języka aplikacji;
  - wybór od razu zapisuje się przez `POST settings` z samym `lang`. Toast w języku aplikacji: „Język płytki: Polski”;
  - język płytki i odstęp nasłuchu idą jedną kolejką zapisów. Szybkie kolejne zmiany aplikacja scala i wysyła tylko ostatni stan;
  - błąd zapisu wraca zaznaczenie do `state.settings` i pokazuje komunikat pod panelem;
  - uwaga: płytka przyjmie zmianę przy najbliższym kontakcie z serwerem;
  - starszy serwer bez pola `settings` nie zaznacza niczego.
- **Powiadomienia Claude** z `state.notify`, pod ustawieniami płytki. Zapis idzie przez `POST notify` (sekcja „API serwera”, podsekcja „Powiadomienia”).
  - **Tryb:** jedna z trzech opcji, każda z opisem: „Cichy” (tylko lista, bez dźwięku), „Gotowy na kolejne zadania” (raz, gdy skończą wszystkie aktywne sesje), „Prymus” (po każdej skończonej odpowiedzi). W trybie Cichy wszystkie pola działają, a panel mówi „W trybie Cichy płytka nie powiadamia.”. Ustawienia można więc przygotować zawczasu.
  - **Gdzie:** „Tylko ekran Sesje Claude” albo „Wszystkie ekrany”.
  - **Gotowe** i **Czeka na Ciebie:** po dwa wybory. Dźwięk jak na karcie Wyślij: Cisza, Dzwonek, Gong, Obiad, Alarm, potem biblioteka z odsłuchem. Animacja: pięć przycisków z podglądem 64×64 px na `canvas` i nazwą.
  - Po otwarciu karty pasek dźwięków przewija się tak, żeby zapisany wybór był widoczny. Przewija się tylko pasek, nie strona.
  - Zapisany dźwięk, którego nie ma już w bibliotece, ma kafel „Dźwięk z biblioteki (usunięty)” i ostrzeżenie „Płytka zagra Dzwonek”.
  - **Zapisz ustawienia** wysyła cały obiekt. Gdy formularz nie różni się od `state.notify`, przycisk ma `aria-disabled="true"` i nic nie robi, ale zostaje w kolejności fokusu. Sukces pokazuje „Zapisane. Płytka przyjmie je przy następnej synchronizacji.”. Zapis z usuniętym dźwiękiem dostaje 400, a pod przyciskiem pojawia się np. „Dźwięku z „Gotowe” nie ma w bibliotece. Wybierz inny.”.
  - Formularz bez zmian idzie za serwerem, np. po zapisie z innego telefonu. Formularz ze zmianami je zachowuje.
  - Gdy znany tryb płytki z `state.device.mode` jest inny niż `listen`, panel ostrzega: „Płytka jest w trybie <tryb>. Powiadomienia działają tylko w trybie Nasłuch (przycisk KEY).”.
  - Gdy `state` nie ma pola `notify`, czyli serwer ma starszy `api.php`, panel pokazuje „Serwer nie obsługuje powiadomień: wgraj nowy api.php” zamiast formularza.
  - **Ruch podglądów.** Kryterium WCAG 2.2.2 wymaga, żeby ruch, który sam trwa dłużej niż 5 s, dało się zatrzymać. Dlatego nic nie rusza się samo dłużej niż 5 s. Każdy podgląd i Clawd z Dashboardu grają tyle pełnych cykli, ile mieści się w 5 s (co najmniej jeden), a potem stoją na klatce kluczowej (`key` w `anim.js`). Przy `prefers-reduced-motion: reduce` od razu stoją na klatce kluczowej. Wybrany podgląd gra raz, także przy tym ustawieniu, bo użytkownik sam o to poprosił. Otwarcie karty Płytka albo Dashboard i zmiana ustawienia ruchu dają nowe 5 s, tak samo zmiana animacji Clawda. Poza tymi kartami i przy ukrytej karcie przeglądarki nic się nie rysuje.

**3. Dashboard** (karta startowa: aplikacja otwiera się na niej; w pasku środkowa; `state.usage`, `state.sessions`; tylko do odczytu)

Kolejność od góry: Clawd, Sesje Claude, Limity planu.

- **Clawd.** Animacja z `lib/anim.js` bez panelu i bez ramki, wprost na tle, i zdanie pod nią. Klatka 64×64 px jest pokazana 2 razy większa na telefonie i 3 razy od szerokości 600 px, piksele bez wygładzania. Puste wiersze klatki nad i pod postacią (animacje Dashboardu rysują w wierszach 9–55) zabierają ujemne marginesy. Animację wybiera `dashboardAnim`, od najważniejszego stanu:
  1. któraś sesja czeka: `alert`, „Czeka na Ciebie: N”;
  2. któraś pracuje: `typing`, „Pracuje: N”;
  3. któraś skończyła mniej niż 90 s temu: `happy`, „Zadanie właśnie skończone”; wiek liczy się z `age` wiersza, więc zegar telefonu nie ma znaczenia, a radość kończy się sama;
  4. są sesje, wszystkie gotowe dawno temu: `idle`, „Wszystkie sesje skończone”;
  5. brak sesji: `sleep`, „Brak sesji Claude Code”.
  Czytnik ekranu dostaje opis „Clawd: <nazwa animacji>”. Ruch podlega regule 5 s z karty Płytka. Clawd jest pomarańczowym wycinkiem (`frameToCutout`): zapalone piksele mają kolor `--clawd`, biel zamknięta przez ciało (oczy) ma kolor `--clawd-eye`, a biel sięgająca brzegu klatki jest przezroczysta. Motyw jasny: `#C15F3C`, 3,5:1 do tła, oczy `#0D171B`, 4,3:1 do ciała. Motyw ciemny: `#D97757`, 6,1:1 do tła, oczy `#0A1013` w kolorze tła, więc też 6,1:1 do ciała. Zmiana motywu przemalowuje Clawda od razu. Gdy aplikacja nie odczyta tych kolorów, Clawd wraca do pikseli płytki (czarne na białym). Podglądy animacji na karcie Płytka zostają pikselami płytki.
- **Sesje Claude** (przeniesione z karty Płytka) z `state.sessions`: tytuł sesji (`title`), a gdy jest pusty albo go brak, alias; stan w języku aplikacji („pracuje”, „czeka na Ciebie”, „gotowe”, po angielsku „working”, „waiting for you”, „done”) i wiek. Gdy sesja czeka i zna `kind`, stan mówi na co: „czeka na Ciebie: pytanie”, „…: plan do zatwierdzenia”, „…: prośba o zgodę”. Długi tytuł zawija się najwyżej do dwóch wierszy. Kluczem wiersza jest `id`, a na starszym serwerze bez tego pola alias. Pod stanem:
  - pasek postępu zadań z „2/5”, gdy hook zgłosił listę (`taskProgress`: tylko `1 ≤ t`, `0 ≤ d ≤ t`; inaczej bez paska);
  - linia faktów, tylko tych, które hook zgłosił: „tura 7 · trwa 4 min · subagenci: 2 · kontekst 41% · Opus 5.5” (czas tury rośnie od pobrania stanu).
  Aplikacja nie pozwala odpowiadać na pytania Claude: sesja tylko pokazuje, że czeka.
- **Limity planu** (`lib/dash.js`, `limitBar`): dwa paski, „Okno 5 h” i „Tydzień”, każdy z procentem, „reset za 1 h 19 min” i „odczyt sprzed 2 min”; pasek jest czarny (w ciemnym motywie jasny), a od 80% w kolorze akcentu, z procentem obok, więc ostrzega nie tylko kolor. Fail-closed:
  - brak odczytu: „brak odczytu” i pusty pasek, a pod paskami podpowiedź, skąd limity pochodzą (pasek stanu Claude Code, plany Pro, Max i Ultra, po pierwszej odpowiedzi sesji w terminalu);
  - po resecie okna (wg `left` z serwera, liczonego dalej od pobrania stanu): „odnowiony” bez procentu;
  - okno starsze niż jego długość serwer w ogóle nie wysyła;
  - uszkodzony odczyt (procent spoza 0–100, ujemny czas) to „brak odczytu”;
  - wiek odczytu jest zawsze napisany;
  - `usage.hit`: nad paskami komunikat „Limit wyczerpany: Claude przerwał ostatnią turę z powodu limitu planu.”.
  Paski mają `role="progressbar"` z `aria-valuenow` (bez niego przy braku odczytu i odnowieniu) i opisem tekstowym. Kontrast wypełnienia do tła toru: ok. 12:1 w motywie jasnym i ok. 8,9:1 w ciemnym dla czerni/bieli, akcent 5,1:1 (jasny) i 4,9:1 (ciemny); próg dla elementów graficznych to 3:1.
- Dashboard odświeża się razem ze stanem i co `TICK_MS`, więc czasy „reset za” i „odczyt sprzed” żyją między pobraniami.

**4. Dźwięki**

- Nagłówek „Biblioteka N/50”.
- **Import z myinstants.** Pole na link i przycisk „Pobierz”. Aplikacja sprawdza link tym samym wzorcem co serwer, zanim cokolwiek wyśle. Potem:
  1. `GET sound_source?url=…` → MP3;
  2. `wav.js` zamienia MP3 na WAV (sekcja „Format dźwięku”);
  3. podgląd: odtwarzacz, długość i rozmiar, pole nazwy (1–24 znaki, podpowiedź z nazwy pliku albo strony);
  4. „Zapisz w bibliotece” → `POST sound?name=…`.
  Dźwięk dłuższy niż 15 s jest przycinany do 15 s, a podgląd mówi o tym wprost. Jedno kliknięcie „Pobierz” daje dokładnie jedno zapytanie do serwera.
- **Import z pliku.** Przycisk „Wybierz plik” (`accept="audio/*"`), dalej jak w krokach 2–4. To droga zapasowa, gdy myinstants odmówi (502 z 403/429) albo hosting nie ma cURL (501).
- **Lista.** Nazwa, długość, kto dodał i kiedy. Przyciski „Odsłuchaj” (`GET sound` z tokenem w nagłówku, odtwarzanie z `blob:`) i „Usuń” (potwierdzenie „Usunąć <nazwa>? Wiadomość i powiadomienia z tym dźwiękiem zagrają dzwonek”) → `POST sound_delete`.
- Przy pełnej bibliotece przycisk zapisu jest wyłączony z napisem „Biblioteka pełna: usuń dźwięk”.

**5. Tapeta**

- **Obecna tapeta.** `GET wallpaper` narysowane na `canvas` 400×300 w skali pikseli (`image-rendering: pixelated`), czarne na białym jak na płytce. 404 pokazuje „Brak tapety”.
- **Nowa tapeta.** „Wybierz obraz” (`accept="image/*"`). Opcje jak w `tools/img2rlcd.py`:
  - kadrowanie: „Wypełnij” (cover, domyślnie) albo „Zmieść” (contain, białe tło);
  - „Odcienie kropkami” (dithering Floyda–Steinberga, domyślnie) albo „Kontur” (próg 50%);
  - suwak jasności −50…+50, domyślnie 0 (przesunięcie jasności przed ditheringiem).
- Podgląd przelicza się na żywo i pokazuje dokładnie te bity, które pójdą na płytkę.
- Przezroczyste piksele idą na białe tło. Orientację zdjęcia z telefonu przeglądarka stosuje sama (`createImageBitmap` z `imageOrientation: "from-image"`).
- „Wyślij tapetę” → `POST wallpaper` z surowymi 15000 B. Serwer sam ustawia widok Tapeta.

### Odświeżanie stanu

- `GET state` przy starcie, po każdym udanym zapisie, po powrocie do karty przeglądarki (`visibilitychange`), po kliknięciu odświeżania i co 30 s, gdy karta jest widoczna. W tle aplikacja nie pyta serwera.
- Zapis blokuje swój przycisk do końca zapytania, więc podwójne kliknięcie nie wyśle dwóch wiadomości.

### Błędy

- `api.js` zamienia znane komunikaty serwera (sekcja „Kody błędów”) na zdania w języku aplikacji, np. `sound library is full (50): delete a sound first` → „Biblioteka pełna (50). Usuń dźwięk.”, po angielsku „Library full (50). Delete a sound.”
- Nieznany komunikat aplikacja pokazuje dosłownie z kodem HTTP, np. „Serwer: write failed (500)”, po angielsku „Server: write failed (500)”.
- Tak samo, dosłownie, aplikacja pokazuje trzy komunikaty blokady urządzeń: `app_lock: all N device places…`, `app_lock must be…` i `devices.json is corrupt…`. Docierają tylko do przeglądarki, która ma aplikację w pamięci podręcznej, a ich tłumaczenie wymagałoby nowej wersji plików aplikacji.
- Brak sieci: pasek „Brak połączenia z serwerem” nad treścią. Przyciski zapisu działają dalej i pokazują błąd, bo aplikacja nie odkłada zapisów na później.
- Błąd pokazuje się przy przycisku, który go wywołał (`role="alert"`), a sukces w komunikacie, który znika po 4 s (`aria-live="polite"`).

### Tryb offline i aktualizacje

- Service worker trzyma w pamięci podręcznej tylko pliki aplikacji. Zapytań do `api.php` nie zapisuje i nie przechwytuje.
- Przeglądarka bez miejsca w blokadzie urządzeń dostaje przy sprawdzaniu aktualizacji service worker, który czyści pamięć podręczną i się wyrejestrowuje (sekcja „Blokada urządzeń”).
- Nazwa pamięci podręcznej zawiera wersję, obecnie `tablica-v10`. Aplikacja czyta pliki najpierw z pamięci podręcznej, więc telefon pokaże nową funkcję dopiero po zmianie tej nazwy. Każda zmiana pliku z listy `FILES` w `sw.js` wymaga więc następnej nazwy (`tablica-v10` i dalej) i nowego wpisu w rejestrze `LEDGER` w `server/tests/app_test.mjs`. Test nie przepuszcza zmiany plików bez nowej nazwy, a jego komunikat podaje gotowy wpis do rejestru. Numery rosną i nie wracają (`v3` do `v6` zajmowały kolejno gałąź ustawień płytki, język aplikacji, obie razem i animacje Clawda; `v7` to scalenie animacji z ustawieniami płytki, `v8` własny odstęp nasłuchu, `v9` karta Dashboard, a `v10` Dashboard jako karta startowa z pomarańczowym Clawdem).
- Po wgraniu nowej wersji: stary cache znika przy aktywacji, a aplikacja pokazuje „Jest nowa wersja · Odśwież”. Dotknięcie „Odśwież” przeładowuje aplikację z nowymi plikami; gdy baner się nie pojawi (np. na iPhonie), zamknij aplikację całkiem i otwórz ją ponownie, czasem dwa razy: telefon najpierw pobiera nowy `sw.js`, a przejmuje go dopiero przy następnym otwarciu. Przez FTP `sw.js` idzie na końcu, bo telefon, który pobierze nowy `sw.js` przed resztą plików, zapisze stare pliki pod nową nazwą.

### Testy

- `node server/tests/app_test.mjs` sprawdza bez sieci i bez przeglądarki:
  - `wav.js`: nagłówek 44 B, 16 kHz mono 16-bit, szczyt −1 dBFS, przycięcie do 15 s; wynik przechodzi przez te same reguły co `wavCanonical` w `server/sounds.php`;
  - `dither.js`: 15000 B, kolejność bitów (najstarszy bit = lewy piksel, 1 = czarny), cover i contain, biel przy przezroczystości, próg;
  - `text.js`: licznik znaków i lista znaków nieobsługiwanych;
  - `api.js`: nagłówek `X-Token`, tłumaczenie błędów w obu językach, także komunikatów `POST notify`, zdanie dla każdego komunikatu z `api.php`, 401 → nieważny profil (atrapa `fetch`), treść `POST notify` z formularza (`notifyBody`), treść `POST settings` z `poll_s` liczbą i `null`, `pollSeconds` (granice, tylko cyfry ASCII, bez ułamków, znaków i cyfr spoza ASCII);
  - `anim.js`: id zgodne z `NOTIFY_ANIMS` w `api.php`, klatki 64×64 po 512 B, nazwa, czasy i klatka kluczowa każdej animacji, niepusta klatka kluczowa, `animById`, reguła rogu, `frameToRgba`, wycinek `frameToCutout` (tusz, oczy, przezroczyste tło) oraz `node tools/anim2c.mjs --check`;
  - `dash.js`: pasek limitu (brak, uszkodzony, odnowiony, ostrzeżenie od 80%, czas liczony dalej), słowa czasu, fakty sesji (tylko zgłoszone), postęp zadań (tylko wiarygodny), rodzaj oczekiwania oraz reguła Clawda `dashboardAnim` (czeka, pracuje, radość, która kończy się sama, spokój, sen);
  - `i18n.js`: język domyślny `en`, te same klucze w obu słownikach, żadnej pustej wartości, te same pola `{…}` w obu językach, formy liczby mnogiej dla obu języków, `t`, `tn`, `setLang`, `loadLang` i `saveLang` (także przy zablokowanym zapisie), polskie nazwy animacji równe nazwom w `anim.js`, każdy klucz użyty w `app.js`, `lib/api.js` i `lib/wav.js` istnieje w słowniku;
  - `index.html` i manifesty: każdy klucz `data-i18n` jest w obu słownikach, elementy z `data-i18n` są puste, strona nie ma własnych słów, `<head>` i `manifest.webmanifest` są po angielsku, `manifest-pl.webmanifest` różni się od niego tylko nazwą, krótką nazwą, opisem i językiem, opcje przełącznika to lista `LANGS`, opcje odstępu nasłuchu to `default` i `custom`, a `maxlength` pola sekund to liczba cyfr `POLL_MAX_S`;
  - odstęp nasłuchu: `POLL_DEFAULT_S` (10), `POLL_MIN_S` i `POLL_MAX_S` są równe w `api.php` i `firmware/main/app.h`, a obie granice także w `lib/api.js`;
  - `sw.js`: rejestr nazw pamięci podręcznej (`LEDGER`). Zmiana pliku aplikacji bez nowej nazwy `CACHE` kończy test błędem, bo telefon trzyma stare pliki, dopóki nazwa się nie zmieni (podsekcja „Tryb offline i aktualizacje”).
- Wygląd sprawdza się w przeglądarce: 375 px i desktop, motyw jasny i ciemny, po angielsku i po polsku, na lokalnym `php -S` z danymi syntetycznymi.
- Testy serwera, hooka i konwertera muszą przechodzić. Test serwera sprawdza też `POST usage` (token hooka, oba okna, zakresy, okna osobno, `hit` kasowany nowym odczytem, odczyt starszy niż okno i po resecie, uszkodzony blok), liczby sesji (`turns`, `agents`, `kind`, `tasks`: brak pola to brak wartości, odrzucone typy i zakresy, `rev` tylko przy zmianie, `turn_age`) oraz `POST notify`, liczniki `ev` oraz `POST settings`: każdy odrzucony `poll_s`, brak częściowego zapisu, powrót do domyślnego przez `null`, wpisane 10 jako domyślne, brak wzrostu `rev` bez zmiany i odczyt starych wartości z `state.json`.

## Powiadomienia o sesjach Claude

Szczegóły są w sekcjach:

- „API serwera”: akcja `POST notify`, pola `notify` i `ev` (podsekcja „Powiadomienia”);
- „Zachowanie płytki”: kiedy płytka powiadamia, ekran powiadomienia i róg widoku Sesje Claude (podsekcja „Powiadomienia o sesjach”);
- „Aplikacja PWA”: panel „Powiadomienia Claude” na karcie Płytka; róg listy sesji z karty Płytka zastąpił Clawd na karcie Dashboard.

Tu zostają opis funkcji, animacje, szczegóły zachowania i testy.

Płytka daje znać, że sesje Claude Code skończyły pracę albo czekają na Ciebie. Powiadomienie to ekran z animacją i tekstem na 6 s oraz wybrany dźwięk. Niezależnie od powiadomień widok Sesje Claude ma w prawym dolnym rogu małą animację, która pokazuje, co się dzieje.

### Tryby i zasięg

| Tryb | id | Kiedy płytka powiadamia |
|---|---|---|
| Cichy (domyślny) | `quiet` | nigdy; lista sesji działa jak dotąd |
| Gotowy na kolejne zadania | `ready` | „Gotowe”, gdy skończy ostatnia aktywna sesja; „Czeka na Ciebie”, gdy któraś sesja zaczyna czekać |
| Prymus | `each` | „Gotowe” po każdej skończonej odpowiedzi w każdej sesji; „Czeka na Ciebie” jak wyżej |

- Aktywna sesja to wiersz w stanie `working` (pracuje) albo `waiting` (czeka). Hook zgłasza `done` po każdej odpowiedzi Claude, więc przy jednej sesji oba tryby powiadamiają po każdej odpowiedzi.
- Zasięg `scope` ma dwie wartości: `sessions`, czyli powiadomienie tylko wtedy, gdy płytka pokazuje widok Sesje Claude, albo `all`, czyli na każdym widoku, także na tapecie i nad ekranem stanu. Domyślnie `all`.
- Powiadomienia działają tylko w trybie NASŁUCH. W trybach NA ŻĄDANIE i OFFLINE płytka sama nie pyta serwera, więc nie wie o zdarzeniach na bieżąco.
- Wiersz, który po przerwaniu odpowiedzi klawiszem Esc został na „pracuje”, wstrzymuje „Gotowe” w trybie `ready`, aż ta sesja skończy następną odpowiedź. To ograniczenie hooka z sekcji „Panel sesji Claude Code”.

### Ustawienia

Każde z dwóch powiadomień ma własny dźwięk i własną animację:

| Powiadomienie | Klucz | Dźwięk domyślny | Animacja domyślna |
|---|---|---|---|
| Gotowe | `done` | `bell` (Gong) | `happy` (Radość) |
| Czeka na Ciebie | `wait` | `chime` (Dzwonek) | `away` (Odchodzi) |

- Dźwięk wybiera się tak jak w wiadomości: `none` (cisza), ton wbudowany (`chime`, `bell`, `dinner`, `alert`) albo id z biblioteki.
- Animację wybiera się z sześciu opisanych w podsekcji „Animacje”.
- Ustawienia są jedne dla całej płytki. Zmienić je może każda osoba z rolą `user`.

### Animacje

Animacje to gotowe ilustracje robota Clawd z licencją MIT: pikselowe animacje SVG z projektu [marciogranzotto/clawd-tank](https://github.com/marciogranzotto/clawd-tank), folder `assets/svg-animations/`, commit `ee5c079` z 2026-10-27. Copyright (c) 2026 Marcio Granzotto Rodrigues. Pliki SVG leżą bez zmian w `assets/clawd/` razem z `LICENSE` (pełny tekst MIT) i `README.md` (źródło). Własnych postaci nie rysujemy w kodzie. Grafik Clawda z projektu clawd-on-desk dalej nie używamy, bo ich licencja („All Rights Reserved”) nie pozwala ich kopiować.

Płytka nie pokaże SVG, więc animacja to zestaw klatek 64×64 px zapisanych jak tapeta: wiersze od góry, najstarszy bit to lewy piksel, 1 oznacza czarny, 512 B na klatkę. Ani aplikacja, ani płytka nie renderują SVG w działaniu: klatki są wypalone w repo.

| id | Nazwa | Plik | Klatki, cykl | Ruch |
|---|---|---|---|---|
| `away` | Odchodzi | `clawd-going-away.svg` | 17, 3 s | Clawd kopie dołek i znika w nim; ostatnie 1,4 s widać sam pusty dołek |
| `alert` | Uwaga | `clawd-notification.svg` | 8, 4 s | Clawd podskakuje, nad nim miga wykrzyknik |
| `happy` | Radość | `clawd-happy.svg` | 20, 3 s | Clawd skacze z radości, wokół błyskają iskierki |
| `typing` | Pisanie | `clawd-working-typing.svg` | 12, 1,2 s | Clawd za laptopem stuka w klawisze, z ekranu wylatują bity |
| `idle` | Spokój | `clawd-idle-living.svg` | 62, 16 s | Clawd stoi, mruga, rozgląda się i od czasu do czasu przeciąga |
| `sleep` | Sen | `clawd-sleeping.svg` | 23, 6 s | Clawd śpi na leżąco, oddycha, a nad nim unoszą się litery Z |

- **Wypalanie klatek.** `node tools/clawd2anim.mjs` otwiera każdy SVG w Chrome bez okna, zatrzymuje animacje CSS i robi zrzut w stałych chwilach (krok 100–250 ms, zależnie od animacji). Okno 32×32 jednostek rysunku daje klatkę 64×64, czyli każdy piksel rysunku to kwadrat 2×2, w całych pikselach. Kolory zamienia na czerń i biel: ciało i rekwizyty są czarne, oczy zostają przerwami w ciele, cień znika (w `away` dołek zostaje czarny). Kolejne jednakowe klatki łączy w jedną, dłuższą. Przepisy (kadr, kolory, poprawki CSS, które wyrównują pętle) są na początku narzędzia. Wynik trafia do `server/lib/anim.js` między znaczniki BEGIN i END, spakowany (XOR z poprzednią klatką, PackBits, base64). `--sheet <png>` zapisuje też wszystkie klatki obok siebie do obejrzenia.
- Jedynym źródłem klatek jest `server/lib/anim.js`. `node tools/anim2c.mjs` zapisuje z niego `firmware/main/anim_frames.c` i `firmware/main/anim_frames.h`, więc płytka i podgląd w aplikacji mają te same bajty. `node tools/anim2c.mjs --check` niczego nie zapisuje i kończy się błędem, gdy któryś z tych plików nie zgadza się ze źródłem.
- Każda klatka ma własny czas (`ms` w `anim.js`); suma czasów to cykl z tabeli.
- Każda animacja ma klatkę kluczową (`key` w `anim.js`). Tę klatkę pokazuje aplikacja, gdy podgląd skończy grać albo gdy system prosi o ograniczenie ruchu: `away` 0, `alert` 1 (z wykrzyknikiem), `happy` 0, `typing` 0, `idle` 0, `sleep` 5 (z literą Z) (numeracja od 0).
- Aplikacja rysuje te same klatki, więc podgląd pokazuje dokładnie to, co płytka.

### Szczegóły zachowania

- **Domyślny zasięg to `all`.** Domyślny tryb `quiet` i tak nic nie pokazuje, a kto włączy inny tryb, dostaje powiadomienia od razu na każdym widoku.
- **Usunięty dźwięk.** `POST notify` z id spoza biblioteki dostaje 400. Ustawienie zapisane wcześniej, którego dźwięk usunięto później, zostaje, a płytka gra wtedy `chime`, tak jak przy wiadomości. Aplikacja pokazuje taki dźwięk jako kafel „Dźwięk z biblioteki (usunięty)”, żeby było widać, co jest zapisane.
- **Ruch w aplikacji.** Podglądy i Clawd z Dashboardu grają same najwyżej 5 s, potem stoją na klatce kluczowej (kryterium WCAG 2.2.2). Przy ograniczeniu ruchu w systemie stoją od razu, a wybrany podgląd gra raz.
- **`aria-disabled` zamiast `disabled`.** Przycisk „Zapisz ustawienia” bez zmian w formularzu zostaje w kolejności fokusu. Fokus nie znika więc po zapisie, a czytnik ekranu dalej znajduje przycisk.
- **Bez animacji, gdy sesja pracuje.** Animacja grająca w kółko przez całą pracę sesji rozpraszałaby, więc róg jest wtedy pusty. Stan „pracuje” widać nadal w wierszu sesji. `typing` zostaje do wyboru jako animacja powiadomienia.
- **Clawd w powiadomieniach.** Domyślnie `away` przy „Czeka na Ciebie” i `happy` przy „Gotowe”. W rogu przy skończonych sesjach gra `idle`, bo jest spokojniejszy niż `happy`; przy czekaniu `alert`, przy pustej liście `sleep`. Nieznane id animacji (np. `ring`, `check`, `burst`, `zzz`) płytka zamienia na animację domyślną, a aplikacja pokazuje grupę bez zaznaczenia, aż ktoś zapisze nowe. `typing` z laptopem jest najmniej czytelny, bo laptop zasłania większość ciała.
- **Róg tylko przy najwyżej 6 wierszach** na płytce. Przy 7 i 8 wierszach lista płytki zajmuje to miejsce. W aplikacji tę rolę pełni Clawd na karcie Dashboard (inna reguła: przy pracy gra `typing`).
- **Przewijanie paska dźwięków.** Zapisany dźwięk może leżeć poza ekranem po prawej, więc aplikacja przewija sam pasek, nigdy całą stronę.
- **`GET state` zwraca też `ev`**, tak jak `poll`, żeby stan liczników dało się sprawdzić bez płytki.
- **Starszy serwer.** Bez poprawnych pól `notify` i `ev` płytka nie powiadamia, a aplikacja zamiast formularza prosi o nowy `api.php`. Róg listy na płytce i Clawd w aplikacji działają także wtedy, bo zależą tylko od listy sesji.

### Testy powiadomień

- `php server/tests/api_test.php`:
  - walidacja `POST notify`, każdy komunikat 400;
  - ustawienia domyślne i zera bez pól w `state.json`;
  - wzrost `rev`, pola w `state` i `poll`;
  - liczniki: `working`→`done` z `all` i bez `all` (gdy druga sesja pracuje), `waiting`→`done`, `done`→`done`, nowy wiersz `done`, nowy wiersz `waiting`, `working`→`waiting`, `end` ostatniej aktywnej sesji, zmiana samego tytułu.
- Test `notify_test.c`: każdy warunek z listy „Kiedy gra” (sekcja „Zachowanie płytki”, podsekcja „Powiadomienia o sesjach”), pierwszeństwo, pierwsza synchronizacja, tryby pracy, starszy serwer, szczegół i odmiana „sesje”/„sesji” oraz reguła rogu.
- `node server/tests/app_test.mjs`: liczba i rozmiar klatek każdej animacji, `node tools/anim2c.mjs --check`, treść `POST notify` z formularza i reguła rogu.
- Przegląd wyglądu w przeglądarce dla panelu i rogu listy.
- Na płytce: oba powiadomienia w obu zasięgach, róg w trzech stanach i pusty przy pracy sesji, dźwięk z biblioteki i ton wbudowany.

## Blokada urządzeń

Kod: `server/applock.php` (wspólne funkcje), `server/gate.php` (pliki aplikacji) i `deviceLock()` w `server/api.php`. Test: `php server/tests/lock_test.php`.

Blokada wpuszcza do aplikacji tylko kilka przeglądarek. Każda inna dostaje pod adresem aplikacji zwykłą stronę 404, jakby aplikacji tam nie było. Płytka, hook i polecenia z terminala działają dalej na samych tokenach.

### Włączanie

- Wpis w `config.php`: `'app_lock' => ['devices' => 2]`. Liczba to liczba miejsc.
- Brak wpisu, `null` albo `0` wyłącza blokadę. Serwer działa wtedy jak przed jej dodaniem i nie tworzy `devices.json`.
- Każda inna postać wpisu zamyka aplikację: tekst zamiast liczby, `false`, literówka w kluczu, dodatkowy klucz albo liczba ujemna. `gate.php` odpowiada wtedy 404, a `api.php` błędem 500 dla każdej roli, także dla płytki. Serwer nie zgaduje, co miało stać w konfiguracji.

### Miejsca

- Miejsce zajmuje przeglądarka, w której ktoś się zaloguje tokenem osoby z `users`, póki są wolne miejsca. Logowaniem jest każde zapytanie do `api.php` z takim tokenem, zwykle `GET me` z ekranu „Dodaj osobę”. Telefon z zapisanym profilem zajmie miejsce sam, przy pierwszym zapytaniu po włączeniu blokady.
- Serwer daje tej przeglądarce ciasteczko `__Secure-tablica_device`: 64 znaki szesnastkowe z `random_bytes(32)`, z flagami `HttpOnly`, `Secure` i `SameSite=Lax`, dla ścieżki katalogu aplikacji, ważne 400 dni. Każde użycie aplikacji liczy 400 dni od nowa.
- `devices.json` trzyma dla każdego miejsca tylko skrót SHA-256 ciasteczka, imię osoby i datę zajęcia. Samego ciasteczka serwer nie zapisuje.
- Miejsce należy do przeglądarki, nie do osoby. W przeglądarce z miejscem można się zalogować tokenem każdej osoby.
- Gdy miejsca są zajęte, przeglądarka bez miejsca dostaje od `api.php` błąd 404 `app_lock: all N device places are taken by other browsers`, także z poprawnym tokenem. Bez poprawnego tokenu dostaje jak dotąd 401 `bad token`. Obcy bez tokenu nie zajmie więc miejsca, ale do zapełnienia miejsc widzi ekran logowania.
- Przez 10 s od zajęcia miejsca zapytanie bez ciasteczka od tej samej osoby przechodzi, ale nie zajmuje kolejnego miejsca. Aplikacja po zalogowaniu wysyła kilka zapytań naraz, a ciasteczko niesie tylko odpowiedź na pierwsze.
- O miejscu serwer decyduje pod blokadą `flock` na `data/.lock`, po ponownym odczycie `devices.json`. Dwie przeglądarki logujące się w tej samej chwili nie zajmą trzeciego miejsca.
- Mniejsza liczba w `config.php` zostawia miejsca najstarszym przeglądarkom. Powrót do większej liczby przywraca pozostałe bez ponownego logowania.
- Usunięcie `devices.json` zwalnia wszystkie miejsca. Przeglądarka ze starym ciasteczkiem przy następnym logowaniu zajmie miejsce znowu, z tym samym ciasteczkiem.
- Uszkodzony `devices.json` zamyka aplikację. `gate.php` odpowiada 404 każdemu, a `api.php` przeglądarkom błędem 500 `devices.json is corrupt: fix it or delete it to free every place`. Serwer nie nadpisuje pliku. Płytka, hook i curl działają dalej. Pusty plik to pusta lista.

### Kto jest przeglądarką

- Blokada dotyczy tylko przeglądarek. Serwer uznaje zapytanie za przeglądarkowe, gdy ma nagłówek `Sec-Fetch-Site` albo `Sec-Fetch-Mode` albo gdy `User-Agent` zaczyna się od `Mozilla/`. Nagłówki `Sec-Fetch-*` współczesna przeglądarka dodaje sama, a skrypt strony nie może ich usunąć.
- Płytka, hook, `tools/img2rlcd.py` i curl tych nagłówków nie wysyłają. Nie zajmują miejsc, a `api.php` obsługuje je jak bez blokady.

### Pliki aplikacji

- `.htaccess` kieruje do `gate.php` każde zapytanie poza `api.php`. Bez modułu `mod_rewrite` pliki idą bezpośrednio, a blokadę trzyma tylko `api.php`.
- `gate.php` wydaje tylko zamkniętą listę plików, `APPLOCK_FILES` w `server/applock.php`: pliki z listy `FILES` w `sw.js` i sam `sw.js`. Na każde inne zapytanie odpowiada 404, także na katalogi, ścieżki z `..` i metody inne niż `GET` i `HEAD`.
- Przy włączonej blokadzie:
  - przeglądarka z ciasteczkiem z listy dostaje plik i odnowione ciasteczko;
  - gdy są wolne miejsca, plik dostaje każdy, ale `gate.php` nie daje ciasteczek: miejsce przyznaje tylko `api.php` po zalogowaniu;
  - gdy miejsca są zajęte, każdy bez ciasteczka z listy dostaje 404, także curl.
- Zainstalowana aplikacja w przeglądarce bez miejsca sprawdza aktualizację zapytaniem `GET sw.js` z nagłówkiem `Service-Worker: script`. Dostaje wtedy zamiast `sw.js` krótki service worker, który usuwa pamięć podręczną `tablica-*`, wyrejestrowuje się i przeładowuje okna aplikacji. Po przeładowaniu przeglądarka widzi 404.
- Plik wychodzi z nagłówkami `Cache-Control: private, no-cache` i `Vary: Cookie`, żeby żaden pośrednik nie podał go przeglądarce bez miejsca. `gate.php` obsługuje `If-Modified-Since` (odpowiedź 304) i `HEAD`.
- Adres katalogu bez ukośnika na końcu przekierowuje (301) na adres z ukośnikiem, tylko gdy przeglądarka ma dostęp.
- Strona 404 wygląda jak zwykła strona 404 serwera i ma nagłówek `Cache-Control: no-store`.
- Brak albo błąd `config.php`, nieoczekiwany `SCRIPT_NAME` po przekierowaniu, błędny `app_lock` i uszkodzony `devices.json` dają 404. Przyczynę `gate.php` zapisuje w logu błędów PHP z przedrostkiem `tablica gate:`.
- Pliki z listy `FilesMatch` w `.htaccess` (`config.php`, `config.example.php`, `sounds.php`, `applock.php`), katalog `data/` i pliki `.ht*` dają dalej 403. Serwer odmawia dostępu, zanim zadziała przekierowanie.
- Plik wydany przez `gate.php` ma w odpowiedzi 200 nagłówek `Vary: Cookie`. Gdy go brak, hosting pomija przekierowanie i blokadę trzyma tylko `api.php`. Gdy adres aplikacji daje 404 mimo dostępu, przekierowanie trafia w złą ścieżkę. Pomaga wtedy linia `RewriteBase /<katalog>/` w `.htaccess`, czyli ścieżka katalogu aplikacji.

### Ograniczenia

- Miejsce zajmuje każda przeglądarka, w której ktoś się zaloguje, także okno prywatne.
- Na iPhonie Safari i aplikacja z ekranu początkowego mają osobne ciasteczka. iOS 17.2 i nowsze kopiują je raz, przy „Dodaj do ekranu początkowego”, więc aplikacja dodana po zalogowaniu w Safari dzieli z nim miejsce. Logowanie w Safari po dodaniu aplikacji zajmuje już drugie miejsce. Najprościej logować się tylko w aplikacji z ekranu początkowego.
- Przeglądarka, która blokuje ciasteczka, zajmuje nowe miejsce przy każdym logowaniu po upływie 10 s, aż miejsca się skończą. Pomaga usunięcie `devices.json`.
- Przeglądarki pobierają manifest bez ciasteczek, a Safari może tak pobierać ikonę `apple-touch-icon`. Przy zajętych miejscach te zapytania dostają 404. Sparowanej przeglądarce to nie szkodzi, bo ma te pliki w pamięci podręcznej service workera. Aplikację warto jednak dodać do ekranu początkowego, zanim miejsca się zapełnią.
- Kto wyśle `GET sw.js` z nagłówkiem `Service-Worker: script`, zobaczy, że pod adresem działa aplikacja z pamięcią podręczną `tablica-`. Żadnego innego pliku nie dostanie.
- Trzy komunikaty blokady aplikacja pokazuje dosłownie, po angielsku (podsekcja „Błędy” w sekcji „Aplikacja PWA”).

### Testy blokady

`php server/tests/lock_test.php` uruchamia `php -S` z `server/tests/lock_router.php`. Router montuje aplikację w podkatalogu `/app/sub/`, jak na hostingu, i zastępuje `.htaccess`. Test sprawdza:

- bez blokady i przy `0`: każdy plik z listy z właściwym typem, 404 dla wszystkiego spoza listy, `HEAD`, 301, 304, brak ciasteczek i brak `devices.json`;
- zajmowanie miejsc: atrybuty ciasteczka, zawartość `devices.json` bez samego ciasteczka, odnawianie, 10 s dla tej samej osoby i decyzję pod blokadą `flock`;
- zajęte miejsca: 404 dla trzeciej przeglądarki w `gate.php` i w `api.php`, service worker usuwający aplikację, płytkę i curl z tokenem bez zmian;
- zmianę liczby miejsc oraz usunięty, uszkodzony i pusty `devices.json`;
- błędny `app_lock` i błędny `config.php`;
- zgodność `APPLOCK_FILES` z listą `FILES` w `sw.js`.

## Prywatność i bezpieczeństwo

- Płytka sama nawiązuje każde połączenie: wychodzące HTTPS do serwera. Mac i płytka ze sobą nie rozmawiają. Nie ma lokalnego serwera, mDNS (rozgłaszania usług w sieci lokalnej) ani API w sieci domowej. USB służy tylko do wgrania programu.
- Płytka, hook i każda osoba mają osobne tokeny. Tokeny leżą w trzech miejscach:
  - `config.php`, zablokowany w `.htaccess`;
  - `firmware/main/secrets.h`, wykluczony z repozytorium w `.gitignore`;
  - `~/.config/rlcd/`, z uprawnieniami 600.
- `server/.htaccess` wyłącza listowanie katalogów i blokuje `config.php`, `config.example.php`, `sounds.php` i `applock.php`. Pozostałe zapytania poza `api.php` kieruje do `gate.php`, który wydaje tylko pliki aplikacji. `server/data/.htaccess` blokuje cały katalog danych.
- Blokada urządzeń zapisuje w `devices.json` tylko skrót SHA-256 ciasteczka, imię i datę zajęcia miejsca. Ciasteczko ma flagę `HttpOnly`, więc skrypt strony go nie odczyta. Przy zajętych miejscach obca przeglądarka widzi pod adresem aplikacji tylko stronę 404 (sekcja „Blokada urządzeń”). Na hostingu bez obsługi `.htaccess` trzeba przenieść `data_dir` poza `public_html`.
- Hook wysyła alias, początek identyfikatora sesji, tytuł sesji, stan oraz liczby i rodzaje: liczbę tur, liczbę pracujących subagentów, rodzaj oczekiwania (pytanie, plan, zgoda) i postęp listy zadań jako „zrobione z wszystkich”. Pasek stanu wysyła procenty limitów z czasami resetu, nazwę modelu i zapełnienie kontekstu w procentach. Nie wysyła ścieżki, poleceń, kodu, treści rozmowy ani nazw kroków zadań. Test hooka sprawdza, że żaden z tych tekstów nie trafia do `state.json`. Aliasy i tytuły sesji widać na płytce, w aplikacji i na serwerze, więc sesji z poufną nazwą, np. z nazwą klienta, warto nadać w aplikacji Claude neutralny tytuł.
- Wiadomości, tapety i dźwięki zostają na własnym hostingu.
- Open-Meteo dostaje tylko współrzędne z `config.php`.
- myinstants.com dostaje zapytanie tylko wtedy, gdy ktoś importuje dźwięk.
