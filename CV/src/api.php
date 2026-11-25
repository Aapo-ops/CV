<?php

require_once "tietokanta.php"; // luo PDO-yhteyden muuttujaan $conn (ERRMODE_EXCEPTION)

// ---------------------------------------------------------
// Sama istunnon asetukset kuin login.php:ssä
// ---------------------------------------------------------
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();
date_default_timezone_set('Europe/Helsinki');

header('Content-Type: application/json; charset=utf-8');

function haeKirjautunutKayttaja(PDO $conn): ?array
{
    $kayttajaId = (int)($_SESSION['kayttaja_id'] ?? 0);
    if ($kayttajaId <= 0) {
        return null;
    }

    $stmt = $conn->prepare(
        'SELECT Kayttaja_id, Nimi, Rooli FROM kayttaja WHERE Kayttaja_id = ? LIMIT 1'
    );
    $stmt->execute([$kayttajaId]);
    $kayttaja = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$kayttaja) {
        session_unset();
        session_destroy();
        return null;
    }

    $_SESSION['nimi'] = $kayttaja['Nimi'];
    $_SESSION['rooli'] = $kayttaja['Rooli'];
    return $kayttaja;
}

// Rooli, jolla saa muokata kaikkien varauksia (kayttaja-taulun Rooli-arvo)
const YLLAPITAJA_ROOLI = 'Yllapitaja';
const SALLITUT_TILAT   = ['Varattu', 'Saapunut', 'Peruttu', 'Vapaa'];

// ---------------------------------------------------------
// Apufunktiot
// ---------------------------------------------------------
function vastaa(int $koodi, array $data): void
{
    http_response_code($koodi);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Palauttaa kirjautuneen käyttäjän id:n (login.php tallentaa sen avaimella kayttaja_id)
function vaadiKirjautuminen(): int
{
    $kayttaja = haeKirjautunutKayttaja($GLOBALS['conn']);
    if ($kayttaja === null) {
        vastaa(401, ["virhe" => "Kirjaudu sisään ensin."]);
    }
    return (int)$kayttaja['Kayttaja_id'];
}

// CSRF-token tulee otsakkeessa X-CSRF-Token (sama token kuin login/logout käyttää)
function vaadiCsrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION["csrf_token"]) || !hash_equals($_SESSION["csrf_token"], $token)) {
        vastaa(403, ["virhe" => "Virheellinen pyyntö."]);
    }
}

const VARAUS_ROOLIT = ['Opettaja', 'Yllapitaja'];

function onYllapitaja(): bool
{
    return ($_SESSION["rooli"] ?? "") === YLLAPITAJA_ROOLI;
}

function vaadiVarausOikeus(): void
{
    if (!in_array($_SESSION["rooli"] ?? "", VARAUS_ROOLIT, true)) {
        vastaa(403, ["virhe" => "Opiskelijalla ei ole oikeutta varata tai muokata luokkia."]);
    }
}
function lueJson(): array
{
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        vastaa(400, ["virhe" => "Virheellinen JSON."]);
    }
    return $data;
}

// Päivämäärä muodossa VVVV-KK-PP, muuten null
function tarkistaPvm($arvo): ?string
{
    $arvo = is_string($arvo) ? $arvo : '';
    $d = DateTime::createFromFormat('!Y-m-d', $arvo);
    return ($d && $d->format('Y-m-d') === $arvo) ? $arvo : null;
}

// Kellonaika muodossa TT:MM tai TT:MM:SS (kaksinumeroiset tunnit), palauttaa TT:MM:SS tai null
function tarkistaAika($arvo): ?string
{
    $arvo = is_string($arvo) ? $arvo : '';
    foreach (['H:i:s', 'H:i'] as $muoto) {
        $d = DateTime::createFromFormat('!' . $muoto, $arvo);
        if ($d && $d->format($muoto) === $arvo) {
            return $d->format('H:i:s');
        }
    }
    return null;
}

// Tarkistaa varauksen päivän ja ajat, palauttaa [pvm, alku, loppu]
function tarkistaVarausAjat(array $data): array
{
    $pvm   = tarkistaPvm($data["PVM_Varattu"] ?? null);
    $alku  = tarkistaAika($data["Aika_Aloitus"] ?? null);
    $loppu = tarkistaAika($data["Aika_loppu"] ?? null);

    if ($pvm === null || $alku === null || $loppu === null) {
        vastaa(400, ["virhe" => "Päivämäärä (VVVV-KK-PP) tai kellonaika (TT:MM) on virheellinen."]);
    }
    if ($pvm < date("Y-m-d")) {
        vastaa(400, ["virhe" => "Varauspäivä ei saa olla menneisyydessä."]);
    }
    if ($loppu <= $alku) {
        vastaa(400, ["virhe" => "Lopetusajan pitää olla alkamisajan jälkeen."]);
    }
    return [$pvm, $alku, $loppu];
}

// Onko luokka jo varattu päällekkäiselle ajalle? (perutut eivät estä)
function onPaallekkain(PDO $conn, int $luokkaId, string $pvm, string $alku, string $loppu, int $ohitaVarausId = 0): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM Varaus
         WHERE Luokka_id = ?
           AND PVM_Varattu = ?
           AND Aika_Aloitus < ?
           AND Aika_loppu > ?
           AND Tila NOT IN ('Peruttu', 'Vapaa')
           AND Varaus_id <> ?"
    );
    $stmt->execute([$luokkaId, $pvm, $loppu, $alku, $ohitaVarausId]);
    return $stmt->fetchColumn() > 0;
}

// Poistaa varaukset, joiden päivä tai loppuaika on jo mennyt.
// Aika annetaan PHP:stä (Europe/Helsinki), koska tietokanta voi käyttää UTC-aikaa.
function poistaVanhatVaraukset(PDO $conn): void
{
    $tanaan = date("Y-m-d");
    $nyt    = date("H:i:s");

    $stmt = $conn->prepare(
        "DELETE FROM Varaus
         WHERE PVM_Varattu < ?
            OR (PVM_Varattu = ? AND Aika_loppu < ?)"
    );
    $stmt->execute([$tanaan, $tanaan, $nyt]);
}

function normalisoiTila($arvo): ?string
{
    $tila = ucfirst(strtolower(trim((string)$arvo)));
    return in_array($tila, SALLITUT_TILAT, true) ? $tila : null;
}

// ---------------------------------------------------------
// Reititys
// ---------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'];

// =========================================================
// GET
// =========================================================
if ($method === 'GET') {

    vaadiKirjautuminen();

    // Kirjautuneen käyttäjän tiedot + CSRF-token frontendille
    if (isset($_GET['Sessio']) && $_GET['Sessio'] === 'true') {
        vastaa(200, [
            "Kayttaja_id" => (int)$_SESSION["kayttaja_id"],
            "Nimi"        => $_SESSION["nimi"] ?? null,
            "Rooli"       => $_SESSION["rooli"] ?? null,
            "csrf_token"  => $_SESSION["csrf_token"] ?? null
        ]);
    }

    // Kaikki luokat ja niiden varaukset
    if (isset($_GET['Luokka']) && $_GET['Luokka'] === 'true') {

        try {
            // Siivotaan menneet varaukset pois ennen listan hakua
            poistaVanhatVaraukset($conn);

            $sql = "SELECT
                        Luokka.Luokka_id,
                        Luokka.Nimi AS LuokanNimi,
                        Luokka.Kurssinimi,
                        Luokka.Oppilaitos,
                        Luokka.sijainti,

                        Varaus.Varaus_id,
                        Varaus.PVM_Varattu,
                        Varaus.Aika_Aloitus,
                        Varaus.Aika_loppu,
                        Varaus.Tila,
                        Varaus.Jasenet,

                        kayttaja.Kayttaja_id,
                        kayttaja.Nimi AS VaraajanNimi

                    FROM Luokka

                    LEFT JOIN Varaus
                        ON Luokka.Luokka_id = Varaus.Luokka_id

                    LEFT JOIN kayttaja
                        ON Varaus.Kayttaja_id = kayttaja.Kayttaja_id

                    ORDER BY Luokka.Luokka_id, Varaus.PVM_Varattu, Varaus.Aika_Aloitus";

            $stmt = $conn->query($sql);
            vastaa(200, $stmt->fetchAll(PDO::FETCH_ASSOC));

        } catch (PDOException $e) {
            error_log($e->getMessage());
            vastaa(500, ["virhe" => "Tietokantavirhe"]);
        }
    }

    vastaa(400, ["virhe" => "Virheellinen GET-pyyntö"]);
}

// =========================================================
// POST: uusi varaus (käyttäjä otetaan sessiosta)
// =========================================================
elseif ($method === 'POST') {

    $kayttajaId = vaadiKirjautuminen();
    vaadiCsrf();
    vaadiVarausOikeus();

    $data = lueJson();

    $luokkaId = (int)($data["Luokka_id"] ?? 0);
    $jasenet  = trim((string)($data["Jasenet"] ?? ""));

    if ($luokkaId <= 0 || $jasenet === "") {
        vastaa(400, ["virhe" => "Kaikki kentät ovat pakollisia."]);
    }

    [$pvm, $alku, $loppu] = tarkistaVarausAjat($data);

    try {
        // Luokan pitää olla olemassa
        $luokka = $conn->prepare("SELECT Luokka_id FROM Luokka WHERE Luokka_id = ?");
        $luokka->execute([$luokkaId]);
        if (!$luokka->fetch()) {
            vastaa(404, ["virhe" => "Luokkaa ei löytynyt."]);
        }

        // Luokan pitää olla vapaana
        if (onPaallekkain($conn, $luokkaId, $pvm, $alku, $loppu)) {
            vastaa(409, ["virhe" => "Luokka on jo varattu tälle ajalle."]);
        }

        $stmt = $conn->prepare(
            "INSERT INTO Varaus
                (Luokka_id, Kayttaja_id, PVM_Varattu, Aika_Aloitus, Aika_loppu, Tila, Jasenet)
             VALUES (?, ?, ?, ?, ?, 'Varattu', ?)"
        );
        $stmt->execute([$luokkaId, $kayttajaId, $pvm, $alku, $loppu, $jasenet]);

        vastaa(201, [
            "viesti"    => "Varaus lisätty onnistuneesti",
            "Varaus_id" => (int)$conn->lastInsertId()
        ]);

    } catch (PDOException $e) {
        error_log($e->getMessage());
        vastaa(500, ["virhe" => "Tietokantavirhe"]);
    }
}

// =========================================================
// PUT: varauksen muokkaus / peruminen (vain oma varaus tai ylläpitäjä)
// =========================================================
elseif ($method === 'PUT') {

    $kayttajaId = vaadiKirjautuminen();
    vaadiCsrf();
    vaadiVarausOikeus();

    $data = lueJson();

    $varausId = (int)($data["Varaus_id"] ?? 0);
    $jasenet  = trim((string)($data["Jasenet"] ?? ""));

    if ($varausId <= 0 || $jasenet === "") {
        vastaa(400, ["virhe" => "Varaus_id ja Jasenet ovat pakollisia."]);
    }

    [$pvm, $alku, $loppu] = tarkistaVarausAjat($data);

    try {
        // Löytyykö varaus?
        $haku = $conn->prepare("SELECT Luokka_id, Kayttaja_id, Tila FROM Varaus WHERE Varaus_id = ?");
        $haku->execute([$varausId]);
        $varaus = $haku->fetch(PDO::FETCH_ASSOC);

        if (!$varaus) {
            vastaa(404, ["virhe" => "Varausta ei löytynyt."]);
        }

        // Oma varaus vai ylläpitäjä?
        if ((int)$varaus["Kayttaja_id"] !== $kayttajaId && !onYllapitaja()) {
            vastaa(403, ["virhe" => "Voit muokata vain omia varauksiasi."]);
        }

        // Tila: jos annettu, pitää olla sallittu; muuten säilyy ennallaan
        $tila = $varaus["Tila"];
        if (isset($data["Tila"])) {
            $tila = normalisoiTila($data["Tila"]);
            if ($tila === null) {
                vastaa(400, ["virhe" => "Tila voi olla Varattu, Saapunut tai Peruttu."]);
            }
        }

        // Päällekkäisyys tarkistetaan vain, jos varaus jää voimaan (ei ohiteta itseään)
        if (!in_array($tila, ['Peruttu', 'Vapaa'], true) && onPaallekkain($conn, (int)$varaus["Luokka_id"], $pvm, $alku, $loppu, $varausId)) {
            vastaa(409, ["virhe" => "Luokka on jo varattu tälle ajalle."]);
        }

        $stmt = $conn->prepare(
            "UPDATE Varaus
             SET PVM_Varattu = ?, Aika_Aloitus = ?, Aika_loppu = ?, Tila = ?, Jasenet = ?
             WHERE Varaus_id = ?"
        );
        $stmt->execute([$pvm, $alku, $loppu, $tila, $jasenet, $varausId]);

        vastaa(200, ["viesti" => "Varaus päivitetty onnistuneesti"]);

    } catch (PDOException $e) {
        error_log($e->getMessage());
        vastaa(500, ["virhe" => "Tietokantavirhe"]);
    }
}

// =========================================================
// Muut metodit
// =========================================================
else {
    vastaa(405, ["virhe" => "Metodia ei tueta"]);
}