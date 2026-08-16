<?php

declare(strict_types=1);

/**
 * Shown when a /demo/{code} instance is missing, deleted or not provisioned.
 */
$code = strtolower(trim((string) ($_GET['code'] ?? '')));
if (!preg_match('/^[a-f0-9]{10}$/', $code)) {
    $code = '';
}

$siteHome = 'https://www.socly.it/';
$contactMail = 'info@socly.it';
?><!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Demo non disponibile · SOCLY</title>
  <link rel="icon" href="https://www.socly.it/img/icon.png" type="image/png">
  <style>
    :root { color-scheme: light; }
    body {
      margin: 0; min-height: 100vh; display: grid; place-items: center;
      font-family: Manrope, system-ui, sans-serif; background: #eef5f3; color: #0b3d38;
      padding: 1.5rem;
    }
    .card {
      max-width: 32rem; width: 100%; background: #fff; border-radius: 18px;
      padding: 1.75rem 1.5rem; box-shadow: 0 18px 50px rgba(11,61,56,.12);
      border: 1px solid #d5e6e2;
    }
    h1 { margin: 0 0 .75rem; font-size: 1.45rem; letter-spacing: -.02em; }
    p { margin: 0 0 .85rem; line-height: 1.55; color: #456660; }
    .actions { display: flex; flex-wrap: wrap; gap: .65rem; margin-top: 1.25rem; }
    a.btn {
      display: inline-flex; align-items: center; justify-content: center;
      padding: .7rem 1rem; border-radius: 12px; font-weight: 700; text-decoration: none;
      background: #0D6E66; color: #fff;
    }
    a.btn.ghost { background: #fff; color: #0D6E66; border: 1px solid #b9d4cf; }
    code { font-size: .85em; background: #f3f8f7; padding: .1rem .35rem; border-radius: 6px; }
  </style>
</head>
<body>
  <main class="card">
    <h1>Demo scaduta o non disponibile</h1>
    <p>
      L’istanza demo<?= $code !== '' ? ' <code>' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</code>' : '' ?>
      non esiste più oppure è scaduta.
    </p>
    <p>Puoi tornare al sito SOCLY per richiedere una nuova demo o contattarci.</p>
    <div class="actions">
      <a class="btn" href="<?= htmlspecialchars($siteHome, ENT_QUOTES, 'UTF-8') ?>">Torna a socly.it</a>
      <a class="btn ghost" href="mailto:<?= htmlspecialchars($contactMail, ENT_QUOTES, 'UTF-8') ?>">Scrivi a <?= htmlspecialchars($contactMail, ENT_QUOTES, 'UTF-8') ?></a>
    </div>
  </main>
</body>
</html>
