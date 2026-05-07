<?php
/*
Plugin Name: Tesoro Resales Page
Description: Page proprietes For Resales depuis l'API Tesoro MLS — shortcode [tesoro_resales]
Version: 2.1
*/

function tesoro_resales_shortcode() {

    $API_BASE = 'https://sale.vidaensol.com/api/tesoro/properties';

    $preload_props   = null;
    $preload_filters = null;

    $ctx = stream_context_create(['http' => ['timeout' => 8]]);

    $raw_props = @file_get_contents($API_BASE . '?page=1&limit=20', false, $ctx);
    if ($raw_props) $preload_props = json_decode($raw_props, true);

    $raw_filt = @file_get_contents($API_BASE . '/filters', false, $ctx);
    if ($raw_filt) $preload_filters = json_decode($raw_filt, true);

    $js_props   = $preload_props   ? json_encode($preload_props)   : 'null';
    $js_filters = $preload_filters ? json_encode($preload_filters) : 'null';

    ob_start();
    ?>

<!-- RESET WORDPRESS CONTAINER — pleine largeur -->
<style>
/* Force full-width en dehors du shortcode pour casser les conteneurs WP */
.tsr-fullwidth-wrapper {
  width: 100vw !important;
  max-width: 100vw !important;
  margin-left: calc(-50vw + 50%) !important;
  margin-right: calc(-50vw + 50%) !important;
  padding-left: 0 !important;
  padding-right: 0 !important;
  overflow-x: hidden;
}
/* Neutralise les conteneurs parents courants de WP themes */
.tsr-fullwidth-wrapper ~ *,
.entry-content,
.post-content,
.site-content,
.wp-block-group,
.elementor-widget-container {
  /* Pas touché — on cible uniquement notre wrapper */
}
</style>

<div class="tsr-fullwidth-wrapper">
<div id="tsr-app">
  <div class="tsr-filter-wrap">
    <div class="tsr-filter-bar">
      <div class="tsr-search-box">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        <input type="text" id="tsr-search" placeholder="Search town, province...">
      </div>
      <select id="tsr-town" class="tsr-sel"><option value="">All Towns</option></select>
      <select id="tsr-type" class="tsr-sel"><option value="">All Types</option></select>
      <select id="tsr-beds" class="tsr-sel">
        <option value="">Any Beds</option>
        <option value="1">1+ Beds</option>
        <option value="2">2+ Beds</option>
        <option value="3">3+ Beds</option>
        <option value="4">4+ Beds</option>
      </select>
      <select id="tsr-price-min" class="tsr-sel"><option value="">Min Price</option></select>
      <select id="tsr-price-max" class="tsr-sel"><option value="">Max Price</option></select>
      <button class="tsr-reset-btn" id="tsr-reset">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
        Reset
      </button>
    </div>
    <div class="tsr-filter-meta"><span id="tsr-count">Loading...</span></div>
  </div>
  <div class="tsr-grid" id="tsr-grid"></div>
  <div class="tsr-pagination" id="tsr-pagination"></div>
</div>
</div><!-- /.tsr-fullwidth-wrapper -->

<!-- SVG icons (hidden) -->
<div style="display:none" aria-hidden="true">
  <span id="tsr-i-bed"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 9V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v4M2 9h20M2 9v10M22 9v10M2 19h20"/></svg></span>
  <span id="tsr-i-bath"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6 6.5 3.5a1.5 1.5 0 0 0-1-.5C4.683 3 4 3.683 4 4.5V17a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5"/><line x1="10" x2="8" y1="5" y2="7"/><line x1="2" x2="22" y1="12" y2="12"/></svg></span>
  <span id="tsr-i-area"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18M3 9h18"/></svg></span>
  <span id="tsr-i-pool"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 20c.6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1 .6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1M2 16c.6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1 .6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1"/><circle cx="12" cy="5" r="3"/><path d="m10.2 6.3 3.6 3.6"/></svg></span>
  <span id="tsr-i-cam"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/></svg></span>
  <span id="tsr-i-ext"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" x2="21" y1="14" y2="3"/></svg></span>
  <span id="tsr-i-shr"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" x2="15.42" y1="13.51" y2="17.49"/><line x1="15.41" x2="8.59" y1="6.51" y2="10.49"/></svg></span>
  <span id="tsr-i-mail"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></span>
  <span id="tsr-i-ibed"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#a07030" stroke-width="1.8"><path d="M2 9V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v4M2 9h20M2 9v10M22 9v10M2 19h20"/></svg></span>
  <span id="tsr-i-ibth"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#a07030" stroke-width="1.8"><path d="M9 6 6.5 3.5a1.5 1.5 0 0 0-1-.5C4.683 3 4 3.683 4 4.5V17a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5"/><line x1="10" x2="8" y1="5" y2="7"/><line x1="2" x2="22" y1="12" y2="12"/></svg></span>
  <span id="tsr-i-iarea"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#a07030" stroke-width="1.8"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18M3 9h18"/></svg></span>
  <span id="tsr-i-iplot"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#a07030" stroke-width="1.8"><path d="M3 17l4-8 4 4 4-6 4 10H3z"/></svg></span>
  <span id="tsr-i-ipool"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#a07030" stroke-width="1.8"><path d="M2 20c.6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1 .6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1M2 16c.6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1 .6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1"/><circle cx="12" cy="5" r="3"/><path d="m10.2 6.3 3.6 3.6"/></svg></span>
  <span id="tsr-i-inew"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#a07030" stroke-width="1.8"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></span>
  <span id="tsr-i-ienrg"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#a07030" stroke-width="1.8"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></span>
  <span id="tsr-i-inop"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#8a7a6a" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg></span>
</div>

<!-- Modal -->
<div class="tsr-modal-overlay" id="tsr-modal-overlay">
  <div class="tsr-modal">
    <button class="tsr-modal-close" id="tsr-modal-close">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18M6 6l12 12"/></svg>
    </button>
    <div class="tsr-modal-gallery">
      <div class="tsr-gallery-track" id="tsr-gallery-track"></div>
      <button class="tsr-gal-btn tsr-gal-prev" id="tsr-gal-prev"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6"/></svg></button>
      <button class="tsr-gal-btn tsr-gal-next" id="tsr-gal-next"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg></button>
      <div class="tsr-gal-counter" id="tsr-gal-counter">1 / 1</div>
    </div>
    <div class="tsr-modal-body" id="tsr-modal-body"></div>
  </div>
</div>

<style>
/* ═══════════════════════════════════════════════════
   VARIABLES
═══════════════════════════════════════════════════ */
:root{
  --sand:#fff;--clay:#FFF;--clay-dark:#e0a030;
  --ink:#1a1410;--ink-mid:#4a3f35;--ink-light:#8a7a6a;
  --sea:#2e6b8a;--sea-light:#e8f4f9;
  --white:#ffffff;--card-bg:#fffdf9;--border:#e8dfd0;
  --radius-lg:20px;
  --shadow:0 4px 24px rgba(26,20,16,.10);
  --shadow-hov:0 12px 40px rgba(26,20,16,.18);
  --font-serif:'Playfair Display',Georgia,serif;
  --font-sans:'DM Sans',system-ui,sans-serif;
  --tr:.25s cubic-bezier(.4,0,.2,1);

  /* Typographie fluide : clamp(min, préféré, max) */
  --fs-xs:   clamp(10px, 1.1vw, 12px);
  --fs-sm:   clamp(12px, 1.3vw, 14px);
  --fs-base: clamp(14px, 1.5vw, 16px);
  --fs-lg:   clamp(16px, 1.8vw, 20px);
  --fs-xl:   clamp(18px, 2.2vw, 24px);
  --fs-2xl:  clamp(22px, 3vw,   32px);

  /* Espacement fluide */
  --gap-sm:  clamp(8px,  1vw,  12px);
  --gap-md:  clamp(16px, 2vw,  24px);
  --gap-lg:  clamp(24px, 3vw,  40px);
  --pad-x:   clamp(16px, 4vw,  60px);
}

/* ═══════════════════════════════════════════════════
   BASE — PLEINE LARGEUR
═══════════════════════════════════════════════════ */
#tsr-app * { box-sizing:border-box; margin:0; padding:0; }

#tsr-app {
  font-family: var(--font-sans);
  font-size: var(--fs-base);
  color: var(--ink);
  background: var(--white);
  min-height: 60vh;
  /* Pleine largeur — écrase 90dvw de la v1 */
  width: 100%;
  max-width: 100%;
  margin: 0;
  overflow-x: hidden;
  padding-inline :10%;
}

/* ═══════════════════════════════════════════════════
   BARRE DE FILTRES — sticky, pleine largeur
═══════════════════════════════════════════════════ */
.tsr-filter-wrap {
  background: var(--white);
  border-bottom: 1px solid var(--border);
  padding: var(--gap-sm) var(--pad-x) var(--gap-sm);
  position: sticky;
  top: 0;
  z-index: 100;
  box-shadow: 0 2px 12px rgba(26,20,16,.06);
  width: 100%;
}

.tsr-filter-bar {
  display: flex;
  flex-wrap: wrap;
  gap: var(--gap-sm);
  align-items: center;
  width: 100%;
  max-width: 1600px;
  margin: 0 auto;
}

.tsr-search-box {
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--sand);
  border: 1.5px solid var(--border);
  border-radius: 8px;
  padding: 0 14px;
  flex: 1 1 200px;
  min-width: 160px;
  color: var(--ink-light);
}
.tsr-search-box input {
  border: none;
  background: transparent;
  font-family: var(--font-sans);
  font-size: var(--fs-sm);
  color: var(--ink);
  width: 100%;
  padding: 10px 0;
  outline: none;
}
.tsr-search-box input::placeholder { color: var(--ink-light); }

.tsr-sel {
  background: var(--sand);
  border: 1.5px solid var(--border);
  border-radius: 8px;
  font-family: var(--font-sans);
  font-size: var(--fs-sm);
  color: var(--ink);
  padding: 10px 12px;
  cursor: pointer;
  outline: none;
  transition: border-color var(--tr);
  min-width: 110px;
  flex: 1 1 110px;
}
.tsr-sel:hover,.tsr-sel:focus { border-color: var(--clay); }

.tsr-reset-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  background: transparent;
  border: 1.5px solid var(--border);
  border-radius: 8px;
  padding: 10px 14px;
  font-family: var(--font-sans);
  font-size: var(--fs-sm);
  color: var(--ink-light);
  cursor: pointer;
  transition: all var(--tr);
  white-space: nowrap;
}
.tsr-reset-btn:hover { border-color: var(--clay); color: var(--clay); }

.tsr-filter-meta {
  max-width: 1600px;
  margin: 8px auto 0;
  font-size: var(--fs-xs);
  color: var(--ink-light);
}

/* ═══════════════════════════════════════════════════
   GRILLE CARDS — pleine largeur, max 4 colonnes
═══════════════════════════════════════════════════ */
.tsr-grid {
  display: grid;
  /* Colonnes auto-responsives : min 280px, max 1fr */
  grid-template-columns: repeat(auto-fill, minmax(clamp(260px, 28vw, 340px), 1fr));
  gap: var(--gap-md);
  padding: var(--gap-lg) var(--pad-x);
  width: 100%;
  max-width: 1600px;
  margin: 0 auto;
}

/* ═══════════════════════════════════════════════════
   CARD
═══════════════════════════════════════════════════ */
.tsr-card {
  background: var(--card-bg);
  border-radius: var(--radius-lg);
  overflow: hidden;
  border: 1px solid var(--border);
  box-shadow: var(--shadow);
  transition: transform var(--tr), box-shadow var(--tr);
  cursor: pointer;
  animation: tsr-fadeup .4s ease both;
}
.tsr-card:hover { transform: translateY(-6px); box-shadow: var(--shadow-hov); }

@keyframes tsr-fadeup {
  from { opacity:0; transform:translateY(20px); }
  to   { opacity:1; transform:translateY(0); }
}

.tsr-card-img {
  position: relative;
  /* Hauteur fluide */
  height: clamp(180px, 22vw, 260px);
  overflow: hidden;
  background: var(--border);
}
.tsr-card-img img {
  width: 100%; height: 100%;
  object-fit: cover;
  transition: transform .5s ease;
}
.tsr-card:hover .tsr-card-img img { transform: scale(1.05); }

.tsr-card-badge {
  position: absolute;
  top: 12px; left: 12px;
  background: var(--clay);
  color: var(--ink);
  font-size: var(--fs-xs);
  font-weight: 700;
  letter-spacing: .1em;
  text-transform: uppercase;
  padding: 4px 10px;
  border-radius: 100px;
}
.tsr-card-badge.new  { background: var(--sea);    color: var(--white); }
.tsr-card-badge.rent { background: #6b9e2e;        color: var(--white); }

.tsr-card-photo-count {
  position: absolute;
  bottom: 10px; right: 10px;
  background: rgba(26,20,16,.7);
  color: var(--white);
  font-size: var(--fs-xs);
  padding: 3px 10px;
  border-radius: 100px;
  display: flex;
  align-items: center;
  gap: 5px;
}

.tsr-card-body { padding: clamp(14px,1.5vw,20px) clamp(14px,1.5vw,20px) clamp(16px,1.8vw,22px); }

.tsr-card-location {
  font-size: var(--fs-xs);
  font-weight: 600;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: #a07030;
  margin-bottom: 6px;
}

.tsr-card-title {
  font-family: var(--font-serif);
  font-size: var(--fs-lg);
  font-weight: 700;
  color: var(--ink);
  line-height: 1.25;
  margin-bottom: 10px;
}

.tsr-card-specs {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}
.tsr-card-spec {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: var(--fs-xs);
  color: var(--ink-mid);
  font-weight: 500;
}
.tsr-card-spec svg { color: #a07030; flex-shrink: 0; }

.tsr-card-bottom {
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-top: 1px solid var(--border);
  padding-top: 12px;
}

.tsr-card-price {
  font-family: var(--font-serif);
  font-size: var(--fs-xl);
  font-weight: 700;
  color: var(--ink);
}
.tsr-card-price small {
  font-family: var(--font-sans);
  font-size: var(--fs-xs);
  color: var(--ink-light);
  font-weight: 400;
}

.tsr-card-btn {
  background: var(--clay);
  color: var(--ink);
  border: none;
  border-radius: 8px;
  padding: 9px 16px;
  font-size: var(--fs-xs);
  font-weight: 700;
  cursor: pointer;
  transition: background var(--tr);
  font-family: var(--font-sans);
}
.tsr-card-btn:hover { background: var(--clay-dark); color: var(--white); }

/* ═══════════════════════════════════════════════════
   SKELETONS
═══════════════════════════════════════════════════ */
.tsr-skeleton {
  background: var(--card-bg);
  border-radius: var(--radius-lg);
  border: 1px solid var(--border);
  overflow: hidden;
  animation: tsr-pulse 1.6s ease-in-out infinite;
}
@keyframes tsr-pulse { 0%,100%{opacity:1;} 50%{opacity:.5;} }
.tsr-sk-img { height: clamp(180px, 22vw, 260px); background: var(--border); }
.tsr-sk-body { padding: 18px 20px 20px; }
.tsr-sk-line { height:12px; background:var(--border); border-radius:6px; margin-bottom:10px; }
.tsr-sk-line.short{width:60%;} .tsr-sk-line.med{width:80%;} .tsr-sk-line.long{width:100%;}

.tsr-empty {
  text-align: center;
  padding: 80px 24px;
  grid-column: 1 / -1;
}
.tsr-empty-icon { font-size: clamp(36px, 5vw, 56px); margin-bottom: 16px; }
.tsr-empty h3 {
  font-family: var(--font-serif);
  font-size: var(--fs-xl);
  color: var(--ink);
  margin-bottom: 8px;
}
.tsr-empty p { color: var(--ink-light); font-size: var(--fs-base); }

/* ═══════════════════════════════════════════════════
   PAGINATION
═══════════════════════════════════════════════════ */
.tsr-pagination {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: var(--gap-md) var(--pad-x) clamp(32px, 5vw, 60px);
  flex-wrap: wrap;
  width: 100%;
}

.tsr-page-btn {
  min-width: 40px;
  height: 40px;
  border: 1.5px solid var(--border);
  background: var(--white);
  border-radius: 8px;
  font-family: var(--font-sans);
  font-size: var(--fs-sm);
  font-weight: 500;
  color: var(--ink);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 0 10px;
  transition: all var(--tr);
}
.tsr-page-btn:hover:not([disabled]) { border-color: var(--clay); color: #a07030; }
.tsr-page-btn.active { background: var(--clay); border-color: var(--clay); color: var(--ink); font-weight:700; }
.tsr-page-btn[disabled] { opacity:.4; cursor:default; pointer-events:none; }
.tsr-page-dots { color: var(--ink-light); padding: 0 4px; line-height: 40px; }

/* ═══════════════════════════════════════════════════
   MODAL
═══════════════════════════════════════════════════ */
.tsr-modal-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(26,20,16,.75);
  z-index: 99999;
  align-items: flex-start;
  justify-content: center;
  padding: 60px 16px 20px;
  overflow-y: auto;
  backdrop-filter: blur(4px);
}
.tsr-modal-overlay.open { display: flex; }

.tsr-modal {
  background: var(--white);
  border-radius: var(--radius-lg);
  width: 100%;
  max-width: clamp(600px, 70vw, 920px);
  position: relative;
  overflow: hidden;
  box-shadow: 0 32px 80px rgba(26,20,16,.3);
  animation: tsr-modal-in .35s cubic-bezier(.4,0,.2,1);
}
@keyframes tsr-modal-in {
  from { opacity:0; transform:translateY(30px) scale(.97); }
  to   { opacity:1; transform:none; }
}

.tsr-modal-close {
  position: absolute;
  top: 16px; right: 16px;
  z-index: 10;
  width: 40px; height: 40px;
  background: rgba(26,20,16,.6);
  border: none;
  border-radius: 50%;
  cursor: pointer;
  color: var(--white);
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background var(--tr);
}
.tsr-modal-close:hover { background: var(--clay); color: var(--ink); }

.tsr-modal-gallery {
  position: relative;
  height: clamp(240px, 35vw, 420px);
  background: var(--ink);
  overflow: hidden;
}
.tsr-gallery-track {
  display: flex;
  height: 100%;
  transition: transform .4s cubic-bezier(.4,0,.2,1);
}
.tsr-gallery-track img {
  flex-shrink: 0;
  width: 100%; height: 100%;
  object-fit: cover;
}
.tsr-gal-btn {
  position: absolute;
  top: 50%; transform: translateY(-50%);
  width: 44px; height: 44px;
  background: rgba(26,20,16,.65);
  border: none; border-radius: 50%;
  color: var(--white);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background var(--tr);
}
.tsr-gal-btn:hover { background: var(--clay); color: var(--ink); }
.tsr-gal-prev { left: 16px; } .tsr-gal-next { right: 16px; }
.tsr-gal-counter {
  position: absolute;
  bottom: 14px; right: 16px;
  background: rgba(26,20,16,.65);
  color: var(--white);
  font-size: var(--fs-xs);
  padding: 4px 12px;
  border-radius: 100px;
}

.tsr-modal-body { padding: clamp(20px,3vw,36px) clamp(20px,3vw,36px) clamp(28px,4vw,48px); }

.tsr-modal-top {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}
.tsr-modal-loc {
  font-size: var(--fs-xs);
  font-weight: 700;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: #a07030;
  margin-bottom: 6px;
}
.tsr-modal-title {
  font-family: var(--font-serif);
  font-size: var(--fs-2xl);
  font-weight: 700;
  color: var(--ink);
  line-height: 1.2;
}
.tsr-modal-price-box { text-align: right; flex-shrink: 0; }
.tsr-modal-price {
  font-family: var(--font-serif);
  font-size: var(--fs-2xl);
  font-weight: 700;
  color: #a07030;
}
.tsr-modal-freq { font-size: var(--fs-xs); color: var(--ink-light); }

.tsr-modal-specs {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(90px, 1fr));
  gap: 10px;
  margin-bottom: 22px;
}
.tsr-modal-spec-card {
  background: var(--sand);
  border-radius: 10px;
  padding: 14px 12px;
  text-align: center;
}
.tsr-modal-spec-card .icon { font-size: 22px; margin-bottom: 6px; }
.tsr-modal-spec-card .label {
  font-size: var(--fs-xs);
  font-weight: 700;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: var(--ink-light);
  margin-bottom: 4px;
}
.tsr-modal-spec-card .value { font-size: var(--fs-sm); font-weight: 600; color: var(--ink); }

.tsr-modal-section { margin-bottom: 22px; }
.tsr-modal-section h4 {
  font-size: var(--fs-xs);
  font-weight: 700;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: #a07030;
  margin-bottom: 10px;
  padding-bottom: 8px;
  border-bottom: 1px solid var(--border);
}
.tsr-modal-desc {
  font-size: var(--fs-sm);
  line-height: 1.75;
  color: var(--ink-mid);
  max-height: 200px;
  overflow-y: auto;
  padding-right: 8px;
}
.tsr-modal-desc::-webkit-scrollbar { width: 4px; }
.tsr-modal-desc::-webkit-scrollbar-track { background: var(--sand); }
.tsr-modal-desc::-webkit-scrollbar-thumb { background: var(--clay); border-radius: 4px; }

.tsr-features { display: flex; flex-wrap: wrap; gap: 8px; }
.tsr-feature-tag {
  background: var(--sea-light);
  color: var(--sea);
  font-size: var(--fs-xs);
  font-weight: 500;
  padding: 5px 12px;
  border-radius: 100px;
}

.tsr-modal-actions {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  margin-top: 24px;
}
.tsr-btn-primary {
  flex: 1;
  min-width: 130px;
  background: var(--clay);
  color: var(--ink);
  border: none;
  border-radius: 10px;
  padding: 14px 20px;
  font-size: var(--fs-sm);
  font-weight: 700;
  cursor: pointer;
  transition: background var(--tr);
  font-family: var(--font-sans);
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}
.tsr-btn-primary:hover { background: var(--clay-dark); color: var(--white); }

.tsr-btn-secondary {
  background: transparent;
  color: var(--ink-mid);
  border: 1.5px solid var(--border);
  border-radius: 10px;
  padding: 14px 20px;
  font-size: var(--fs-sm);
  font-weight: 500;
  cursor: pointer;
  transition: all var(--tr);
  font-family: var(--font-sans);
  display: flex;
  align-items: center;
  gap: 8px;
}
.tsr-btn-secondary:hover { border-color: var(--clay); color: #a07030; }

/* ═══════════════════════════════════════════════════
   RESPONSIVE
═══════════════════════════════════════════════════ */

/* Tablette : 2 colonnes fixes si auto-fill ne suffit pas */
@media (max-width: 900px) {
  .tsr-grid {
    grid-template-columns: repeat(2, 1fr);
    padding: var(--gap-md) var(--pad-x);
    gap: var(--gap-sm);
  }
}

/* Mobile */
@media (max-width: 600px) {
  .tsr-filter-bar { flex-direction: column; }
  .tsr-search-box,
  .tsr-sel,
  .tsr-reset-btn { width: 100%; min-width: 0; flex: 1 1 100%; }

  .tsr-grid {
    grid-template-columns: 1fr;
    padding: 12px var(--pad-x);
    gap: 12px;
  }

  .tsr-modal-gallery { height: clamp(200px, 55vw, 280px); }
  .tsr-modal-overlay {
    padding: 0;
    align-items: flex-end;
  }
  .tsr-modal {
    border-radius: var(--radius-lg) var(--radius-lg) 0 0;
    max-height: 92vh;
    overflow-y: auto;
    max-width: 100%;
  }
  .tsr-modal-specs {
    grid-template-columns: repeat(3, 1fr);
  }
}

/* Très grands écrans : confort de lecture */
@media (min-width: 1600px) {
  .tsr-grid {
    grid-template-columns: repeat(4, 1fr);
    max-width: 1800px;
  }
  .tsr-filter-bar,
  .tsr-filter-meta {
    max-width: 1800px;
  }
}
</style>

<script>
(function () {
  'use strict';

  var API  = 'https://sale.vidaensol.com/api/tesoro/properties';
  var FILT = 'https://sale.vidaensol.com/api/tesoro/properties/filters';
  var LIMIT = 20;

  var PRELOAD_DATA    = <?php echo $js_props; ?>;
  var PRELOAD_FILTERS = <?php echo $js_filters; ?>;

  var page = 1, totalPages = 1, total = 0;
  var filters = { search:'', town:'', type:'', beds:'', priceMin:'', priceMax:'' };
  var propMap = {};
  var loading = false;

  function $(id){ return document.getElementById(id); }
  var $grid    = $('tsr-grid');
  var $pager   = $('tsr-pagination');
  var $count   = $('tsr-count');
  var $search  = $('tsr-search');
  var $town    = $('tsr-town');
  var $type    = $('tsr-type');
  var $beds    = $('tsr-beds');
  var $pMin    = $('tsr-price-min');
  var $pMax    = $('tsr-price-max');
  var $reset   = $('tsr-reset');
  var $overlay = $('tsr-modal-overlay');
  var $mClose  = $('tsr-modal-close');
  var $track   = $('tsr-gallery-track');
  var $mBody   = $('tsr-modal-body');
  var $prev    = $('tsr-gal-prev');
  var $next    = $('tsr-gal-next');
  var $gcnt    = $('tsr-gal-counter');

  var galIdx = 0, galImgs = [];

  function svg(id){ return document.getElementById(id).innerHTML; }
  var ICO = {
    bed:svg('tsr-i-bed'), bath:svg('tsr-i-bath'), area:svg('tsr-i-area'), pool:svg('tsr-i-pool'),
    cam:svg('tsr-i-cam'), ext:svg('tsr-i-ext'),  shr:svg('tsr-i-shr'),   mail:svg('tsr-i-mail'),
    ibed:svg('tsr-i-ibed'),  ibth:svg('tsr-i-ibth'),  iarea:svg('tsr-i-iarea'), iplot:svg('tsr-i-iplot'),
    ipool:svg('tsr-i-ipool'),inew:svg('tsr-i-inew'),  ienrg:svg('tsr-i-ienrg'), inop:svg('tsr-i-inop')
  };

  function parseDescField(raw) {
    if (!raw) return {};
    if (typeof raw === 'object' && !Array.isArray(raw)) return raw;
    if (typeof raw === 'string') {
      var result = {};
      var langs = ['en','es','fr','nl','de'];
      langs.forEach(function(lang) {
        var re = new RegExp('<' + lang + '>([\\s\\S]*?)<\\/' + lang + '>', 'i');
        var m = raw.match(re);
        if (m && m[1]) {
          result[lang] = m[1]
            .replace(/&lt;/g,   '<')
            .replace(/&gt;/g,   '>')
            .replace(/&amp;/g,  '&')
            .replace(/&quot;/g, '"')
            .replace(/&#039;/g, "'")
            .replace(/&apos;/g, "'");
        }
      });
      return result;
    }
    return {};
  }

  function parseUrlField(raw) {
    if (!raw) return {};
    if (typeof raw === 'object' && !Array.isArray(raw)) return raw;
    if (typeof raw === 'string') {
      var result = {};
      ['en','es','fr','nl','de'].forEach(function(lang) {
        var re = new RegExp('<' + lang + '>([^<]+)<\\/' + lang + '>', 'i');
        var m = raw.match(re);
        if (m && m[1]) result[lang] = m[1].trim();
      });
      return result;
    }
    return {};
  }

  function getDesc(p) {
    if (!p) return 'No description available.';
    var d = parseDescField(p.description || p.desc);
    return d.en || d.es || d.fr || d.nl || d.de || 'No description available.';
  }

  function getUrlEn(p) {
    if (!p) return null;
    var u = parseUrlField(p.urls || p.url);
    return u.en || null;
  }

  function getImages(p) {
    if (!p) return [];
    var imgs = p.images || [];
    if (Array.isArray(imgs) && imgs.length && imgs[0] && imgs[0].url) return imgs;
    return [];
  }

  function showSkeletons() {
    var h = '';
    for (var i = 0; i < 9; i++) {
      h += '<div class="tsr-skeleton">'
        + '<div class="tsr-sk-img"></div>'
        + '<div class="tsr-sk-body">'
        + '<div class="tsr-sk-line short"></div>'
        + '<div class="tsr-sk-line med" style="height:18px;margin-bottom:14px"></div>'
        + '<div class="tsr-sk-line long"></div><div class="tsr-sk-line long"></div>'
        + '<div class="tsr-sk-line short" style="margin-top:14px;height:16px"></div>'
        + '</div></div>';
    }
    $grid.innerHTML = h;
  }

  function initFilters(data) {
    $town.innerHTML = '<option value="">All Towns</option>';
    (data.towns || []).forEach(function(v){ $town.add(new Option(v, v)); });
    $type.innerHTML = '<option value="">All Types</option>';
    (data.types || []).forEach(function(v){ $type.add(new Option(v, v)); });
    var steps = [
      50000,100000,150000,200000,250000,300000,350000,400000,450000,500000,
      600000,700000,800000,900000,1000000,1250000,1500000,2000000,3000000
    ];
    $pMin.innerHTML = '<option value="">Min Price</option>';
    $pMax.innerHTML = '<option value="">Max Price</option>';
    steps.forEach(function(s) {
      var f = s >= 1000000
        ? (s/1000000).toFixed(s%1000000===0?0:2)+'M'
        : (s/1000)+'K';
      $pMin.add(new Option('>='+f+' EUR', s));
      $pMax.add(new Option('<='+f+' EUR', s));
    });
  }

  function loadFilters() {
    if (PRELOAD_FILTERS) { initFilters(PRELOAD_FILTERS); return; }
    fetch(FILT).then(function(r){ return r.json(); }).then(initFilters)
      .catch(function(e){ console.warn('[TSR] filters error', e); });
  }

  function loadPage(callback) {
    if (loading) return;
    var isFirst = (
      page===1 && !filters.search && !filters.town && !filters.type &&
      !filters.beds && !filters.priceMin && !filters.priceMax
    );
    if (isFirst && PRELOAD_DATA) {
      applyData(PRELOAD_DATA);
      if (typeof callback === 'function') callback();
      return;
    }
    loading = true;
    var p = new URLSearchParams({ page:page, limit:LIMIT });
    if (filters.search)   p.set('search',   filters.search);
    if (filters.town)     p.set('town',      filters.town);
    if (filters.type)     p.set('type',      filters.type);
    if (filters.beds)     p.set('bedrooms',  filters.beds);
    if (filters.priceMin) p.set('priceMin',  filters.priceMin);
    if (filters.priceMax) p.set('priceMax',  filters.priceMax);
    fetch(API + '?' + p.toString())
      .then(function(r){ return r.json(); })
      .then(function(data) {
        applyData(data); loading = false;
        if (typeof callback === 'function') callback();
      })
      .catch(function() {
        $grid.innerHTML = '<div class="tsr-empty"><div class="tsr-empty-icon">:(</div>'
          + '<h3>Connection Error</h3><p>Could not reach the property server.</p></div>';
        $count.textContent = ''; loading = false;
      });
  }

  function applyData(data) {
    total      = data.total      || 0;
    totalPages = data.totalPages || 1;
    propMap    = {};
    (data.properties || []).forEach(function(p){ propMap[p.id] = p; });
    renderCards(data.properties || []);
    renderPager();
    $count.textContent = total + ' propert' + (total===1?'y':'ies') + ' found';
  }

  function renderCards(list) {
    if (!list.length) {
      $grid.innerHTML = '<div class="tsr-empty"><div class="tsr-empty-icon">&#128518;</div>'
        + '<h3>No Properties Found</h3><p>Try adjusting your filters.</p></div>';
      return;
    }
    var h = '';
    list.forEach(function(p, i) {
      var imgs = getImages(p);
      var img  = imgs.length ? imgs[0].url : 'https://via.placeholder.com/600x400?text=No+Image';
      var npic = imgs.length;
      var badge;
      if (p.new_build)                badge = '<span class="tsr-card-badge new">New Build</span>';
      else if (p.price_freq==='month') badge = '<span class="tsr-card-badge rent">For Rent</span>';
      else                             badge = '<span class="tsr-card-badge">For Sale</span>';
      var price = p.price_freq==='month'
        ? '&euro;'+Number(p.price).toLocaleString('en')+' <small>/mo</small>'
        : '&euro;'+Number(p.price).toLocaleString('en');
      h += '<div class="tsr-card" style="animation-delay:'+(i*.05)+'s" onclick="tsrModal(\''+p.id+'\''+')">'
        + '<div class="tsr-card-img"><img src="'+img+'" alt="'+(p.town||'')+'" loading="lazy">'
        + badge
        + (npic>1?'<div class="tsr-card-photo-count">'+ICO.cam+' '+npic+'</div>':'')
        + '</div><div class="tsr-card-body">'
        + '<div class="tsr-card-location">'+(p.province||p.country||'')+'</div>'
        + '<div class="tsr-card-title">'+(p.type||'Property')+' &middot; '+(p.town||'Spain')+'</div>'
        + '<div class="tsr-card-specs">'
        + (p.beds  ?'<span class="tsr-card-spec">'+ICO.bed +' '+p.beds +' bed' +(p.beds >1?'s':'')+'</span>':'')
        + (p.baths ?'<span class="tsr-card-spec">'+ICO.bath+' '+p.baths+' bath'+(p.baths>1?'s':'')+'</span>':'')
        + (p.surface_area&&p.surface_area.built?'<span class="tsr-card-spec">'+ICO.area+' '+p.surface_area.built+' m&sup2;</span>':'')
        + (p.pool  ?'<span class="tsr-card-spec">'+ICO.pool+' Pool</span>':'')
        + '</div><div class="tsr-card-bottom">'
        + '<div class="tsr-card-price">'+price+'</div>'
        + '<button class="tsr-card-btn">View &rarr;</button>'
        + '</div></div></div>';
    });
    $grid.innerHTML = h;
  }

  function renderPager() {
    if (totalPages<=1){ $pager.innerHTML=''; return; }
    var SL='<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6"/></svg>';
    var SR='<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>';
    var h='<button class="tsr-page-btn"'+(page===1?'disabled':'onclick="tsrPage('+(page-1)+')"')+'>'
      +SL+' Prev</button>';
    pageNums(page,totalPages).forEach(function(n){
      if(n==='...') h+='<span class="tsr-page-dots">&hellip;</span>';
      else h+='<button class="tsr-page-btn'+(n===page?' active':'')+'"'
        +(n===page?'':' onclick="tsrPage('+n+')"')+'>'+n+'</button>';
    });
    h+='<button class="tsr-page-btn"'+(page===totalPages?'disabled':'onclick="tsrPage('+(page+1)+')"')
      +'>Next '+SR+'</button>';
    $pager.innerHTML=h;
  }

  function pageNums(cur,tot){
    if(tot<=7){var a=[];for(var i=1;i<=tot;i++)a.push(i);return a;}
    if(cur<=4)   return[1,2,3,4,5,'...',tot];
    if(cur>=tot-3)return[1,'...',tot-4,tot-3,tot-2,tot-1,tot];
    return[1,'...',cur-1,cur,cur+1,'...',tot];
  }

  window.tsrPage=function(n){
    if(n<1||n>totalPages||n===page)return;
    page=n; showSkeletons();
    document.getElementById('tsr-app').scrollIntoView({behavior:'smooth',block:'start'});
    loadPage();
  };

  window.tsrModal=function(id){
    var p=propMap[id]; if(!p)return;
    history.replaceState(null,'',location.href.split('?')[0]+'?prop='+id);
    var imgs=getImages(p); galImgs=imgs; galIdx=0;
    var gi=''; imgs.forEach(function(img){ gi+='<img src="'+img.url+'" alt="property" loading="lazy">'; });
    $track.innerHTML=gi; $track.style.transform='translateX(0)';
    $gcnt.textContent='1 / '+(imgs.length||1);
    var desc=getDesc(p); var feats=p.features||[];
    var priceHtml=p.price_freq==='month'
      ?'&euro;'+Number(p.price).toLocaleString('en')+'<span class="tsr-modal-freq"> / month</span>'
      :'&euro;'+Number(p.price).toLocaleString('en');
    var specs='';
    if(p.beds)  specs+=specCard(ICO.ibed,'Beds',p.beds);
    if(p.baths) specs+=specCard(ICO.ibth,'Baths',p.baths);
    if(p.surface_area&&p.surface_area.built) specs+=specCard(ICO.iarea,'Built',p.surface_area.built+' m&sup2;');
    if(p.surface_area&&p.surface_area.plot)  specs+=specCard(ICO.iplot,'Plot', p.surface_area.plot +' m&sup2;');
    specs+=specCard(p.pool?ICO.ipool:ICO.inop,'Pool',p.pool?'Yes':'No');
    if(p.new_build) specs+=specCard(ICO.inew,'Build','New');
    if(p.energy_rating&&p.energy_rating.consumption&&p.energy_rating.consumption!=='X')
      specs+=specCard(ICO.ienrg,'Energy',p.energy_rating.consumption);
    var urlEn=getUrlEn(p); var actions='';
    if(urlEn) actions+='<button class="tsr-btn-primary" onclick="window.open(\''+urlEn+'\',\'_blank\')">'+ICO.ext+' Full Listing</button>';
    actions+='<button class="tsr-btn-secondary" onclick="tsrShare(\''+p.id+'\',\''+esc(p.town)+' '+esc(p.type)+'\')">'+ICO.shr+' Share</button>';
    if(p.email) actions+='<button class="tsr-btn-secondary" onclick="location.href=\'mailto:'+p.email+'?subject=Property '+esc(p.ref)+'\'">'+ICO.mail+' Contact</button>';
    $mBody.innerHTML=
      '<div class="tsr-modal-top"><div>'
      +'<div class="tsr-modal-loc">'+[p.town,p.province,p.country].filter(Boolean).join(' &middot; ')+'</div>'
      +'<div class="tsr-modal-title">'+(p.type||'Property')+(p.ref?' &middot; '+p.ref:'')+'</div>'
      +'</div><div class="tsr-modal-price-box"><div class="tsr-modal-price">'+priceHtml+'</div></div></div>'
      +'<div class="tsr-modal-specs">'+specs+'</div>'
      +(feats.length?'<div class="tsr-modal-section"><h4>Features &amp; Amenities</h4><div class="tsr-features">'
        +feats.map(function(f){return'<span class="tsr-feature-tag">'+f+'</span>';}).join('')
        +'</div></div>':'')
      +'<div class="tsr-modal-section"><h4>Description</h4><div class="tsr-modal-desc">'+desc+'</div></div>'
      +'<div class="tsr-modal-actions">'+actions+'</div>';
    $overlay.classList.add('open');
    document.body.style.overflow='hidden';
  };

  function specCard(icon,label,value){
    return'<div class="tsr-modal-spec-card"><div class="icon">'+icon+'</div>'
      +'<div class="label">'+label+'</div><div class="value">'+value+'</div></div>';
  }
  function esc(s){return s?String(s).replace(/'/g,'').replace(/"/g,''):''; }

  $prev.onclick=function(){
    if(galIdx>0){galIdx--;$track.style.transform='translateX(-'+(galIdx*100)+'%)';$gcnt.textContent=(galIdx+1)+' / '+galImgs.length;}
  };
  $next.onclick=function(){
    if(galIdx<galImgs.length-1){galIdx++;$track.style.transform='translateX(-'+(galIdx*100)+'%)';$gcnt.textContent=(galIdx+1)+' / '+galImgs.length;}
  };

  function closeModal(){
    $overlay.classList.remove('open');
    document.body.style.overflow='';
    history.replaceState(null,'',location.href.split('?')[0]);
  }
  $mClose.onclick=closeModal;
  $overlay.onclick=function(e){if(e.target===$overlay)closeModal();};
  document.addEventListener('keydown',function(e){if(e.key==='Escape')closeModal();});

  window.tsrShare=function(id,name){
    var url=location.href.split('?')[0]+'?prop='+id;
    if(navigator.share){navigator.share({title:name,url:url}).catch(function(){});}
    else if(navigator.clipboard){navigator.clipboard.writeText(url).then(function(){alert('Link copied!');});}
    else{prompt('Copy this link:',url);}
  };

  function go(){page=1;showSkeletons();loadPage();}
  var deb;
  $search.oninput=function(){clearTimeout(deb);deb=setTimeout(function(){filters.search=$search.value.trim();go();},400);};
  $town.onchange =function(){filters.town    =$town.value; go();};
  $type.onchange =function(){filters.type    =$type.value; go();};
  $beds.onchange =function(){filters.beds    =$beds.value; go();};
  $pMin.onchange =function(){filters.priceMin=$pMin.value; go();};
  $pMax.onchange =function(){filters.priceMax=$pMax.value; go();};
  $reset.onclick =function(){
    filters={search:'',town:'',type:'',beds:'',priceMin:'',priceMax:''};
    $search.value='';
    [$town,$type,$beds,$pMin,$pMax].forEach(function(s){s.value='';});
    go();
  };

  loadFilters();
  var urlParams   =new URLSearchParams(window.location.search);
  var propFromUrl =urlParams.get('prop');
  if(PRELOAD_DATA){
    applyData(PRELOAD_DATA);
    if(propFromUrl){
      if(propMap[propFromUrl]){tsrModal(propFromUrl);}
      else{
        fetch(API+'/'+propFromUrl).then(function(r){return r.json();})
          .then(function(data){var prop=data.property||data;if(prop&&prop.id){propMap[prop.id]=prop;tsrModal(prop.id);}})
          .catch(function(){});
      }
    }
  }else{
    showSkeletons();
    loadPage(function(){
      if(!propFromUrl)return;
      if(propMap[propFromUrl]){tsrModal(propFromUrl);return;}
      fetch(API+'/'+propFromUrl).then(function(r){return r.json();})
        .then(function(data){var prop=data.property||data;if(prop&&prop.id){propMap[prop.id]=prop;tsrModal(prop.id);}})
        .catch(function(){});
    });
  }
})();
</script>

    <?php
    return ob_get_clean();
}

add_shortcode('tesoro_resales', 'tesoro_resales_shortcode');