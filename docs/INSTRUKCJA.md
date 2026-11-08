# Instrukcja wdrożenia

Instrukcja krok po kroku: serwer, płytka, hook Claude Code i sterowanie z terminala. Opis projektu po angielsku jest w [README](../README.md), a specyfikacja w [SPEC.md](SPEC.md). Ścieżki w tej instrukcji liczą się od głównego folderu repozytorium.

Płytka Waveshare ESP32-S3-RLCD-4.2 jako tablica na biurko: zegar z pogodą, wiadomości od domowników z dźwiękiem, tapety i stan sesji Claude Code. Ekran to RLCD (odblaskowy LCD, który świeci światłem z otoczenia jak papier, bez podświetlenia): 400×300 punktów, czerń i biel.

```
Ty (curl, dowolny klient HTTP) ──HTTPS──▶ api.php na zwykłym hostingu PHP ◀──HTTPS── płytka w sieci Wi-Fi IoT
hook Claude Code na Macu       ──HTTPS──▶ api.php
```

Płytka nie łączy się z Makiem ani z niczym w sieci lokalnej. Sama pyta serwer o zmiany: domyślnie co 10 s (w aplikacji ustawisz własny odstęp, od 5 s do 1 h) lub na żądanie. Potrzebuje tylko internetu (DNS i HTTPS), więc działa w sieci IoT z pełną izolacją klientów.

| W repo | Co to jest |
|---|---|
| `firmware/` | program płytki (C, ESP-IDF 5.5) |
| `server/` | `api.php`: API na zwykły hosting PHP, bez bazy danych, stan w plikach |
| `hook/` | hook Claude Code, który zgłasza stan sesji na tablicę |
| `tools/img2rlcd.py` | zamiana zdjęcia na tapetę 400×300 |
| `docs/SPEC.md` | specyfikacja: tryby, widoki, API, formaty plików |

## Co pokazuje płytka

**Widoki.** Przytrzymaj BOOT, żeby przejść do następnego, albo ustaw widok z serwera.

| Widok | Co widać |
|---|---|
| Pulpit | duży zegar, data, temperatura i wilgotność w pokoju, pogoda na zewnątrz, licznik sesji Claude |
| Wiadomość | od kogo, o której i treść ostatniej wiadomości, największą czcionką, jaka się zmieści |
| Tapeta | obraz na cały ekran |
| Sesje Claude | każda sesja Claude Code osobno: tytuł jak w aplikacji Claude (bez tytułu alias projektu), postęp listy zadań (np. „2/5”), stan (pracuje, czeka, gotowe) i od kiedy; sesja, która czeka na Ciebie, jest w negatywie |

**Limit planu.** Na dole widoku Sesje Claude płytka rysuje pasek limitu 5 h z tekstem, np. „5 h 63% · reset za 1 h 20 min · odczyt sprzed 2 min”. Bez odczytu paska nie ma, po resecie okna pasek mówi „odnowiony” bez procentu, a wiek odczytu jest zawsze w tekście. Limity zgłasza pasek stanu Claude Code (rozdział 3), tylko z planów Pro, Max i Ultra.

**Tryby.** Krótkie KEY przełącza tryb, a jego nazwa jest w górnym pasku.

| Tryb | Wi-Fi | Skąd zmiany |
|---|---|---|
| NASŁUCH | stale włączone | płytka pyta serwer domyślnie co 10 s; własny odstęp (od 5 s do 1 h) ustawisz w aplikacji |
| NA ŻĄDANIE | wyłączone | krótkie BOOT: płytka łączy się (do 15 s), pobiera zmiany i znów wyłącza Wi-Fi |
| OFFLINE | wyłączone | brak; zegar idzie z RTC (zegara z własną baterią podtrzymującą), czujnik w pokoju działa |

Tryb, widok, ostatnia wiadomość, tapeta i pogoda przetrwają restart, bo płytka trzyma je w NVS (pamięci ustawień we flashu).

**Przyciski.**

| Przycisk | Krótko | Przytrzymany 2 s |
|---|---|---|
| KEY | następny tryb | ekran stanu: tryb, widok, Wi-Fi, bateria, ostatnia synchronizacja i błąd; znika sam po 10 s |
| BOOT | sprawdź serwer teraz | następny widok |

Środkowy przycisk (PWR) działa sprzętowo i program go nie używa. Przytrzymany wyłącza płytkę, a krótkie naciśnięcie włącza ją z powrotem. Tryb i widok zostają zapamiętane.

## 1. Serwer

Potrzebny jest hosting z PHP 8.1 lub nowszym, na którym PHP może zapisywać pliki w swoim folderze. Baza danych nie jest potrzebna. Rozszerzenie cURL przydaje się tylko do importu dźwięków z myinstants.com.

1. Wygeneruj osobny token dla płytki, dla hooka i dla każdego użytkownika:
   ```bash
   php -r 'echo bin2hex(random_bytes(24)), "\n";'
   ```
2. Skopiuj `server/config.example.php` jako `server/config.php` i uzupełnij:
   - `tokens.device` (ten sam trafi do `secrets.h` płytki) i `tokens.hook` (trafi do `~/.config/rlcd/env` na Macu);
   - `users`: każda osoba ma imię (do 20 znaków, płytka pokazuje je jako nadawcę) i własny token;
   - `location`: nazwa miejsca i współrzędne do pogody; `null` w `lat` i `lon` ukrywa pogodę;
   - opcjonalnie `data_dir`: folder danych poza `public_html`, jeśli hosting na to pozwala.
3. Wgraj przez FTP do `public_html/rlcd/` pliki `api.php`, `sounds.php`, `applock.php`, `gate.php`, `config.php`, `.htaccess` i `data/.htaccess` oraz pliki aplikacji PWA: `index.html`, `app.css`, `app.js`, `sw.js`, oba manifesty i foldery `lib/` i `icons/`. `sw.js` wgraj na końcu, a po wgraniu przeładuj aplikację (baner „Jest nowa wersja · Odśwież”; gdy go nie ma, zamknij aplikację całkiem i otwórz ponownie). `.htaccess` kieruje do `gate.php` każde zapytanie poza `api.php`, a `gate.php` wydaje tylko pliki aplikacji. Pliki z kropką na początku klient FTP często ukrywa, więc włącz pokazywanie ukrytych plików. Folderu `tests/` nie wgrywaj.
4. Na Macu zapisz adres i tokeny w plikach, które może czytać tylko Twoje konto:
   ```bash
   mkdir -p ~/.config/rlcd
   ```
   Plik `~/.config/rlcd/env`:
   ```
   RLCD_URL=https://twoja-domena.pl/rlcd/api.php
   RLCD_HOOK_TOKEN=<tokens.hook z config.php>
   ```
   Plik `~/.config/rlcd/user-header` z Twoim tokenem z `users`:
   ```
   X-Token: <token użytkownika>
   ```
   ```bash
   chmod 600 ~/.config/rlcd/env ~/.config/rlcd/user-header
   ```
5. Sprawdź połączenie. Odpowiedź `{"ok":true,"name":"Ja"}` znaczy, że serwer i token działają:
   ```bash
   . ~/.config/rlcd/env
   ```
   ```bash
   curl -fsS -H @"$HOME/.config/rlcd/user-header" "$RLCD_URL?a=me"
   ```
6. Po pierwszej wiadomości (rozdział 4) sprawdź, że konfiguracji i danych nie da się pobrać z zewnątrz. Pierwsza wiadomość tworzy plik `data/state.json`. Wynik `403` albo `404` jest w porządku, `200` nie:
   ```bash
   curl -s -o /dev/null -w '%{http_code}\n' "${RLCD_URL%api.php}config.php"
   ```
   ```bash
   curl -s -o /dev/null -w '%{http_code}\n' "${RLCD_URL%api.php}data/state.json"
   ```
   `200` znaczy, że serwer pomija `.htaccess`. Wtedy przenieś `data_dir` poza `public_html`.

### Blokada urządzeń (opcjonalnie)

Blokada wpuszcza do aplikacji PWA tylko kilka przeglądarek. Miejsca zajmują pierwsze przeglądarki, w których ktoś się zaloguje, a każda inna dostaje pod adresem aplikacji stronę 404. Płytka, hook i polecenia z terminala działają bez zmian, bo nie są przeglądarkami. Szczegóły są w `docs/SPEC.md`, w rozdziale „Blokada urządzeń”.

1. Na działający serwer wgraj nowe pliki w tej kolejności: `applock.php`, `gate.php`, `api.php`, `.htaccess`. `gate.php` i `api.php` potrzebują `applock.php`, a `.htaccess` kieruje zapytania do `gate.php`, więc przy innej kolejności serwer przez chwilę odpowiada błędami. Potem sprawdź, kto wydaje pliki aplikacji:
   ```bash
   curl -sI "${RLCD_URL%api.php}" | grep -i -E '^(http|vary)'
   ```
   - `200` i `vary: Cookie`: pliki wydaje `gate.php`, wszystko w porządku.
   - `200` bez `Cookie`: hosting pomija przekierowanie z `.htaccess`. Sprawdź, czy `.htaccess` się wgrał. Bez przekierowania blokadę trzyma tylko `api.php`: obca przeglądarka zobaczy ekran logowania, ale się nie zaloguje.
   - `404`: przekierowanie trafia w złą ścieżkę. Dopisz w `.htaccess` pod `RewriteEngine On` linię `RewriteBase /rlcd/`, czyli ścieżkę folderu aplikacji. Jeśli 404 zostaje, przyczynę `gate.php` zapisał w logu błędów PHP z przedrostkiem `tablica gate:`.
2. Dopisz w `config.php` liczbę miejsc:
   ```php
   'app_lock' => ['devices' => 2],
   ```
   Brak wpisu albo `0` wyłącza blokadę. Wpis w innej postaci zatrzymuje cały serwer: aplikacja dostaje 404, a `api.php` odpowiada błędem 500, także płytce i hookowi.
3. Zaloguj się w aplikacji na każdym urządzeniu, które ma mieć miejsce. Na iPhonie najpierw dodaj aplikację do ekranu początkowego i loguj się tylko w niej. Safari ma osobne ciasteczka, więc logowanie w Safari zajmie drugie miejsce. Przeglądarki, w których aplikacja ma już zapisany profil, zajmą miejsca same przy pierwszym zapytaniu, w kolejności zapytań.
4. Przy zajętych miejscach sprawdź, co dostaje obca przeglądarka. curl nie ma ciasteczka urządzenia, więc wynik to `404` (przy wolnych miejscach `200`):
   ```bash
   curl -s -o /dev/null -w '%{http_code}\n' "${RLCD_URL%api.php}"
   ```
   Okno prywatne pokaże wtedy „Not Found”. W `data/devices.json` (zobaczysz go przez FTP) jest tyle wpisów, ile miejsc, z imionami osób.
5. Żeby zwolnić miejsca, usuń przez FTP `data/devices.json`. Przeglądarki z zapisanym profilem zajmą je znowu przy najbliższym zapytaniu. Przy wymianie urządzenia najpierw usuń na starym profil („Osoby” → „Usuń z tego urządzenia”).

## 2. Płytka

Potrzebne jest ESP-IDF 5.5, np. w `~/esp/esp-idf`, i Wi-Fi 2.4 GHz z dostępem do internetu. Płytce wystarczy DNS i HTTPS do hostingu. Izolacja klientów w sieci IoT nie przeszkadza. Zegar ustawia się z czasu serwera, więc NTP (usługa synchronizacji czasu) też nie jest potrzebne.

1. Skopiuj `firmware/main/secrets.h.example` jako `firmware/main/secrets.h` i uzupełnij:
   - `WIFI_SSID` i `WIFI_PASS`;
   - `API_URL`: pełny adres `api.php` z `https://`;
   - `DEVICE_TOKEN`: wartość `tokens.device`.

   Hasło wpisz dokładnie. Spacja skopiowana na końcu wystarczy, żeby płytka się nie połączyła.
2. Podłącz płytkę przez USB, zbuduj i wgraj program:
   ```bash
   . ~/esp/esp-idf/export.sh
   ```
   ```bash
   cd firmware && idf.py build
   ```
   ```bash
   idf.py -p /dev/cu.usbmodem1101 flash monitor
   ```
   - Port sprawdzisz poleceniem `ls /dev/cu.usbmodem*`.
   - `monitor` pokazuje logi płytki; wychodzisz z niego przez Ctrl+].
   - Pierwsze budowanie pobiera z rejestru Espressif sterownik kodeka audio (`esp_codec_dev`).
   - Folder `build` zajmuje ok. 180 MB. Żeby budować na innym dysku, dodaj `-B <folder>` do każdego polecenia `idf.py`.
3. Odłącz USB i zasil płytkę z ładowarki albo z akumulatora 18650. Płytka połączy się z Wi-Fi i od razu zapyta serwer.

**Pierwsze uruchomienie: co sprawdzić.** Po każdej poprawce zbuduj i wgraj program jeszcze raz.

- Obraz do góry nogami: w `firmware/main/display.c:16` zmień `U8G2_R1` na `U8G2_R3`.
- Obraz w negatywie, czyli jasne napisy na ciemnym tle: w `firmware/components/u8g2_st7305/u8g2_st7305.c:139` przełącz komendę między `0x21` (z odwracaniem kolorów, ustawione teraz) a `0x20` (bez odwracania). Na płytce testowej lepiej wygląda `0x21`.
- Głośność ustawia `VOLUME` w `firmware/main/audio.c:18` (0–100, teraz 85).
- Płytka nie wykrywa akumulatora sama, więc teraz pokazuje „USB” zamiast procentów. Po włożeniu akumulatora 18650 ustaw `BATTERY_FITTED` na `1` w `firmware/main/board.h` i wgraj program jeszcze raz.

**Powrót do programu fabrycznego.** Obraz `ESP32-S3-RLCD-4.2_FactoryOnly.bin` (wersja 1.1.0) pobierzesz z [dokumentacji](https://docs.waveshare.com/ESP32-S3-RLCD-4.2) albo z [repozytorium](https://github.com/waveshareteam/ESP32-S3-RLCD-4.2) Waveshare:

```bash
esptool.py -p /dev/cu.usbmodem1101 write_flash 0x0 ESP32-S3-RLCD-4.2_FactoryOnly.bin
```

## 3. Panel sesji Claude Code (hook)

Hook zgłasza na serwer stan każdej sesji Claude Code. Wysyła alias projektu, tytuł sesji z aplikacji Claude, 8 znaków identyfikatora sesji i stan, a do tego same liczby i rodzaje: liczbę tur, liczbę pracujących subagentów, na co czeka sesja (pytanie, plan, zgoda) i postęp listy zadań jako „zrobione z wszystkich”. Bez ścieżek, treści rozmowy i nazw kroków. Tytuł płytka pokazuje zamiast aliasu i przycina wielokropkiem, gdy jest za długi. Gdy sesja nie ma tytułu, widać alias.

1. Zainstaluj skrypty (do liczenia postępu zadań i paska stanu potrzebny jest `jq`):
   ```bash
   mkdir -p ~/.claude/hooks && cp hook/rlcd-hook.sh hook/rlcd-statusline.sh ~/.claude/hooks/ && chmod +x ~/.claude/hooks/rlcd-hook.sh ~/.claude/hooks/rlcd-statusline.sh
   ```
2. Nadaj projektom krótkie aliasy w pliku `~/.config/rlcd/aliases`. Każda linia to ścieżka, spacja i alias:
   ```
   # ścieżka projektu, potem alias
   ~/Projekty/tablica tablica
   ~/Projekty/Mój blog blog
   ```
   - Alias ma 1–24 znaki: małe litery a–z, cyfry i `-`.
   - Ścieżka może zawierać spacje, a `~/` oznacza katalog domowy.
   - Projekt bez aliasu widać jako `p-` i 4 znaki skrótu ścieżki (np. `p-3fa1`), więc nazwa folderu nie opuszcza Maca.
   - Alias widać na płytce i na serwerze, więc nie wpisuj w nim nazw klientów ani niczego poufnego.
   - Tytuł sesji też widać na płytce, w aplikacji i na serwerze. Sesji z poufną nazwą nadaj w aplikacji Claude neutralny tytuł.
3. Dopisz wpisy z `hook/settings-snippet.json` do sekcji `"hooks"` w `~/.claude/settings.json`, a wpis `statusLine` (limity planu i model sesji) na wierzch pliku. Jeśli masz już własny `statusLine`, połącz oba skrypty w jednym poleceniu zamiast go zastępować. Jeśli masz już hooki dla tych samych zdarzeń (np. `Stop`), dodaj nowy wpis do istniejącej tablicy, zamiast ją zastępować.
4. Sprawdź działanie w folderze projektu:
   ```bash
   ~/.claude/hooks/rlcd-hook.sh check
   ```
   Skrypt pokaże projekt, alias i serwer, wyśle stan „gotowe” i potwierdzi wynik komunikatem `OK: the sessions view lists <alias> as done after the next poll.`

| Co się dzieje w Claude Code | Stan na płytce |
|---|---|
| wysyłasz prompt albo Claude używa narzędzia | pracuje |
| Claude zadaje pytanie, pokazuje plan do akceptacji albo prosi o zgodę | czeka (wiersz w negatywie) |
| Claude kończy odpowiedź | gotowe |
| Claude uruchamia subagenta | stan bez zmiany, liczba subagentów rośnie |
| tura zatrzymana limitem planu (StopFailure, `rate_limit`) | gotowe, a pasek limitu mówi „limit wyczerpany” |
| zamykasz sesję | wiersz znika |
| zmieniasz tytuł sesji w aplikacji Claude | nowy tytuł przy najbliższym zdarzeniu tej sesji |

Postęp zadań hook liczy z `TodoWrite`, `TaskCreate`/`TaskUpdate` i `update_plan` z aplikacji Claude; sesja bez listy nie ma postępu. Limity planu i zapełnienie kontekstu zgłasza `rlcd-statusline.sh` po każdej odpowiedzi sesji w terminalu (pola `rate_limits` są tylko w planach Pro, Max i Ultra i tylko w nowszych wersjach Claude Code: zaktualizuj poleceniem `claude update`). Aplikacja Claude (desktop) nie uruchamia skryptu paska stanu, więc limity przychodzą z terminala.

Przypomnienie o bezczynności nie zmienia stanu. W czasie pracy hook nic nie wypisuje i zawsze kończy się sukcesem, więc awaria serwera nie przeszkadza Claude Code.

## 4. Sterowanie z terminala

Najpierw wczytaj adres i zdefiniuj skrót. Token jest czytany z pliku, więc nie trafia do historii poleceń:

```bash
. ~/.config/rlcd/env; rlcd() { curl -sS --fail-with-body -H @"$HOME/.config/rlcd/user-header" "$@"; }
```

Blokada urządzeń z rozdziału 1 tych poleceń nie dotyczy, bo curl nie jest przeglądarką.

**Wiadomość z dźwiękiem.** Płytka od razu przełącza się na widok Wiadomość.

```bash
rlcd -H 'Content-Type: application/json' -d '{"text":"Obiad gotowy","sound":"dinner"}' "$RLCD_URL?a=message"
```

Tekst może mieć od 1 do 280 znaków. Czcionka płytki ma polskie litery, ale nie ma emoji ani znaków `—` i `…`, więc te się nie wyświetlą. Wbudowane dźwięki to `none`, `chime`, `bell`, `dinner` i `alert`. Dźwięki z biblioteki mają identyfikatory `s1`, `s2` i kolejne (opis niżej).

**Widok:** `dashboard` (Pulpit), `message` (Wiadomość), `wallpaper` (Tapeta) albo `sessions` (Sesje Claude).

```bash
rlcd -H 'Content-Type: application/json' -d '{"view":"sessions"}' "$RLCD_URL?a=view"
```

**Tapeta** z dowolnego zdjęcia (JPEG, PNG, HEIC i inne formaty, które czyta macOS). Podgląd `tapeta.bmp` otworzysz w Podglądzie.

```bash
python3 -I tools/img2rlcd.py zdjecie.heic tapeta.bin --preview tapeta.bmp
```
```bash
rlcd -H 'Content-Type: application/octet-stream' --data-binary @tapeta.bin "$RLCD_URL?a=wallpaper"
```

Domyślnie obraz wypełnia ekran, a nadmiar jest przycinany. `--fit contain` pokazuje cały obraz na białym tle. `--no-dither` wyłącza dithering, czyli oddawanie szarości gęstością czarnych kropek; to lepsze do tekstu i rysunków.

**Dźwięk z myinstants.com do biblioteki.** Płytka odtwarza tylko WAV. Serwer pobiera więc MP3, Mac konwertuje go do WAV, a WAV wraca na serwer:

```bash
rlcd -G --data-urlencode 'url=https://www.myinstants.com/en/instant/gadu-gadu-powiadomienie-84239/' "$RLCD_URL?a=sound_source" -o gadu.mp3
```
```bash
afconvert -f WAVE -d LEI16@16000 -c 1 gadu.mp3 gadu.wav
```
```bash
rlcd -H 'Content-Type: audio/wav' --data-binary @gadu.wav "$RLCD_URL?a=sound&name=Gadu-gadu"
```

Odpowiedź zawiera identyfikator, np. `"id":"s1"`. Użyj go w wiadomości jako `"sound":"s1"`.

- Nazwa dźwięku ma do 24 znaków. Spację zapisz w niej jako `%20`, np. `name=Bajo%20jajo`.
- Jeśli curl zgłosi błąd, komunikat serwera jest w pliku `gadu.mp3`; pokaże go `cat gadu.mp3`.
- Jeśli myinstants odmówi serwerowi (błąd z `HTTP 403/429`), pobierz MP3 ze strony dźwięku w przeglądarce i zacznij od `afconvert`.
- Dźwięk dłuższy niż 15 s serwer odrzuca (błąd 413), więc przytnij go wcześniej, np. w QuickTime Player.
- Biblioteka mieści 50 dźwięków.

**Stan serwera i biblioteka dźwięków:**

```bash
rlcd "$RLCD_URL?a=state" | python3 -m json.tool --no-ensure-ascii
```
```bash
rlcd -H 'Content-Type: application/json' -d '{"id":"s1"}' "$RLCD_URL?a=sound_delete"
```

## 5. Testy

Testy serwera i hooka uruchamiają `php -S` na 127.0.0.1 z tymczasową konfiguracją i nie łączą się z internetem. Część testów konwertera używa narzędzia `sips` z macOS; bez niego zostaje pominięta.

```bash
php server/tests/api_test.php
```
```bash
php server/tests/lock_test.php
```
```bash
php hook/tests/hook_test.php
```
```bash
node server/tests/app_test.mjs
```
```bash
python3 -I tools/tests/img2rlcd_test.py
```

## Ustawienia płytki

W aplikacji, na karcie **Płytka**, ustawiasz:

- **Język płytki**, w panelu „Ustawienia płytki”: English albo Polski. Dotyczy napisów na ekranie płytki, komunikatów i błędów. Aplikacja zostaje po polsku. Nowa płytka mówi po angielsku do pierwszej synchronizacji.
- **Sprawdzanie serwera w trybie Nasłuch**, w panelu „Stan urządzenia”: co ile płytka w trybie NASŁUCH pyta serwer o zmiany. „Domyślne (10 s)” zapisuje się od razu. „Własne” odsłania pole na pełne sekundy od 5 do 3600 (1 h), a wartość zapisujesz przyciskiem „Zapisz” albo klawiszem Enter. Im dłuższy odstęp, tym później płytka pokaże wiadomość, tapetę i powiadomienie o sesji Claude, ale rzadziej łączy się z serwerem. Krótki BOOT nadal synchronizuje od razu.

Płytka przyjmuje ustawienia przy najbliższym kontakcie z serwerem i zapisuje je w pamięci, więc zostają po restarcie i w trybie OFFLINE. Wartości spoza zakresu serwer odrzuca, a płytka ignoruje i zostaje przy poprzednich. Ekran stanu pokazuje bieżący odstęp, np. „Mode: LISTEN, every 45 s”, a pełne minuty jako „every 5 min”.

Starszy program płytki, sprzed własnego odstępu, zna tylko 10 s, 30 s, 1 min, 5 min i 15 min. Inną wartość zignoruje i zostanie przy poprzednim odstępie, dopóki nie wgrasz nowego programu.

## Zachowanie w praktyce

- W trybie NA ŻĄDANIE wiadomość dotrze dopiero po naciśnięciu BOOT. Jeśli czekasz na wiadomości od domowników, używaj trybu NASŁUCH.
- Dźwięk gra tylko przy wiadomości młodszej niż 30 min. Po długiej przerwie w sieci stara wiadomość pojawi się po cichu.
- Dźwięki z biblioteki są tylko na serwerze i płytka pobiera plik przy każdej wiadomości. Jeśli pobranie się nie uda, zagra `chime` i pokaże komunikat.
- Po przerwaniu odpowiedzi klawiszem Esc wiersz zostaje na „pracuje”, aż Claude skończy następną odpowiedź albo zamkniesz sesję, bo Claude Code nie zgłasza przerwania.
- Sesja bez zmian przez 12 h znika z listy, a lista ma najwyżej 8 wierszy.
- Serwer pyta Open-Meteo o pogodę najwyżej co 30 min i przekazuje płytce godzinę odczytu. Gdy Open-Meteo nie odpowiada, serwer wysyła ostatni odczyt z jego godziną. Odczyt starszy niż 3 h płytka podpisuje tą godziną, np. „Pogoda z 14:30”. Odczytu starszego niż doba nie pokazuje.

## Prywatność i bezpieczeństwo

- Płytka jest tylko klientem. Sama łączy się z hostingiem i nie nasłuchuje na żadnym porcie, więc w sieci IoT nie ma do niej wejścia. Certyfikat serwera sprawdza wbudowanym zestawem certyfikatów ESP-IDF.
- Każda rola ma własny token:
  - token płytki pozwala tylko pobierać zmiany, tapetę i dźwięki oraz zgłaszać tryb i baterię;
  - token hooka pozwala tylko zgłaszać stan sesji i limity planu;
  - wiadomości, tapetę, widok i bibliotekę dźwięków zmieniają tokeny użytkowników.
- Hook wysyła tylko alias, tytuł sesji, początek jej identyfikatora, stan oraz liczby i rodzaje (tury, subagenci, na co czeka sesja, „zrobione z wszystkich” zadań), a pasek stanu procenty limitów z czasem resetu, model i zapełnienie kontekstu. Bez ścieżek, nazw plików, treści rozmowy i nazw kroków zadań.
- `.htaccess` blokuje dostęp do `config.php`, `config.example.php`, `sounds.php`, `applock.php` i całego `data/`. Pozostałe zapytania poza `api.php` kieruje do `gate.php`, który wydaje tylko pliki aplikacji.
- Blokada urządzeń zapisuje w `data/devices.json` tylko skrót SHA-256 ciasteczka, imię osoby i datę zajęcia miejsca. Ciasteczko ma flagę `HttpOnly`, więc skrypty strony go nie odczytają.
- Hasło Wi-Fi i tokeny są tylko w plikach ignorowanych przez git: `server/config.php`, `firmware/main/secrets.h` i `~/.config/rlcd/`.
- Serwer importuje dźwięk z myinstants.com tylko na Twoje żądanie i tylko z tej domeny. Przedstawia się jawnym User-Agentem, pilnuje limitu rozmiaru i wykonuje najwyżej 3 przekierowania.
