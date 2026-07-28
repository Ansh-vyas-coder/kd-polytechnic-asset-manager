<?php
/**
 * 404.php — Lost at Sea error page
 * Drop this in as your 404 handler (e.g. via ErrorDocument or router fallback).
 * Change $dashboardUrl below to point wherever "Dashboard" should go.
 */

$dashboardUrl = 'dashboard.php';
$requestedPath = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/unknown', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>404 — Lost at Sea</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root{
    --sky-top: #eaf3ff;
    --sky-bot: #ffffff;
    --sea: #2563eb;
    --sea-deep: #1e40af;
    --hull: #1e3a5f;
    --line: #93c5fd;
  }
  body{
    background: linear-gradient(180deg, var(--sky-top) 0%, #ffffff 45%);
    font-family: 'Inter', sans-serif;
  }
  .mono{ font-family: 'JetBrains Mono', monospace; }

  /* the only motion on the page: a slow, gentle drift of the waves */
  .wave{
    animation: drift 8s ease-in-out infinite;
  }
  .wave-2{ animation-delay: -2.5s; }
  .wave-3{ animation-delay: -5s; }
  @keyframes drift{
    0%, 100%{ transform: translateX(0); }
    50%{ transform: translateX(-8px); }
  }

  @media (prefers-reduced-motion: reduce){
    .wave{ animation: none; }
  }
</style>
</head>
<body class="min-h-screen flex items-center justify-center px-4 sm:px-6 py-10 sm:py-16 text-slate-700">

  <div class="w-full max-w-4xl">

    <!-- Top status bar -->
    <div class="mono text-[10px] sm:text-[11px] tracking-widest text-slate-400 flex flex-wrap items-center justify-between gap-2 mb-6 sm:mb-8 uppercase">
      <span class="flex items-center gap-2">
        <span class="w-1.5 h-1.5 rounded-full bg-blue-600"></span>
        Ship's Log — Last Known Position Unclear
      </span>
      <span>Status: <span class="text-blue-600">Nothing on the Horizon</span></span>
    </div>

    <div class="grid md:grid-cols-2 gap-8 sm:gap-10 items-center">

      <!-- Illustration -->
      <div class="flex justify-center">
        <svg viewBox="0 0 400 320" class="w-full max-w-[260px] sm:max-w-xs md:max-w-sm" role="img" aria-label="A sailor on a ship looking through a telescope, finding nothing on the horizon">
          <defs>
            <linearGradient id="seaGrad" x1="0%" y1="0%" x2="0%" y2="100%">
              <stop offset="0%" stop-color="#3b82f6"/>
              <stop offset="100%" stop-color="#1e40af"/>
            </linearGradient>
          </defs>

          <!-- sky -->
          <rect x="0" y="0" width="400" height="200" fill="#eaf3ff"/>

          <!-- distant horizon marker: a tiny bottle with a 404 note, barely visible, far off -->
          <g opacity="0.55">
            <line x1="330" y1="150" x2="330" y2="162" stroke="#1e40af" stroke-width="1.5"/>
            <rect x="325" y="140" width="10" height="12" rx="1" fill="#bfdbfe" stroke="#1e40af" stroke-width="1"/>
            <text x="330" y="149" text-anchor="middle" class="mono" font-size="5" fill="#1e40af">404</text>
          </g>

          <!-- sea -->
          <rect x="0" y="200" width="400" height="120" fill="url(#seaGrad)"/>

          <!-- waves -->
          <g stroke="#bfdbfe" stroke-width="2" fill="none" opacity="0.6">
            <path class="wave wave-2" d="M-20,215 Q 10,208 40,215 T 100,215 T 160,215 T 220,215 T 280,215 T 340,215 T 400,215 T 460,215"/>
            <path class="wave wave-3" d="M-20,235 Q 10,228 40,235 T 100,235 T 160,235 T 220,235 T 280,235 T 340,235 T 400,235 T 460,235"/>
          </g>

          <!-- ship hull -->
          <path d="M70,255 Q200,278 330,255 L308,290 Q200,305 92,290 Z" fill="var(--hull)"/>
          <path d="M70,255 Q200,278 330,255" fill="none" stroke="#0f2544" stroke-width="2"/>
          <!-- deck -->
          <rect x="120" y="240" width="160" height="16" rx="2" fill="#2d4d73"/>

          <!-- mast -->
          <line x1="200" y1="240" x2="200" y2="140" stroke="#3b2f2f" stroke-width="4" stroke-linecap="round"/>
          <!-- flag -->
          <path d="M200,140 L232,148 L200,156 Z" fill="#2563eb"/>

          <!-- sailor: legs -->
          <path d="M158,240 L152,222 M170,240 L170,222" stroke="#1e3a5f" stroke-width="5" stroke-linecap="round"/>
          <!-- sailor: body -->
          <rect x="149" y="196" width="24" height="28" rx="6" fill="#2563eb"/>
          <!-- sailor: head -->
          <circle cx="161" cy="188" r="9" fill="#f4c9a3"/>
          <!-- sailor: cap -->
          <path d="M151,185 a10,8 0 0 1 20,0 Z" fill="#1e3a5f"/>
          <!-- sailor: back arm (brace on rail) -->
          <path d="M150,206 Q140,214 138,224" stroke="#2563eb" stroke-width="6" stroke-linecap="round" fill="none"/>
          <!-- sailor: front arm holding telescope -->
          <path d="M172,202 Q188,196 198,182" stroke="#2563eb" stroke-width="6" stroke-linecap="round" fill="none"/>

          <!-- telescope -->
          <g transform="translate(198,182) rotate(-32)">
            <rect x="0" y="-4" width="34" height="8" rx="2" fill="#0f2544"/>
            <rect x="26" y="-6" width="10" height="12" rx="1" fill="#334155"/>
          </g>
        </svg>
      </div>

      <!-- Copy -->
      <div class="text-center md:text-left">
        <div class="mono text-6xl sm:text-7xl md:text-8xl font-extrabold text-slate-900 tracking-tight leading-none">
          404
        </div>
        <h1 class="mt-4 text-xl sm:text-2xl md:text-3xl font-semibold text-slate-900">
          Lost at sea.
        </h1>
        <p class="mt-3 text-slate-500 leading-relaxed text-sm sm:text-base">
          Our lookout scanned the whole horizon and this page is nowhere to be found.
          It may have drifted, sunk, or never set sail at all.
        </p>

        <div class="mt-8 flex flex-col sm:flex-row gap-3 justify-center md:justify-start">
          <a href="<?php echo htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8'); ?>"
             class="inline-flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-medium px-6 py-3 rounded-lg transition-colors mono text-sm tracking-wide w-full sm:w-auto">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M3 12l2-2 5 5L21 4"/>
              <path d="M3 21h18"/>
              <path d="M3 21V9"/>
              <path d="M9 21v-6"/>
              <path d="M15 21v-9"/>
              <path d="M21 21V4"/>
            </svg>
            Return to Dashboard
          </a>
        </div>
      </div>
    </div>

    <!-- Bottom status bar -->
    <div class="mono text-[9px] sm:text-[10px] tracking-widest text-slate-400 flex flex-col sm:flex-row items-center justify-between gap-1 sm:gap-0 mt-10 sm:mt-12 pt-4 border-t border-blue-100 uppercase text-center sm:text-left">
      <span>Wind: calm</span>
      <span>Visibility: unlimited, nothing found</span>
      <span>Error code: HTTP 404</span>
    </div>

  </div>

</body>
</html>