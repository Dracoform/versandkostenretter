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
  <a class="brand" href="/" aria-label="Versandkostenretter — Startseite">
    <!-- Wordmark (transparent WebP) replaces the text-only branding.
         Sized responsively via CSS; alt text carries the brand name. -->
    <img class="brand-wordmark"
         src="<?= \Versandkostenretter\Assets::url('/assets/images/wordmark.webp') ?>"
         alt="Versandkostenretter" width="2172" height="724" decoding="async">
  </a>
</header>

<main id="main">
