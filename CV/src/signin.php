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

if (isset($_SESSION["kayttaja_id"])) {
    header("Location: index.php");
    exit;
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$virhe = "";
$nimi = "";
$sahkoposti = "";
$puhelin = "";
$rooli = "Opiskelija";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nimi = trim(is_string($_POST["nimi"] ?? null) ? $_POST["nimi"] : "");
    $sahkoposti = trim(is_string($_POST["sahkoposti"] ?? null) ? $_POST["sahkoposti"] : "");
    $puhelin = trim(is_string($_POST["puhelin"] ?? null) ? $_POST["puhelin"] : "");
    $rooli = is_string($_POST["rooli"] ?? null) ? $_POST["rooli"] : "";
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
    } elseif (!in_array($rooli, ["Opettaja", "Opiskelija"], true)) {
        $virhe = "Valitse opettajan tai oppilaan rooli.";
    } elseif (strlen($salasana) < 8 || strlen($salasana) > 72) {
        $virhe = "Salasanan tulee olla 8–72 merkkiä pitkä.";
    } elseif ($salasana !== $salasanaUudelleen) {
        $virhe = "Salasanat eivät täsmää.";
    } else {
        try {
            $haku = $conn->prepare(
                "SELECT Kayttaja_id
                 FROM kayttaja
                 WHERE Sahkoposti = :sahkoposti
                 LIMIT 1"
            );
            $haku->execute([":sahkoposti" => $sahkoposti]);

            if ($haku->fetch()) {
                $virhe = "Sähköpostiosoite on jo käytössä.";
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO kayttaja (Nimi, Salasana, Sahkoposti, Rooli, PuhelinNum)
                     VALUES (:nimi, :salasana, :sahkoposti, :rooli, :puhelin)"
                );
                $stmt->execute([
                    ":nimi" => $nimi,
                    ":salasana" => password_hash($salasana, PASSWORD_DEFAULT),
                    ":sahkoposti" => $sahkoposti,
                    ":rooli" => $rooli,
                    ":puhelin" => $puhelin
                ]);

                session_regenerate_id(true);
                $_SESSION["kayttaja_id"] = $conn->lastInsertId();
                $_SESSION["nimi"] = $nimi;
                $_SESSION["sahkoposti"] = $sahkoposti;
                $_SESSION["rooli"] = $rooli;
                $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

                header("Location: index.php");
                exit;
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $virhe = "Tilin luominen epäonnistui. Yritä myöhemmin uudelleen.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Luo tili</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-light">

<main class="container py-5" style="max-width: 640px;">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h1 class="h3 mb-3">Luo tili</h1>

            <?php if ($virhe !== ""): ?>
                <div class="alert alert-danger">
                    <?= htmlspecialchars($virhe, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, 'UTF-8') ?>"
                >

                <div class="mb-3">
                    <label class="form-label" for="nimi">Nimi</label>
                    <input
                        class="form-control"
                        type="text"
                        id="nimi"
                        name="nimi"
                        value="<?= htmlspecialchars($nimi, ENT_QUOTES, 'UTF-8') ?>"
                        required
                        maxlength="50"
                        autocomplete="name"
                    >
                </div>

                <div class="mb-3">
                    <label class="form-label" for="sahkoposti">Sähköposti</label>
                    <input
                        class="form-control"
                        type="email"
                        id="sahkoposti"
                        name="sahkoposti"
                        value="<?= htmlspecialchars($sahkoposti, ENT_QUOTES, 'UTF-8') ?>"
                        required
                        maxlength="100"
                        autocomplete="email"
                    >
                </div>

                <div class="mb-3">
                    <label class="form-label" for="puhelin">Puhelinnumero</label>
                    <input
                        class="form-control"
                        type="tel"
                        id="puhelin"
                        name="puhelin"
                        value="<?= htmlspecialchars($puhelin, ENT_QUOTES, 'UTF-8') ?>"
                        required
                        maxlength="50"
                        autocomplete="tel"
                    >
                </div>

                <div class="mb-3">
                    <label class="form-label" for="rooli">Rooli</label>
                    <select class="form-select" id="rooli" name="rooli" required>
                        <option value="Opiskelija" <?= $rooli === "Opiskelija" ? "selected" : "" ?>>Oppilas</option>
                        <option value="Opettaja" <?= $rooli === "Opettaja" ? "selected" : "" ?>>Opettaja</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="salasana">Salasana (8–72 merkkiä)</label>
                    <input
                        class="form-control"
                        type="password"
                        id="salasana"
                        name="salasana"
                        required
                        minlength="8"
                        maxlength="72"
                        autocomplete="new-password"
                    >
                </div>

                <div class="mb-4">
                    <label class="form-label" for="salasana_uudelleen">Salasana uudelleen</label>
                    <input
                        class="form-control"
                        type="password"
                        id="salasana_uudelleen"
                        name="salasana_uudelleen"
                        required
                        minlength="8"
                        maxlength="72"
                        autocomplete="new-password"
                    >
                </div>

                <div class="d-flex justify-content-between align-items-center">
                    <button class="btn btn-primary" type="submit">Luo tili</button>
                    <a href="login.php" class="text-decoration-none">Onko sinulla jo tili? Kirjaudu sisään</a>
                </div>
            </form>
        </div>
    </div>
</main>

</body>
</html>
