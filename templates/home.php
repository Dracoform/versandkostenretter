<?php
/** @var array $shops */
/** @var string $pageTitle */

require __DIR__ . '/layout_header.php';
?>

<section class="hero">
  <h1 class="hero-title">
    <span class="hero-mascot" aria-hidden="true">
      <!-- Friendly raccoon-with-lifebuoy mascot, inline SVG (local asset, no CDN) -->
      <svg viewBox="0 0 120 100" width="150" height="125" role="img" aria-label="Maskottchen: Waschbär mit Rettungsring">
        <ellipse cx="60" cy="92" rx="34" ry="6" fill="#0f766e" opacity=".15"/>
        <!-- tail -->
        <path d="M88 70 q22 -6 24 -24 q-14 8 -26 6 z" fill="#57534e"/>
        <path d="M96 62 q12 -6 14 -16 q-10 4 -18 4 z" fill="#292524"/>
        <!-- body -->
        <ellipse cx="58" cy="64" rx="26" ry="22" fill="#78716c"/>
        <ellipse cx="58" cy="70" rx="17" ry="13" fill="#e7e5e4"/>
        <!-- head -->
        <circle cx="58" cy="38" r="21" fill="#78716c"/>
        <!-- ears -->
        <circle cx="41" cy="23" r="8" fill="#57534e"/>
        <circle cx="75" cy="23" r="8" fill="#57534e"/>
        <!-- mask -->
        <path d="M40 34 q8 -6 17 -2 q9 -4 17 2 q-13 2 -17 -8 z" fill="#292524"/>
        <!-- eyes -->
        <circle cx="50" cy="36" r="3.4" fill="#fff"/>
        <circle cx="67" cy="36" r="3.4" fill="#fff"/>
        <!-- nose -->
        <ellipse cx="58" cy="46" rx="3.4" ry="2.6" fill="#1c1917"/>
        <!-- lifebuoy -->
        <circle cx="92" cy="58" r="15" fill="#fff" stroke="#ea580c" stroke-width="6"/>
        <circle cx="92" cy="58" r="15" fill="none" stroke="#fff" stroke-width="2"/>
        <path d="M92 43 v30 M77 58 h30" stroke="#ea580c" stroke-width="4"/>
      </svg>
    </span>
    Rette deinen Warenkorb!
  </h1>
  <p class="hero-sub">Kleine Extras. Große Wirkung.</p>
  <p class="hero-expl">
    Du stehst kurz vor dem Gratisversand? Wir zeigen dir günstige Produkte
    aus <strong>deinem Shop</strong>, mit denen du die Schwelle erreichst.
  </p>
  <figure class="hero-illustration">
    <img src="/assets/images/hero-raccoon.jpg"
         alt="Maskottchen: Ein Waschbär auf einem Rettungsring zieht einen Einkaufswagen sicher durch die Wellen."
         width="900" height="437" fetchpriority="high" decoding="async">
  </figure>
</section>

<section class="card form-card" aria-labelledby="form-heading">
  <h2 id="form-heading" class="visually-hidden">Warenkorbwert prüfen</h2>
  <!-- GET-only lookup: read-only operation, works without JavaScript,
       sets no cookies, results are shareable/bookmarkable URLs. -->
  <form method="get" action="/" class="search-form" novalidate>
    <div class="field">
      <label for="shop">Shop auswählen</label>
      <select id="shop" name="shop" required>
        <option value="" selected disabled>Bitte wählen …</option>
        <?php foreach ($shops as $shop): ?>
          <option value="<?= \Versandkostenretter\View::e($shop['slug']) ?>">
            <?= \Versandkostenretter\View::e($shop['name']) ?>
            (Gratis ab <?= \Versandkostenretter\Money::formatEuro($shop['free_shipping_threshold_cents']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="cart">Aktueller Warenkorbwert</label>
      <div class="money-input">
        <input type="text" id="cart" name="cart"
               inputmode="decimal" autocomplete="off"
               placeholder="z. B. 125,34" required>
      </div>
    </div>

    <button type="submit" class="btn-primary">Show me the goods!</button>
  </form>
</section>

<?php if (($rescueAttempts ?? 0) > 0): ?>
<p class="rescue-counter">
  <span aria-hidden="true">🛟</span>
  Schon <strong><?= \Versandkostenretter\View::e(number_format($rescueAttempts, 0, ',', '.')) ?></strong>
  <?= $rescueAttempts === 1 ? 'Rettungsversuch' : 'Rettungsversuche' ?> gestartet!
</p>
<?php endif; ?>

<?php if (empty($shops)): ?>
<section class="notice">
  <p>Aktuell sind keine Shops verfügbar. Bitte schau später wieder vorbei.</p>
</section>
<?php endif; ?>

<section class="info-strip">
  <div class="info-item">
    <span class="info-icon" aria-hidden="true">🛒</span>
    <p>Shop wählen &amp; Warenkorbwert eingeben</p>
  </div>
  <div class="info-item">
    <span class="info-icon" aria-hidden="true">🎯</span>
    <p>Wir rechnen aus, was dir zur Gratisversand-Schwelle fehlt</p>
  </div>
  <div class="info-item">
    <span class="info-icon" aria-hidden="true">🦝</span>
    <p>Du bekommst passende Extras – aufsteigend nach Preis</p>
  </div>
</section>

<?php require __DIR__ . '/layout_footer.php'; ?>
