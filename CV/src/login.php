<?php

// Istunnon tietoturva-asetukset
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

// Jos käyttäjä on jo kirjautunut
if (isset($_SESSION["kayttaja_id"])) {
    header("Location: index.php");
    exit;
}

// CSRF-suojaus
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$virhe = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $sahkoposti = trim($_POST["sahkoposti"] ?? "");
    $salasana = $_POST["salasana"] ?? "";
    $csrf = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["csrf_token"], $csrf)) {
        $virhe = "Virheellinen pyyntö.";
    } elseif (!filter_var($sahkoposti, FILTER_VALIDATE_EMAIL)) {
        $virhe = "Virheellinen sähköposti tai salasana.";
    } elseif (strlen($salasana) > 1024 || $salasana === "") {
        $virhe = "Virheellinen sähköposti tai salasana.";
    } else {

        $sql = "SELECT Kayttaja_id, Nimi, Salasana, Sahkoposti, Rooli
                FROM kayttaja
                WHERE Sahkoposti = :sahkoposti
                LIMIT 1";

        $stmt = $conn->prepare($sql);

        $stmt->execute([
            ":sahkoposti" => $sahkoposti
        ]);

        $kayttaja = $stmt->fetch();

        if ($kayttaja &&
            password_verify($salasana, $kayttaja["Salasana"])) {

            // Uusi istuntotunnus kirjautumisen jälkeen
            session_regenerate_id(true);

            $_SESSION["kayttaja_id"] = $kayttaja["Kayttaja_id"];
            $_SESSION["nimi"] = $kayttaja["Nimi"];
            $_SESSION["sahkoposti"] = $kayttaja["Sahkoposti"];
            $_SESSION["rooli"] = $kayttaja["Rooli"];

            // Uusi CSRF-token
            $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

            header("Location: index.php");
            exit;

        } else {
            $virhe = "Virheellinen sähköposti tai salasana.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kirjaudu sisään</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-light">

<main class="container py-5" style="max-width: 480px;">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h1 class="h3 mb-3">Kirjaudu sisään</h1>
            
            <?php if ($virhe !== ""): ?>
                <div class="alert alert-danger">
                    <?= htmlspecialchars($virhe, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, 'UTF-8') ?>">

                <div class="mb-3">
                    <label class="form-label" for="sahkoposti">Sähköposti</label>
                    <input class="form-control" type="email" id="sahkoposti" name="sahkoposti" required maxlength="100" autocomplete="email">
                </div>

                <div class="mb-3">
                    <label class="form-label" for="salasana">Salasana</label>
                    <input class="form-control" type="password" id="salasana" name="salasana" required maxlength="1024" autocomplete="current-password">
                </div>

                <button class="btn btn-primary w-100" type="submit">Kirjaudu</button>
            </form>
        </div>
    </div>
</main>

</body>
</html>