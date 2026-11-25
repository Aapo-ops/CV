<?php
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

// Jos ei kirjautunut, ohjataan rekisteröitymissivulle
if (empty($_SESSION["kayttaja_id"])) {
    header("Location: signin.php");
    exit;
}

require_once "tietokanta.php";
$pdo = $conn;

$omaId = (int)($_SESSION["kayttaja_id"] ?? 0);
if ($omaId > 0) {
    try {
        $kayttajaStmt = $pdo->prepare("SELECT Kayttaja_id, Nimi, Rooli FROM kayttaja WHERE Kayttaja_id = ? LIMIT 1");
        $kayttajaStmt->execute([$omaId]);
        $kayttaja = $kayttajaStmt->fetch(PDO::FETCH_ASSOC);

        if (!$kayttaja) {
            session_unset();
            session_destroy();
            header("Location: signin.php");
            exit;
        }

        $_SESSION["nimi"] = $kayttaja["Nimi"];
        $_SESSION["rooli"] = $kayttaja["Rooli"];
    } catch (PDOException $e) {
        error_log($e->getMessage());
        session_unset();
        session_destroy();
        header("Location: signin.php");
        exit;
    }
}

$rooli        = $_SESSION["rooli"] ?? "";
$voiVarata    = in_array($rooli, ['Opettaja', 'Yllapitaja'], true);
$onYllapitaja = ($rooli === 'Yllapitaja');

$virhe = "";

// Lomakkeen arvot (säilytetään virhetilanteessa)
$lPvm     = date('Y-m-d');
$lAlku    = '08:00';
$lLoppu   = '10:00';
$lJasenet = '';
$lLuokka  = 0;
$lVaraajaId = $omaId;
$uusiLuokkaNimi = '';
$uusiKurssinimi = '';
$uusiOppilaitos = '';
$uusiSijainti = '';

// ---------- APUFUNKTIOT ----------
function tarkistaCsrf(): bool
{
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    return !empty($_SESSION["csrf_token"]) && hash_equals($_SESSION["csrf_token"], $token);
}

function pvmOk(string $s): bool
{
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s;
}

// Hyväksyy TT:MM tai TT:MM:SS, palauttaa TT:MM:SS tai null
function aikaOk(string $s): ?string
{
    if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $s)) {
        return substr($s, 0, 5) . ':00';
    }
    return null;
}

// ---------- LUOKAN LISÄYS ----------
if (isset($_POST['lisaa_luokka'])) {
    if (!$onYllapitaja) {
        http_response_code(403);
        exit("Ei oikeutta.");
    }
    if (!tarkistaCsrf()) {
        http_response_code(403);
        exit("Virheellinen pyyntö.");
    }

    $uusiLuokkaNimi = trim(is_string($_POST['luokan_nimi'] ?? null) ? $_POST['luokan_nimi'] : '');
    $uusiKurssinimi = trim(is_string($_POST['kurssinimi'] ?? null) ? $_POST['kurssinimi'] : '');
    $uusiOppilaitos = trim(is_string($_POST['oppilaitos'] ?? null) ? $_POST['oppilaitos'] : '');
    $uusiSijainti = trim(is_string($_POST['sijainti'] ?? null) ? $_POST['sijainti'] : '');

    if (
        $uusiLuokkaNimi === '' || mb_strlen($uusiLuokkaNimi) > 50 ||
        $uusiKurssinimi === '' || mb_strlen($uusiKurssinimi) > 50 ||
        $uusiOppilaitos === '' || mb_strlen($uusiOppilaitos) > 50 ||
        $uusiSijainti === '' || mb_strlen($uusiSijainti) > 100
    ) {
        $virhe = "Täytä kaikki luokan tiedot. Luokan, kurssin ja oppilaitoksen nimet saavat olla enintään 50 merkkiä ja sijainti 100 merkkiä.";
    } else {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO Luokka (Nimi, Kurssinimi, Oppilaitos, sijainti)
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$uusiLuokkaNimi, $uusiKurssinimi, $uusiOppilaitos, $uusiSijainti]);
            header("Location: index.php");
            exit;
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $virhe = "Luokan lisääminen epäonnistui. Yritä myöhemmin uudelleen.";
        }
    }
}

// ---------- LUOKAN POISTO ----------
if (isset($_POST['poista_luokka'])) {
    if (!$onYllapitaja) {
        http_response_code(403);
        exit("Ei oikeutta.");
    }
    if (!tarkistaCsrf()) {
        http_response_code(403);
        exit("Virheellinen pyyntö.");
    }

    $poistettavaLuokkaId = filter_var($_POST['luokka_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$poistettavaLuokkaId || $poistettavaLuokkaId < 1) {
        $virhe = "Valittu luokka ei kelpaa.";
    } else {
        try {
            $pdo->beginTransaction();
            $luokka = $pdo->prepare("SELECT Luokka_id FROM Luokka WHERE Luokka_id = ? FOR UPDATE");
            $luokka->execute([$poistettavaLuokkaId]);

            if (!$luokka->fetch()) {
                $pdo->rollBack();
                $virhe = "Luokkaa ei löytynyt.";
            } else {
                $varauksia = $pdo->prepare("SELECT COUNT(*) FROM Varaus WHERE Luokka_id = ?");
                $varauksia->execute([$poistettavaLuokkaId]);

                if ((int)$varauksia->fetchColumn() > 0) {
                    $pdo->rollBack();
                    $virhe = "Luokkaa ei voi poistaa, koska siihen liittyy varauksia.";
                } else {
                    $poisto = $pdo->prepare("DELETE FROM Luokka WHERE Luokka_id = ?");
                    $poisto->execute([$poistettavaLuokkaId]);
                    $pdo->commit();
                    header("Location: index.php");
                    exit;
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($e->getMessage());
            $virhe = "Luokan poistaminen epäonnistui. Yritä myöhemmin uudelleen.";
        }
    }
}

// ---------- VARAUKSEN LISÄYS ----------
if (isset($_POST['lisaa'])) {

    if (!$voiVarata) {
        http_response_code(403);
        exit("Ei oikeutta.");
    }
    if (!tarkistaCsrf()) {
        http_response_code(403);
        exit("Virheellinen pyyntö.");
    }

    $lLuokka  = (int)($_POST['luokka_id'] ?? 0);
    $lPvm     = is_string($_POST['pvm'] ?? null) ? trim($_POST['pvm']) : '';
    $lAlku    = is_string($_POST['alku'] ?? null) ? trim($_POST['alku']) : '';
    $lLoppu   = is_string($_POST['loppu'] ?? null) ? trim($_POST['loppu']) : '';
    $lJasenet = is_string($_POST['jasenet'] ?? null) ? trim($_POST['jasenet']) : '';
    $lVaraajaId = $onYllapitaja
        ? (int)($_POST['varaaja_id'] ?? 0)
        : $omaId;

    $alku  = aikaOk($lAlku);
    $loppu = aikaOk($lLoppu);

    if ($lLuokka <= 0 || $lVaraajaId <= 0 || $lJasenet === '') {
        $virhe = "Kaikki kentät ovat pakollisia.";
    } elseif (mb_strlen($lJasenet) > 5000) {
        $virhe = "Jäsenet-kenttä on liian pitkä.";
    } elseif (!pvmOk($lPvm) || $alku === null || $loppu === null) {
        $virhe = "Päivämäärä tai kellonaika on virheellinen.";
    } elseif ($loppu <= $alku) {
        $virhe = "Loppumisajan pitää olla alkamisajan jälkeen.";
    } elseif ($lPvm < date('Y-m-d')) {
        $virhe = "Et voi varata menneelle päivälle.";
    } else {
        try {
            $pdo->beginTransaction();

            // Lukitaan luokan rivi, jotta kaksi samanaikaista varausta ei mene päällekkäin
            $luokka = $pdo->prepare("SELECT Luokka_id FROM Luokka WHERE Luokka_id = ? FOR UPDATE");
            $luokka->execute([$lLuokka]);

            if (!$luokka->fetch()) {
                $pdo->rollBack();
                $virhe = "Luokkaa ei löytynyt.";
            } else {
                $varaaja = $pdo->prepare("SELECT Kayttaja_id FROM kayttaja WHERE Kayttaja_id = ?");
                $varaaja->execute([$lVaraajaId]);

                if (!$varaaja->fetch()) {
                    $pdo->rollBack();
                    $virhe = "Valittua varaajaa ei löytynyt.";
                } else {
                    $tarkista = $pdo->prepare(
                        "SELECT COUNT(*) FROM Varaus
                         WHERE Luokka_id = ? AND PVM_Varattu = ?
                           AND Tila NOT IN ('Peruttu', 'Vapaa')
                           AND Aika_Aloitus < ? AND Aika_loppu > ?"
                    );
                    $tarkista->execute([$lLuokka, $lPvm, $loppu, $alku]);

                    if ($tarkista->fetchColumn() > 0) {
                        $pdo->rollBack();
                        $virhe = "Luokka on jo varattu valittuna aikana.";
                    } else {
                        $stmt = $pdo->prepare(
                            "INSERT INTO Varaus (Luokka_id, Kayttaja_id, PVM_Varattu, Aika_Aloitus, Aika_loppu, Tila, Jasenet)
                             VALUES (?, ?, ?, ?, ?, 'Varattu', ?)"
                        );
                        // Opettajan varaaja tulee istunnosta; ylläpitäjä voi valita varaajan.
                        $stmt->execute([$lLuokka, $lVaraajaId, $lPvm, $alku, $loppu, $lJasenet]);
                        $pdo->commit();

                        header("Location: index.php");
                        exit;
                    }
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($e->getMessage());
            $virhe = "Tietokantavirhe. Yritä myöhemmin uudelleen.";
        }
    }
}

// ---------- VARAUKSEN POISTO ----------
if (isset($_POST['poista'])) {

    if (!$voiVarata) {
        http_response_code(403);
        exit("Ei oikeutta.");
    }
    if (!tarkistaCsrf()) {
        http_response_code(403);
        exit("Virheellinen pyyntö.");
    }

    try {
        // Opettaja saa poistaa vain omansa, ylläpitäjä kaikki
        $stmt = $pdo->prepare(
            "DELETE FROM Varaus WHERE Varaus_id = ? AND (Kayttaja_id = ? OR ? = 1)"
        );
        $stmt->execute([(int)($_POST['varaus_id'] ?? 0), $omaId, $onYllapitaja ? 1 : 0]);
        header("Location: index.php");
        exit;
    } catch (PDOException $e) {
        error_log($e->getMessage());
        $virhe = "Varauksen poistaminen epäonnistui. Yritä myöhemmin uudelleen.";
    }
}

// ---------- TIETOJEN HAKU ----------
$luokat = $pdo->query(
    "SELECT l.Luokka_id, l.Nimi, l.Kurssinimi, l.Oppilaitos, l.sijainti,
            (SELECT COUNT(*) FROM Varaus v WHERE v.Luokka_id = l.Luokka_id) AS Varauksia
     FROM Luokka l
     ORDER BY l.Luokka_id"
)->fetchAll();
$kayttajat = [];
if ($onYllapitaja) {
    $kayttajat = $pdo->query(
        "SELECT Kayttaja_id, Nimi, Sahkoposti FROM kayttaja ORDER BY Nimi, Sahkoposti"
    )->fetchAll();
}

// Tulevat varaukset: ei menneitä, ei peruttuja eikä "vapaita"
$hakuVaraukset = $pdo->prepare(
    "SELECT v.Varaus_id, v.Kayttaja_id, v.PVM_Varattu, v.Aika_Aloitus, v.Aika_loppu, v.Tila, v.Jasenet,
            l.Nimi AS LuokkaNimi, k.Nimi AS KayttajaNimi
     FROM Varaus v
     JOIN Luokka l ON v.Luokka_id = l.Luokka_id
     JOIN kayttaja k ON v.Kayttaja_id = k.Kayttaja_id
     " . ($onYllapitaja
        ? ""
        : "WHERE v.Tila NOT IN ('Peruttu', 'Vapaa')
             AND (v.PVM_Varattu > ? OR (v.PVM_Varattu = ? AND v.Aika_loppu >= ?))") . "
     ORDER BY v.PVM_Varattu, v.Aika_Aloitus"
);
if ($onYllapitaja) {
    $hakuVaraukset->execute();
} else {
    $hakuVaraukset->execute([date('Y-m-d'), date('Y-m-d'), date('H:i:s')]);
}
$varaukset = $hakuVaraukset->fetchAll();

$csrf = htmlspecialchars($_SESSION["csrf_token"] ?? "", ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="fi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Luokan varaus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-light">

<!-- Yläpalkki -->
<nav class="navbar navbar-dark bg-primary mb-4">
    <div class="container">
        <span class="navbar-brand mb-0 h1">Luokan varaus</span>

        <div class="d-flex align-items-center gap-3">
            <span class="text-white">
                Kirjautunut: <?= htmlspecialchars($_SESSION["nimi"] ?? "", ENT_QUOTES, 'UTF-8') ?>
                (<?= htmlspecialchars($rooli, ENT_QUOTES, 'UTF-8') ?>)
            </span>

            <?php if ($onYllapitaja): ?>
                <a class="btn btn-outline-light btn-sm" href="create_admin.php">Luo ylläpitäjä</a>
            <?php endif; ?>

            <form method="POST" action="Logout.php" class="m-0">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <button type="submit" class="btn btn-outline-light btn-sm">Kirjaudu ulos</button>
            </form>
        </div>
    </div>
</nav>

<div class="container pb-5">

    <?php if ($virhe !== ""): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($virhe, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($onYllapitaja): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h4 class="card-title mb-3">Luokkien hallinta</h4>
                <form method="POST" class="row g-3 mb-4">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <div class="col-md-6">
                        <label class="form-label" for="luokan_nimi">Luokan nimi</label>
                        <input class="form-control" id="luokan_nimi" name="luokan_nimi"
                               maxlength="50" required
                               value="<?= htmlspecialchars($uusiLuokkaNimi, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="kurssinimi">Kurssin nimi</label>
                        <input class="form-control" id="kurssinimi" name="kurssinimi"
                               maxlength="50" required
                               value="<?= htmlspecialchars($uusiKurssinimi, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="oppilaitos">Oppilaitos</label>
                        <input class="form-control" id="oppilaitos" name="oppilaitos"
                               maxlength="50" required
                               value="<?= htmlspecialchars($uusiOppilaitos, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="sijainti">Sijainti</label>
                        <input class="form-control" id="sijainti" name="sijainti"
                               maxlength="100" required
                               value="<?= htmlspecialchars($uusiSijainti, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="col-12">
                        <button type="submit" name="lisaa_luokka" class="btn btn-primary">Lisää luokka</button>
                    </div>
                </form>

                <h5 class="h6">Nykyiset luokat</h5>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Luokka</th>
                                <th>Kurssi</th>
                                <th>Oppilaitos</th>
                                <th>Sijainti</th>
                                <th>Varauksia</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($luokat)): ?>
                                <tr><td colspan="6" class="text-center text-muted">Ei luokkia.</td></tr>
                            <?php else: ?>
                                <?php foreach ($luokat as $luokka): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($luokka['Nimi'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($luokka['Kurssinimi'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($luokka['Oppilaitos'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($luokka['sijainti'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= (int)$luokka['Varauksia'] ?></td>
                                        <td class="text-end">
                                            <?php if ((int)$luokka['Varauksia'] === 0): ?>
                                                <form method="POST" class="m-0"
                                                      onsubmit="return confirm('Poistetaanko luokka?');">
                                                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                                    <input type="hidden" name="luokka_id"
                                                           value="<?= (int)$luokka['Luokka_id'] ?>">
                                                    <button type="submit" name="poista_luokka"
                                                            class="btn btn-outline-danger btn-sm">Poista</button>
                                                </form>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-outline-secondary btn-sm"
                                                        disabled title="Poista luokan varaukset ensin">Poista</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <small class="text-muted">Luokan voi poistaa vasta, kun siihen liittyvät varaukset on poistettu.</small>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($voiVarata): ?>
        <!-- Uuden varauksen lomake (vain opettajat ja ylläpitäjät) -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h4 class="card-title mb-3">Tee uusi varaus</h4>
                <form method="POST" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

                    <div class="col-12">
                        <label class="form-label">Luokka</label>
                        <select name="luokka_id" class="form-select" required>
                            <?php foreach ($luokat as $l): ?>
                                <option value="<?= (int)$l['Luokka_id'] ?>" <?= $lLuokka === (int)$l['Luokka_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($l['Nimi']) ?> – <?= htmlspecialchars($l['Kurssinimi']) ?> (<?= htmlspecialchars($l['Oppilaitos']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($onYllapitaja): ?>
                        <div class="col-12">
                            <label class="form-label" for="varaaja_id">Varaaja</label>
                            <select id="varaaja_id" name="varaaja_id" class="form-select" required>
                                <?php foreach ($kayttajat as $kayttaja): ?>
                                    <option value="<?= (int)$kayttaja['Kayttaja_id'] ?>"
                                        <?= $lVaraajaId === (int)$kayttaja['Kayttaja_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($kayttaja['Nimi'], ENT_QUOTES, 'UTF-8') ?>
                                        (<?= htmlspecialchars($kayttaja['Sahkoposti'], ENT_QUOTES, 'UTF-8') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="col-md-4">
                        <label class="form-label">Päivämäärä</label>
                        <input type="date" name="pvm" class="form-control"
                               value="<?= htmlspecialchars($lPvm, ENT_QUOTES, 'UTF-8') ?>"
                               min="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Alkaa</label>
                        <input type="time" name="alku" class="form-control"
                               value="<?= htmlspecialchars($lAlku, ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Loppuu</label>
                        <input type="time" name="loppu" class="form-control"
                               value="<?= htmlspecialchars($lLoppu, ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Jäsenet</label>
                        <input type="text" name="jasenet" class="form-control" maxlength="5000" required
                               value="<?= htmlspecialchars($lJasenet, ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="Esim. Matti, Liisa">
                    </div>

                    <div class="col-12">
                        <button type="submit" name="lisaa" class="btn btn-primary">Tallenna varaus</button>
                    </div>
                </form>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-info">Opiskelijana voit vain katsella varauksia.</div>
    <?php endif; ?>

    <?php
$tapahtumat = array_map(fn($v) => [
    'id'    => (int)$v['Varaus_id'],
    'title' => $v['LuokkaNimi'] . ' – ' . $v['KayttajaNimi'],
    'start' => $v['PVM_Varattu'] . 'T' . $v['Aika_Aloitus'],
    'end'   => $v['PVM_Varattu'] . 'T' . $v['Aika_loppu'],
], $varaukset);
?>
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div id="kalenteri"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/locales-all.global.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const kalenteri = new FullCalendar.Calendar(document.getElementById('kalenteri'), {
        initialView: 'timeGridWeek',
        locale: 'fi',
        firstDay: 1,
        events: <?= json_encode($tapahtumat, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>
    });
    kalenteri.render();
});
</script>

    <!-- Tulevat varaukset -->
    <div class="card shadow-sm">
        <div class="card-body">
            <h4 class="card-title mb-3"><?= $onYllapitaja ? "Kaikki varaukset" : "Tulevat varaukset" ?></h4>
            <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Luokka</th>
                            <th>Päivä</th>
                            <th>Aika</th>
                            <th>Varaaja</th>
                            <th>Jäsenet</th>
                            <?php if ($onYllapitaja): ?><th>Tila</th><?php endif; ?>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($varaukset)): ?>
                            <tr><td colspan="<?= $onYllapitaja ? 7 : 6 ?>" class="text-center text-muted">Ei varauksia.</td></tr>
                        <?php else: ?>
                            <?php foreach ($varaukset as $v): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($v['LuokkaNimi']) ?></strong></td>
                                <td><?= date('d.m.Y', strtotime($v['PVM_Varattu'])) ?></td>
                                <td><?= substr($v['Aika_Aloitus'], 0, 5) ?>–<?= substr($v['Aika_loppu'], 0, 5) ?></td>
                                <td><?= htmlspecialchars($v['KayttajaNimi']) ?></td>
                                <td><?= htmlspecialchars($v['Jasenet']) ?></td>
                                <?php if ($onYllapitaja): ?>
                                    <td><?= htmlspecialchars($v['Tila'], ENT_QUOTES, 'UTF-8') ?></td>
                                <?php endif; ?>
                                <td class="text-end">
                                    <?php if ($voiVarata && ($onYllapitaja || (int)$v['Kayttaja_id'] === $omaId)): ?>
                                        <div class="d-flex justify-content-end gap-2">
                                            <a class="btn btn-outline-primary btn-sm"
                                               href="muokkaa_varaus.php?id=<?= (int)$v['Varaus_id'] ?>">Muokkaa</a>
                                            <form method="POST" class="m-0" onsubmit="return confirm('Poistetaanko varaus?');">
                                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                                <input type="hidden" name="varaus_id" value="<?= (int)$v['Varaus_id'] ?>">
                                                <button type="submit" name="poista" class="btn btn-outline-danger btn-sm">Poista</button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
</body>
</html>