@php
    $brand = config('landing.brand');
    $legalName = config('landing.legal_name');
    $region = config('landing.region');
    $docsUrl = config('landing.docs_url');
    $statusUrl = config('landing.status_url');
    $termsUrl = config('landing.terms_url');
    $privacyUrl = config('landing.privacy_url');
    $supportEmail = config('landing.support_email');

    // The headline plan is whatever the operator sells cheapest, so the number on the hero and the
    // number Stripe charges are the same number.
    $entryPlan = collect($plans)->first();
    $featured = count($plans) > 1 ? array_key_last($plans) : null;
@endphp

<x-layout-public :title="$brand">
    <a class="skip-link" href="#main">Skip to content</a>

    @php
        $signUpHref = $registrationOpen ? url('/register') : url('/login');
        $signUpLabel = $registrationOpen ? 'Create an account' : 'Sign in';
    @endphp

    <header class="masthead" id="masthead">
        <div class="wrap">
            <a href="{{ url('/') }}" class="mark" aria-label="{{ $brand }} home">
                <x-landing.mark />
                <span>{{ $brand }}</span>
            </a>

            <nav aria-label="Sections">
                <a href="#machines">Machines</a>
                <a href="#platform">Platform</a>
                <a href="#agents">Agents</a>
                @if (count($plans))
                    <a href="#pricing">Pricing</a>
                @endif
                @if ($docsUrl)
                    <a href="{{ $docsUrl }}" rel="noopener">Docs</a>
                @endif
            </nav>

            <div class="actions">
                <a class="btn btn-ghost" href="{{ url('/login') }}">Sign in</a>
                <a class="btn btn-primary" href="{{ $signUpHref }}">{{ $signUpLabel }}</a>
            </div>
        </div>
    </header>

    <main id="main">

        {{-- ------------------------------------------------------------------ hero --}}
        <section class="hero">
            <div class="wrap">
                @if ($entryPlan && filled($entryPlan['price_label'] ?? null))
                    <p class="eyebrow">
                        <b>New</b>
                        <span>Machines from {{ $entryPlan['price_label'] }} a month{{ $region ? ', in ' . $region : '' }}</span>
                    </p>
                @endif

                <h1>{{ config('landing.headline') }}</h1>

                <p class="lede">{{ config('landing.subhead') }}</p>

                <div class="cta">
                    <a class="btn btn-primary btn-lg" href="{{ $signUpHref }}">{{ $signUpLabel }}</a>
                    <a class="btn btn-quiet btn-lg" href="#platform">See what it runs</a>
                </div>

                <p class="reassure">
                    @if ($registrationOpen)
                        No sales call. Pick a plan, connect a repository, deploy.
                    @else
                        Accounts are opened by invitation while we are in preview.
                    @endif
                </p>

                {{-- A hosting company is judged on what the work feels like, so show the work. --}}
                <div class="console" aria-hidden="true">
                    <div class="console-bar">
                        <i></i><i></i><i></i>
                        <span>deploy — main@a41c9f2</span>
                    </div>
<pre><span class="dim">$</span> <b>git push origin main</b>
<span class="dim">→</span> webhook received, build queued
<span class="dim">→</span> cloning repository, 1.2s
<span class="dim">→</span> building image <span class="acc">app:a41c9f2</span>
<span class="dim">→</span> health check passing on :3000
<span class="dim">→</span> certificate issued for <span class="acc">app.example.com</span>
<span class="ok">✔</span> <b>deployed in 47s</b> <span class="dim">— previous release kept for rollback</span></pre>
                </div>
            </div>
        </section>

        {{-- ------------------------------------------------------------------ machines --}}
        @if ($entryPlan && (filled($entryPlan['machine'] ?? null) || filled($entryPlan['price_label'] ?? null)))
            <section id="machines">
                <div class="wrap">
                    <div class="section-head">
                        <p class="kicker">Machines</p>
                        <h2>A real machine, not a slice of a queue.</h2>
                        <p>
                            Your plan comes with compute you can point at: a machine with its own
                            resources, its own address and root you control.
                            @if ($region)
                                It runs in {{ $region }}.
                            @endif
                            Run one application on it or twenty, and when it is busy it is busy for you.
                        </p>
                    </div>

                    <div class="machine">
                        <div class="machine-card">
                            <div class="machine-price">
                                <strong>{{ $entryPlan['price_label'] }}</strong>
                                <span>per month, billed monthly</span>
                            </div>

                            @if (filled($entryPlan['machine'] ?? null))
                                <dl class="spec">
                                    @foreach (explode('·', $entryPlan['machine']) as $index => $fact)
                                        @php $fact = trim($fact); @endphp
                                        @if ($fact !== '')
                                            <div>
                                                <dt>{{ ['Compute', 'Memory', 'Storage', 'Transfer'][$index] ?? 'Included' }}</dt>
                                                <dd>{{ $fact }}</dd>
                                            </div>
                                        @endif
                                    @endforeach
                                </dl>
                            @endif

                            <p style="margin-top:1.25rem;font-size:0.875rem;color:var(--ink-dim)">
                                Cancel whenever you like. Billing stops at the end of the period you
                                already paid for, and nothing is deleted before you say so.
                            </p>
                        </div>

                        <div class="machine-card">
                            <h3 style="font-size:1.05rem">What you get with it</h3>
                            <ul style="margin:1.1rem 0 0;padding:0;list-style:none;display:grid;gap:0.75rem;font-size:0.9rem;color:var(--ink-dim)">
                                <li><x-landing.tick /> The full platform, not a limited tier of it</li>
                                <li><x-landing.tick /> Unlimited applications, databases and services on the machine</li>
                                <li><x-landing.tick /> HTTPS certificates issued and renewed for every domain</li>
                                <li><x-landing.tick /> Scheduled backups of databases and volumes</li>
                                <li><x-landing.tick /> A terminal into any container, from the browser</li>
                                <li><x-landing.tick /> The API and the agent endpoint on the same account</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </section>

            <hr class="rule">
        @endif

        {{-- ------------------------------------------------------------------ platform --}}
        <section id="platform">
            <div class="wrap">
                <div class="section-head">
                    <p class="kicker">The platform</p>
                    <h2>Everything a deployment needs, already wired together.</h2>
                    <p>
                        Applications, the databases behind them and the services around them live on one
                        account, on machines you can see. No separate add-on for the part that matters.
                    </p>
                </div>

                <div class="grid grid-3">
                    <article class="card">
                        <span class="ico"><x-landing.icon name="git" /></span>
                        <h3>Deploy from git</h3>
                        <p>
                            Connect GitHub or GitLab, or hand any git remote a deploy key. A push builds
                            and releases; a pull request gets its own preview environment.
                        </p>
                    </article>

                    <article class="card">
                        <span class="ico"><x-landing.icon name="database" /></span>
                        <h3>Eight database engines</h3>
                        <p>
                            PostgreSQL, MySQL, MariaDB, MongoDB, Redis, KeyDB, Dragonfly and ClickHouse,
                            provisioned on your machine with credentials only you hold.
                        </p>
                    </article>

                    <article class="card">
                        <span class="ico"><x-landing.icon name="stack" /></span>
                        <h3>Hundreds of services</h3>
                        <p>
                            A catalogue of ready compose templates for the things you would otherwise
                            spend an afternoon assembling, from analytics to queues to object storage.
                        </p>
                    </article>

                    <article class="card">
                        <span class="ico"><x-landing.icon name="lock" /></span>
                        <h3>HTTPS without the chore</h3>
                        <p>
                            Point a domain at your machine and the proxy requests the certificate,
                            renews it and routes to the right container.
                        </p>
                    </article>

                    <article class="card">
                        <span class="ico"><x-landing.icon name="shield" /></span>
                        <h3>Backups that are tested</h3>
                        <p>
                            Schedule database and volume backups, keep them locally or push them to S3
                            storage you own, and see every execution.
                        </p>
                    </article>

                    <article class="card">
                        <span class="ico"><x-landing.icon name="terminal" /></span>
                        <h3>A way in when it breaks</h3>
                        <p>
                            Live logs, deployment history, rollback to the previous release, and a
                            terminal into the running container from the browser.
                        </p>
                    </article>
                </div>
            </div>
        </section>

        <hr class="rule">

        {{-- ------------------------------------------------------------------ steps --}}
        <section>
            <div class="wrap">
                <div class="section-head">
                    <p class="kicker">Getting started</p>
                    <h2>Three steps, and the first one is free.</h2>
                </div>

                <div class="steps">
                    <div class="step">
                        <h3>Open an account</h3>
                        <p>
                            An email address and a password. You can look around the platform before you
                            put a card in.
                        </p>
                    </div>
                    <div class="step">
                        <h3>Take a machine</h3>
                        <p>
                            Choose a plan and your machine is attached to the account, ready for the
                            platform to deploy onto.
                        </p>
                    </div>
                    <div class="step">
                        <h3>Ship</h3>
                        <p>
                            Connect a repository, set the domain, and push. The build, the certificate
                            and the routing are handled.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        {{-- ------------------------------------------------------------------ agents --}}
        <section class="agents" id="agents">
            <div class="wrap agents-inner">
                <div>
                    <p class="kicker">For agents and orchestrators</p>
                    <h2 style="font-size:clamp(1.6rem,3.6vw,2.35rem)">
                        Infrastructure your software can operate on its own.
                    </h2>
                    <p style="margin-top:0.875rem;color:var(--ink-dim)">
                        Everything a person can do in the interface is reachable by a token. An agent can
                        hold an account, take a machine, deploy a service and read its logs without a
                        human in the loop — and without a scraped session or a headless browser.
                    </p>

                    <ul>
                        <li>
                            <x-landing.tick />
                            <span><b>A REST API over the whole platform.</b> Applications, databases,
                                services, servers, deployments and environment variables.</span>
                        </li>
                        <li>
                            <x-landing.tick />
                            <span><b>An MCP endpoint.</b> Point a tool-using model at it and the platform
                                becomes a set of tools, authenticated by API token and scoped to one team.</span>
                        </li>
                        <li>
                            <x-landing.tick />
                            <span><b>Scoped tokens.</b> Issue a token per agent, limit what it may touch,
                                revoke it without disturbing anything else.</span>
                        </li>
                        <li>
                            <x-landing.tick />
                            <span><b>Webhooks both ways.</b> Trigger a deployment from anything, and be
                                told when it finishes.</span>
                        </li>
                    </ul>
                </div>

                <div class="console" style="margin-top:0" aria-hidden="true">
                    <div class="console-bar">
                        <i></i><i></i><i></i>
                        <span>agent — deploy a service</span>
                    </div>
<pre><span class="dim">POST</span> <b>/api/v1/applications</b>
<span class="dim">Authorization:</span> Bearer <span class="acc">&lt;agent token&gt;</span>

{
  <span class="acc">"project_uuid"</span>: "…",
  <span class="acc">"git_repository"</span>: "…",
  <span class="acc">"domains"</span>: "https://api.example.com"
}

<span class="ok">201</span> <b>created</b> <span class="dim">— deployment queued</span></pre>
                </div>
            </div>
        </section>

        {{-- ------------------------------------------------------------------ pricing --}}
        @if (count($plans))
            <section id="pricing">
                <div class="wrap">
                    <div class="section-head">
                        <p class="kicker">Pricing</p>
                        <h2>One price. The whole platform.</h2>
                        <p>
                            Plans differ by how many machines the account may run, not by which features
                            you are allowed to have.
                        </p>
                    </div>

                    <div class="plans">
                        @foreach ($plans as $key => $plan)
                            <article class="plan" data-featured="{{ $key === $featured ? 'true' : 'false' }}">
                                @if ($key === $featured)
                                    <span class="plan-flag">For teams</span>
                                @endif

                                <h3>{{ $plan['name'] }}</h3>
                                <p class="tagline">{{ $plan['tagline'] }}</p>

                                <div class="amount">
                                    <strong>{{ $plan['price_label'] }}</strong>
                                    <span>/ month</span>
                                </div>

                                <ul>
                                    @if (($plan['servers'] ?? 0) > 0)
                                        <li>
                                            <x-landing.tick />
                                            <span>{{ $plan['servers'] }} {{ Str::plural('machine', $plan['servers']) }} on the account</span>
                                        </li>
                                    @endif
                                    @if (filled($plan['machine'] ?? null))
                                        <li><x-landing.tick /><span>{{ $plan['machine'] }}</span></li>
                                    @endif
                                    <li><x-landing.tick /><span>Unlimited applications, databases and services</span></li>
                                    <li><x-landing.tick /><span>Preview environments and rollbacks</span></li>
                                    <li><x-landing.tick /><span>Scheduled backups</span></li>
                                    <li><x-landing.tick /><span>API and MCP access</span></li>
                                    @if ($key === $featured)
                                        <li><x-landing.tick /><span>Team members with roles</span></li>
                                    @endif
                                </ul>

                                <a class="btn {{ $key === $featured ? 'btn-primary' : 'btn-quiet' }}"
                                    href="{{ $signUpHref }}">
                                    @if (! $registrationOpen)
                                        Sign in
                                    @elseif ($trialDays > 0)
                                        Start {{ $trialDays }}-day free trial
                                    @else
                                        Start on {{ $plan['name'] }}
                                    @endif
                                </a>
                            </article>
                        @endforeach
                    </div>

                    <p class="plan-note">
                        @if ($trialDays > 0)
                            Every plan starts with a {{ $trialDays }}-day free trial, once per account.
                        @endif
                        Pay by card{{ $credits ? ', or from Xlaunch gateway credits that an agent can top up in SUI' : '' }}.
                        Prices are per month and exclude any tax that applies where you are; the exact
                        total is shown before you pay. Change plan or cancel at any time from your account.
                    </p>
                </div>
            </section>
        @endif

        {{-- ------------------------------------------------------------------ faq --}}
        <section>
            <div class="wrap">
                <div class="section-head">
                    <p class="kicker">Questions</p>
                    <h2>The things worth knowing first.</h2>
                </div>

                <div class="faq">
                    <details>
                        <summary>What actually runs my code?</summary>
                        <p>
                            Docker, on the machine attached to your account. The platform builds an image
                            from your repository — your Dockerfile, your compose file, or a build pack it
                            detects — then runs it behind a proxy that handles domains and certificates.
                        </p>
                    </details>
                    <details>
                        <summary>Can I bring my own server instead?</summary>
                        <p>
                            Yes. Any machine you can reach over SSH can be connected to the account, and
                            the platform will deploy to it exactly as it does to ours.
                        </p>
                    </details>
                    <details>
                        <summary>What happens to my data if I leave?</summary>
                        <p>
                            It is Docker and standard databases, on a machine you have root on. Take a
                            backup, move the volumes, point the domains elsewhere. There is no format to
                            escape from.
                        </p>
                    </details>
                    <details>
                        <summary>Is there a free trial?</summary>
                        <p>
                            @if ($trialDays > 0)
                                Yes. Every plan starts with {{ $trialDays }} days free, once per account, on
                                the same terms for everyone. The first payment is taken when the trial ends,
                                and cancelling before then costs nothing.
                            @else
                                You can open an account and look at the whole interface without paying. A
                                machine starts when you choose a plan.
                            @endif
                        </p>
                    </details>
                    @if ($credits)
                        <details>
                            <summary>Can an agent pay for its own machine?</summary>
                            <p>
                                Yes. A plan can be paid from Xlaunch gateway credits instead of a card, and
                                credits can be topped up in SUI. The same balance pays for the agent's model
                                calls, so an agent can run and pay for itself without a person in the loop.
                            </p>
                        </details>
                    @endif
                    <details>
                        <summary>Can an agent hold the account?</summary>
                        <p>
                            Yes. Issue it a token and it can do what you can do through the API or the MCP
                            endpoint. Tokens are scoped to a team and can be revoked one at a time.
                        </p>
                    </details>
                </div>
            </div>
        </section>

        {{-- ------------------------------------------------------------------ closing --}}
        <section class="closing">
            <div class="wrap">
                <div class="panel">
                    <h2>Take a machine and push something.</h2>
                    <p>
                        The fastest way to judge a platform is to put something real on it. It takes a few
                        minutes.
                    </p>
                    <div class="cta">
                        <a class="btn btn-primary btn-lg" href="{{ $signUpHref }}">{{ $signUpLabel }}</a>
                        @if ($docsUrl)
                            <a class="btn btn-quiet btn-lg" href="{{ $docsUrl }}" rel="noopener">Read the docs</a>
                        @endif
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="site">
        <div class="wrap">
            <div class="row">
                <a href="{{ url('/') }}" class="mark" style="font-size:0.9rem">
                    <x-landing.mark />
                    <span>{{ $brand }}</span>
                </a>

                <nav aria-label="Footer">
                    <a href="{{ url('/login') }}">Sign in</a>
                    @if ($registrationOpen)
                        <a href="{{ url('/register') }}">Create an account</a>
                    @endif
                    @if ($docsUrl)
                        <a href="{{ $docsUrl }}" rel="noopener">Docs</a>
                    @endif
                    @if ($statusUrl)
                        <a href="{{ $statusUrl }}" rel="noopener">Status</a>
                    @endif
                    @if ($termsUrl)
                        <a href="{{ $termsUrl }}" rel="noopener">Terms</a>
                    @endif
                    @if ($privacyUrl)
                        <a href="{{ $privacyUrl }}" rel="noopener">Privacy</a>
                    @endif
                    @if ($supportEmail)
                        <a href="mailto:{{ $supportEmail }}">Contact</a>
                    @endif
                </nav>
            </div>

            <p class="legal">
                &copy; {{ now()->year }} {{ $legalName ?: $brand }}.
                @if ($region)
                    Machines operated in {{ $region }}.
                @endif
            </p>
        </div>
    </footer>

    <script>
        // The masthead gains its hairline only once the page has moved, so the first view is clean.
        (function () {
            var head = document.getElementById('masthead');
            var mark = function () {
                head.dataset.scrolled = window.scrollY > 8 ? 'true' : 'false';
            };
            mark();
            window.addEventListener('scroll', mark, { passive: true });
        })();
    </script>
</x-layout-public>
