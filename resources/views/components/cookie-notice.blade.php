{{--
    Cookie consent notice for the public marketing surface.
    Included at the end of components/layout-public.blade.php so every
    public page (landing, terms, privacy, security) carries it.

    Why inline script (not Vite): layout-public loads a single landing.css
    bundle and no app JS; the notice must work before/without any bundle and
    stay dependency-free. localStorage key gf_cookie_consent:
      "accepted" | "essential" — hide the banner.
      absent                   — show it.
    No tracking, analytics, or third-party cookies are set by this site
    today; the banner exists so the Laravel session/XSRF cookies are
    disclosed and future analytics ship behind a real consent gate.
    Styling reads the public palette via existing CSS custom properties
    with safe fallbacks.
--}}
<div id="gf-cookie-notice" role="dialog" aria-live="polite" aria-label="Cookie notice"
     style="position:fixed;left:16px;right:16px;bottom:16px;z-index:1000;display:none;max-width:640px;margin:0 auto;padding:14px 18px;border-radius:12px;
            background:rgba(11,11,14,.96);color:#f4f4f5;border:1px solid rgba(255,255,255,.12);
            box-shadow:0 8px 30px rgba(0,0,0,.45);font-size:14px;line-height:1.5;">
    <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;">
        <span style="flex:1 1 260px;">
            We use <strong>essential cookies</strong> to run this site (session &amp; security).
            No analytics or advertising cookies are set unless you accept them.
            See our <a href="{{ config('landing.privacy_url') ?: url('/privacy') }}"
               style="color:#93c5fd;text-decoration:underline;">privacy policy</a> and
            <a href="{{ config('landing.terms_url') ?: url('/terms') }}"
               style="color:#93c5fd;text-decoration:underline;">terms</a>.
        </span>
        <span style="display:flex;gap:8px;flex:0 0 auto;">
            <button type="button" id="gf-cookie-essential"
                    style="padding:8px 14px;border-radius:8px;border:1px solid rgba(255,255,255,.25);
                           background:transparent;color:#f4f4f5;cursor:pointer;font-size:13px;">
                Essential only
            </button>
            <button type="button" id="gf-cookie-accept"
                    style="padding:8px 14px;border-radius:8px;border:none;
                           background:#6b16ed;color:#fff;cursor:pointer;font-size:13px;font-weight:600;">
                Accept all
            </button>
        </span>
    </div>
</div>
<script data-navigate-once>
(function () {
    try {
        var KEY = 'gf_cookie_consent';
        var el = document.getElementById('gf-cookie-notice');
        if (!el) return;
        var stored = null;
        try { stored = localStorage.getItem(KEY); } catch (e) { /* private mode */ }
        if (stored === 'accepted' || stored === 'essential') return;
        el.style.display = 'block';
        function choose(value) {
            try { localStorage.setItem(KEY, value); } catch (e) { /* ignore */ }
            el.style.display = 'none';
        }
        document.getElementById('gf-cookie-accept')?.addEventListener('click', function () { choose('accepted'); });
        document.getElementById('gf-cookie-essential')?.addEventListener('click', function () { choose('essential'); });
    } catch (e) { /* never block the page */ }
})();
</script>
