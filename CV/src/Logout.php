<?php

session_start();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    exit("Virheellinen pyyntö.");
}

$csrf = $_POST["csrf_token"] ?? "";

if (
    empty($_SESSION["csrf_token"]) ||
    !hash_equals($_SESSION["csrf_token"], $csrf)
) {
    http_response_code(403);
    exit("Virheellinen pyyntö.");
}

// Tyhjennetään istunnon tiedot
$_SESSION = [];

// Poistetaan istuntoeväste
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        "",
        [
            "expires" => time() - 42000,
            "path" => $params["path"],
            "domain" => $params["domain"],
            "secure" => $params["secure"],
            "httponly" => $params["httponly"],
            "samesite" => "Lax"
        ]
    );
}

session_destroy();

header("Location: signin.php");
exit;

?>