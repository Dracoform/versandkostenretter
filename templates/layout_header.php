<?php

/** @var string $pageTitle */
/** @var string|null $bodyClass */
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Versandkostenretter: Finde günstige Extras in deinem Shop, um den Gratisversand zu erreichen. Rette deinen Warenkorb!">
<meta name="robots" content="<?= \Versandkostenretter\View::e($pageTitle === 'Versandkostenretter — Rette deinen Warenkorb!' ? 'index,follow' : 'noindex') ?>">
<meta name="color-scheme" content="light">
<meta name="theme-color" content="#0d9488">
<title><?= \Versandkostenretter\View::e($pageTitle) ?></title>
<link rel="stylesheet" href="<?= \Versandkostenretter\Assets::url('/assets/css/main.css') ?>">
</head>
<body class="<?= \Versandkostenretter\View::e($bodyClass ?? '') ?>">
<a class="skip-link" href="#main">Zum Inhalt springen</a>
<header class="site-header">
  <a class="brand" href="/">
    <span class="brand-badge" aria-hidden="true">
      <svg viewBox="0 0 64 64" width="34" height="34" role="img" focusable="false">
        <circle cx="32" cy="32" r="30" fill="#0d9488"/>
        <circle cx="32" cy="32" r="21" fill="none" stroke="#fff" stroke-width="6"/>
        <path d="M14 50 L50 14" stroke="#0d9488" stroke-width="6"/>
        <path d="M32 6 L32 18 M46 10 L40 20 M58 32 L46 32 M50 46 L40 40 M32 58 L32 46 M18 46 L24 40 M6 32 L18 32 M14 18 L24 24"
              stroke="#fff" stroke-width="5" stroke-linecap="round" fill="none"/>
      </svg>
    </span>
    <span class="brand-name">Versandkostenretter</span>
  </a>
</header>

<main id="main">
