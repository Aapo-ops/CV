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

require_once "tietokanta.php";

$kayttajaId = (int)($_SESSION["kayttaja_id"] ?? 0);
if ($kayttajaId > 0) {
    try {
        $kayttajaStmt = $conn->prepare("SELECT Kayttaja_id, Nimi, Rooli FROM kayttaja WHERE Kayttaja_id = ? LIMIT 1");
        $kayttajaStmt->execute([$kayttajaId]);
        $kirjautunut = $kayttajaStmt->fetch(PDO::FETCH_ASSOC);

        if (!$kirjautunut) {
            session_unset();
            session_destroy();
            header("Location: signin.php");
            exit;
        }

        $_SESSION["nimi"] = $kirjautunut["Nimi"];
        $_SESSION["rooli"] = $kirjautunut["Rooli"];
    } catch (PDOException $e) {
        error_log($e->getMessage());
        session_unset();
        session_destroy();
        header("Location: signin.php");
        exit;
    }
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$onYllapitaja = ($_SESSION["rooli"] ?? "") === "Yllapitaja";
$kayttajia = (int)$conn->query("SELECT COUNT(*) FROM kayttaja")->fetchColumn();
$ensimmainenYllapitaja = ($kayttajia === 0);

if (!$onYllapitaja && !$ensimmainenYllapitaja) {
    http_response_code(403);
    exit("Ylläpitäjän luonti on sallittu vain ensimmäiselle käyttäjälle tai kirjautuneelle ylläpitäjälle.");
}

$virhe = "";
$nimi = "";
$sahkoposti = "";
$puhelin = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nimi = trim(is_string($_POST["nimi"] ?? null) ? $_POST["nimi"] : "");
    $sahkoposti = trim(is_string($_POST["sahkoposti"] ?? null) ? $_POST["sahkoposti"] : "");
    $puhelin = trim(is_string($_POST["puhelin"] ?? null) ? $_POST["puhelin"] : "");
    $salasana = is_string($_POST["salasana"] ?? null) ? $_POST["salasana"] : "";
    $salasanaUudelleen = is_string($_POST["salasana_uudelleen"] ?? null)
        ? $_POST["salasana_uudelleen"]
        : "";
    $csrf = is_string($_POST["csrf_token"] ?? null) ? $_POST["csrf_token"] : "";

    if (!hash_equals($_SESSION["csrf_token"], $csrf)) {
        $virhe = "Virheellinen pyyntö.";
    } elseif ($nimi === "" || strlen($nimi) > 50) {
        $virhe = "Nimen tulee olla 1–50 merkkiä pitkä.";
    } elseif (!filter_var($sahkoposti, FILTER_VALIDATE_EMAIL) || strlen($sahkoposti) > 100) {
        $virhe = "Anna kelvollinen sähköpostiosoite (enintään 100 merkkiä).";
    } elseif ($puhelin === "" || strlen($puhelin) > 50) {
        $virhe = "Puhelinnumeron tulee olla 1–50 merkkiä pitkä.";
    } elseif (strlen($salasana) < 8 || strlen($salasana) > 72) {
        $virhe = "Salasanan tulee olla 8–72 merkkiä pitkä.";
    } elseif ($salasana !== $salasanaUudelleen) {
        $virhe = "Salasanat eivät täsmää.";
    } else {
        try {
            $lukko = $conn->query("SELECT GET_LOCK('luokkavaraus_yllapitaja', 5)")->fetchColumn();
            if ((int)$lukko !== 1) {
                $virhe = "Ylläpitäjän luonti on juuri nyt varattu. Yritä uudelleen.";
            } else {
                try {
                    $conn->beginTransaction();
                    $kayttajiaNyt = (int)$conn->query("SELECT COUNT(*) FROM kayttaja")->fetchColumn();
                    $onAlustus = !$onYllapitaja && $kayttajiaNyt === 0;

                    if (!$onYllapitaja && !$onAlustus) {
                        $conn->rollBack();
                        http_response_code(403);
                        exit("Ensimmäinen käyttäjä on jo luotu. Vain ylläpitäjä voi luoda uusia ylläpitäjiä.");
                    }

                    $haku = $conn->prepare(
                        "SELECT Kayttaja_id FROM kayttaja WHERE Sahkoposti = ? LIMIT 1"
                    );
                    $haku->execute([$sahkoposti]);

                    if ($haku->fetch()) {
                        $conn->rollBack();
                        $virhe = "Sähköpostiosoite on jo käytössä.";
                    } else {
                        $stmt = $conn->prepare(
                            "INSERT INTO kayttaja (Nimi, Salasana, Sahkoposti, Rooli, PuhelinNum)
                             VALUES (?, ?, ?, 'Yllapitaja', ?)"
                        );
                        $stmt->execute([
                            $nimi,
                            password_hash($salasana, PASSWORD_DEFAULT),
                            $sahkoposti,
                            $puhelin
                        ]);
                        $kayttajaId = (int)$conn->lastInsertId();
                        $conn->commit();

                        if ($onAlustus) {
                            session_regenerate_id(true);
                            $_SESSION["kayttaja_id"] = $kayttajaId;
                            $_SESSION["nimi"] = $nimi;
                            $_SESSION["sahkoposti"] = $sahkoposti;
                            $_SESSION["rooli"] = "Yllapitaja";
                            $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
                        }

                        header("Location: index.php");
                        exit;
                    }
                } finally {
                    $conn->query("SELECT RELEASE_LOCK('luokkavaraus_yllapitaja')");
                }
            }
        } catch (PDOException $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log($e->getMessage());
            $virhe = "Ylläpitäjän luonti epäonnistui. Yritä myöhemmin uudelleen.";
        }
    }
}

$csrf = htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="fi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Luo ylläpitäjä</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-light">
<main class="container py-5" style="max-width: 640px;">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h1 class="h3 mb-3">Luo ylläpitäjä</h1>
            <p class="text-muted">
                Ylläpitäjä voi muokata ja poistaa kaikkien käyttäjien varauksia.
            </p>
            <?php if ($ensimmainenYllapitaja && !$onYllapitaja): ?>
                <div class="alert alert-info">
                    Luo ensimmäinen ylläpitäjätili ennen muiden käyttäjien rekisteröitymistä.
                </div>
            <?php endif; ?>
            <?php if ($virhe !== ""): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($virhe, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <div class="mb-3">
                    <label class="form-label" for="nimi">Nimi</label>
                    <input class="form-control" id="nimi" name="nimi" maxlength="50" required
                           autocomplete="name" value="<?= htmlspecialchars($nimi, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="sahkoposti">Sähköposti</label>
                    <input class="form-control" type="email" id="sahkoposti" name="sahkoposti"
                           maxlength="100" required autocomplete="email"
                           value="<?= htmlspecialchars($sahkoposti, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="puhelin">Puhelinnumero</label>
                    <input class="form-control" type="tel" id="puhelin" name="puhelin"
                           maxlength="50" required autocomplete="tel"
                           value="<?= htmlspecialchars($puhelin, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="salasana">Salasana (8–72 merkkiä)</label>
                    <input class="form-control" type="password" id="salasana" name="salasana"
                           minlength="8" maxlength="72" required autocomplete="new-password">
                </div>
                <div class="mb-4">
                    <label class="form-label" for="salasana_uudelleen">Salasana uudelleen</label>
                    <input class="form-control" type="password" id="salasana_uudelleen"
                           name="salasana_uudelleen" minlength="8" maxlength="72"
                           required autocomplete="new-password">
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-primary" type="submit">Luo ylläpitäjätili</button>
                    <a class="btn btn-outline-secondary" href="<?= $onYllapitaja ? 'index.php' : 'login.php' ?>">Takaisin</a>
                </div>
            </form>
        </div>
    </div>
</main>
</body>
</html>
