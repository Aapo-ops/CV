<?php
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
date_default_timezone_set('Europe/Helsinki');

if (empty($_SESSION["kayttaja_id"])) {
    header("Location: signin.php");
    exit;
}

require_once "tietokanta.php";

$kayttajaId = (int)($_SESSION["kayttaja_id"] ?? 0);
if ($kayttajaId > 0) {
    try {
        $kayttajaStmt = $conn->prepare("SELECT Kayttaja_id, Nimi, Rooli FROM kayttaja WHERE Kayttaja_id = ? LIMIT 1");
        $kayttajaStmt->execute([$kayttajaId]);
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

$rooli = $_SESSION["rooli"] ?? "";
$onYllapitaja = ($rooli === "Yllapitaja");
$voiVarata = in_array($rooli, ["Opettaja", "Yllapitaja"], true);
$varausId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $varausId = filter_input(INPUT_POST, "varaus_id", FILTER_VALIDATE_INT);
}

if (!$voiVarata) {
    http_response_code(403);
    exit("Ei oikeutta.");
}
if (!$varausId || $varausId < 1) {
    http_response_code(400);
    exit("Virheellinen varaus.");
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$virhe = "";
$luokkaId = 0;
$pvm = "";
$alku = "";
$loppu = "";
$tila = "";
$jasenet = "";

try {
    $haku = $conn->prepare(
        "SELECT Varaus_id, Luokka_id, Kayttaja_id, PVM_Varattu, Aika_Aloitus,
                Aika_loppu, Tila, Jasenet
         FROM Varaus
         WHERE Varaus_id = ?"
    );
    $haku->execute([$varausId]);
    $varaus = $haku->fetch();
} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit("Varauksen tietojen hakeminen epäonnistui.");
}

if (!$varaus) {
    http_response_code(404);
    exit("Varausta ei löytynyt.");
}
if (!$onYllapitaja && (int)$varaus["Kayttaja_id"] !== $kayttajaId) {
    http_response_code(403);
    exit("Voit muokata vain omia varauksiasi.");
}

$luokkaId = (int)$varaus["Luokka_id"];
$pvm = $varaus["PVM_Varattu"];
$alku = substr($varaus["Aika_Aloitus"], 0, 5);
$loppu = substr($varaus["Aika_loppu"], 0, 5);
$tila = $varaus["Tila"];
$jasenet = $varaus["Jasenet"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrf = is_string($_POST["csrf_token"] ?? null) ? $_POST["csrf_token"] : "";
    $luokkaId = filter_var($_POST["luokka_id"] ?? null, FILTER_VALIDATE_INT) ?: 0;
    $pvm = is_string($_POST["pvm"] ?? null) ? trim($_POST["pvm"]) : "";
    $alku = is_string($_POST["alku"] ?? null) ? trim($_POST["alku"]) : "";
    $loppu = is_string($_POST["loppu"] ?? null) ? trim($_POST["loppu"]) : "";
    $tila = is_string($_POST["tila"] ?? null) ? trim($_POST["tila"]) : "";
    $jasenet = is_string($_POST["jasenet"] ?? null) ? trim($_POST["jasenet"]) : "";

    $alkuTietokantaan = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $alku)
        ? $alku . ':00'
        : null;
    $loppuTietokantaan = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $loppu)
        ? $loppu . ':00'
        : null;
    $paivamaara = DateTime::createFromFormat('!Y-m-d', $pvm);
    $pvmOk = $paivamaara && $paivamaara->format('Y-m-d') === $pvm;

    if (empty($_SESSION["csrf_token"]) || !hash_equals($_SESSION["csrf_token"], $csrf)) {
        $virhe = "Virheellinen pyyntö.";
    } elseif ($luokkaId <= 0 || !$pvmOk || $alkuTietokantaan === null || $loppuTietokantaan === null) {
        $virhe = "Päivämäärä, luokka tai kellonaika on virheellinen.";
    } elseif ($pvm < date("Y-m-d")) {
        $virhe = "Varauspäivä ei saa olla menneisyydessä.";
    } elseif ($loppuTietokantaan <= $alkuTietokantaan) {
        $virhe = "Lopetusajan pitää olla alkamisajan jälkeen.";
    } elseif (!in_array($tila, ["Varattu", "Saapunut", "Peruttu", "Vapaa"], true)) {
        $virhe = "Valittu varaustila ei kelpaa.";
    } elseif ($jasenet === "" || mb_strlen($jasenet) > 5000) {
        $virhe = "Jäsenet-kenttä on pakollinen ja saa olla enintään 5000 merkkiä pitkä.";
    } else {
        try {
            $conn->beginTransaction();
            $luokkaHaku = $conn->prepare("SELECT Luokka_id FROM Luokka WHERE Luokka_id = ? FOR UPDATE");
            $luokkaHaku->execute([$luokkaId]);
            if (!$luokkaHaku->fetch()) {
                $conn->rollBack();
                $virhe = "Valittua luokkaa ei löytynyt.";
            } elseif (!in_array($tila, ["Peruttu", "Vapaa"], true)) {
                $paallek = $conn->prepare(
                    "SELECT COUNT(*) FROM Varaus
                     WHERE Luokka_id = ? AND PVM_Varattu = ?
                       AND Tila NOT IN ('Peruttu', 'Vapaa')
                       AND Aika_Aloitus < ? AND Aika_loppu > ?
                       AND Varaus_id <> ?"
                );
                $paallek->execute([
                    $luokkaId,
                    $pvm,
                    $loppuTietokantaan,
                    $alkuTietokantaan,
                    $varausId
                ]);
                if ((int)$paallek->fetchColumn() > 0) {
                    $conn->rollBack();
                    $virhe = "Luokka on jo varattu valittuna aikana.";
                }
            }

            if ($virhe === "") {
                $paivitys = $conn->prepare(
                    "UPDATE Varaus
                     SET Luokka_id = ?, PVM_Varattu = ?, Aika_Aloitus = ?,
                         Aika_loppu = ?, Tila = ?, Jasenet = ?
                     WHERE Varaus_id = ?"
                );
                $paivitys->execute([
                    $luokkaId,
                    $pvm,
                    $alkuTietokantaan,
                    $loppuTietokantaan,
                    $tila,
                    $jasenet,
                    $varausId
                ]);
                $conn->commit();
                header("Location: index.php");
                exit;
            }
        } catch (PDOException $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log($e->getMessage());
            $virhe = "Varauksen tallentaminen epäonnistui. Yritä myöhemmin uudelleen.";
        }
    }
}

try {
    $luokat = $conn->query("SELECT Luokka_id, Nimi, Kurssinimi, Oppilaitos FROM Luokka ORDER BY Luokka_id")->fetchAll();
} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit("Luokkien hakeminen epäonnistui.");
}

$csrf = htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="fi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Muokkaa varausta</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-light">
<main class="container py-5" style="max-width: 760px;">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h1 class="h3 mb-4">Muokkaa varausta</h1>
            <?php if ($virhe !== ""): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($virhe, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <form method="POST" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="varaus_id" value="<?= (int)$varausId ?>">
                <div class="col-12">
                    <label class="form-label" for="luokka_id">Luokka</label>
                    <select class="form-select" id="luokka_id" name="luokka_id" required>
                        <?php foreach ($luokat as $luokka): ?>
                            <option value="<?= (int)$luokka["Luokka_id"] ?>"
                                <?= $luokkaId === (int)$luokka["Luokka_id"] ? "selected" : "" ?>>
                                <?= htmlspecialchars($luokka["Nimi"], ENT_QUOTES, 'UTF-8') ?> –
                                <?= htmlspecialchars($luokka["Kurssinimi"], ENT_QUOTES, 'UTF-8') ?>
                                (<?= htmlspecialchars($luokka["Oppilaitos"], ENT_QUOTES, 'UTF-8') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="pvm">Päivämäärä</label>
                    <input class="form-control" type="date" id="pvm" name="pvm"
                           min="<?= date("Y-m-d") ?>" value="<?= htmlspecialchars($pvm, ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="alku">Alkaa</label>
                    <input class="form-control" type="time" id="alku" name="alku"
                           value="<?= htmlspecialchars($alku, ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="loppu">Loppuu</label>
                    <input class="form-control" type="time" id="loppu" name="loppu"
                           value="<?= htmlspecialchars($loppu, ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="tila">Tila</label>
                    <select class="form-select" id="tila" name="tila" required>
                        <?php foreach (["Varattu", "Saapunut", "Peruttu", "Vapaa"] as $vaihtoehto): ?>
                            <option value="<?= $vaihtoehto ?>" <?= $tila === $vaihtoehto ? "selected" : "" ?>>
                                <?= $vaihtoehto ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label" for="jasenet">Jäsenet</label>
                    <textarea class="form-control" id="jasenet" name="jasenet" maxlength="5000"
                              rows="3" required><?= htmlspecialchars($jasenet, ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary" type="submit">Tallenna muutokset</button>
                    <a class="btn btn-outline-secondary" href="index.php">Peruuta</a>
                </div>
            </form>
        </div>
    </div>
</main>
</body>
</html>
