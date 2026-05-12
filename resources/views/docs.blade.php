<!doctype html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Finary API Docs</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Baloo+2:wght@600;700&family=Nunito+Sans:wght@400;600;700&display=swap');

        :root {
            --green: #58cc02;
            --green-dark: #46a302;
            --blue: #1cb0f6;
            --yellow: #ffc800;
            --ink: #1e2a36;
            --muted: #5b6b7a;
            --card: #ffffff;
            --bg-top: #e9fff0;
            --bg-bottom: #d7f7ff;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Nunito Sans", "Segoe UI", sans-serif;
            color: var(--ink);
            background: radial-gradient(circle at top left, var(--bg-top), transparent 55%),
                radial-gradient(circle at bottom right, var(--bg-bottom), transparent 60%),
                #f7fbff;
            min-height: 100vh;
        }

        .page {
            max-width: 1080px;
            margin: 0 auto;
            padding: 48px 20px 64px;
        }

        .hero {
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            gap: 24px;
            align-items: center;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 999px;
            background: #eaffd8;
            color: var(--green-dark);
            font-weight: 700;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        h1 {
            font-family: "Baloo 2", "Nunito Sans", sans-serif;
            font-size: 44px;
            margin: 12px 0 12px;
        }

        .subtitle {
            font-size: 18px;
            color: var(--muted);
            margin: 0 0 24px;
        }

        .cta {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 14px 22px;
            border-radius: 14px;
            background: var(--green);
            color: #fff;
            text-decoration: none;
            font-weight: 700;
            box-shadow: 0 12px 24px rgba(88, 204, 2, 0.25);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .cta:hover {
            transform: translateY(-2px);
            box-shadow: 0 16px 30px rgba(88, 204, 2, 0.35);
        }

        .hero-card {
            background: var(--card);
            border-radius: 20px;
            padding: 20px;
            border: 2px solid #eef3f7;
            box-shadow: 0 10px 24px rgba(30, 42, 54, 0.08);
        }

        .hero-card h3 {
            margin: 0 0 8px;
            font-size: 18px;
        }

        .hero-card code {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 10px;
            background: #f1f8ff;
            color: #0c4a6e;
            font-weight: 700;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 18px;
            margin-top: 28px;
        }

        .card {
            background: var(--card);
            border-radius: 18px;
            padding: 18px 20px;
            border: 2px solid #eef3f7;
        }

        .card h3 {
            margin: 0 0 12px;
            font-size: 18px;
        }

        .list {
            margin: 0;
            padding-left: 18px;
            color: var(--muted);
            line-height: 1.6;
        }

        .pill {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            background: #eef6ff;
            color: #0f3b5f;
            font-weight: 700;
            font-size: 12px;
        }

        .footer {
            margin-top: 36px;
            font-size: 14px;
            color: var(--muted);
        }

        @media (max-width: 900px) {
            .hero {
                grid-template-columns: 1fr;
            }

            h1 {
                font-size: 36px;
            }
        }
    </style>
</head>

<body>
    <div class="page">
        <div class="hero">
            <div>
                <div class="badge">Finary API</div>
                <h1>Simple, friendly API docs</h1>
                <p class="subtitle">
                    Everything you need to integrate Finary. Clean endpoints, clear auth, and a single download button.
                </p>
                <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                    <a class="cta" href="/docs/download">Download API Docs (Markdown)</a>
                    <a class="cta" style="background: var(--blue); box-shadow: 0 12px 24px rgba(28, 176, 246, 0.25);"
                        href="/">
                        Try API (Interactive)
                    </a>
                </div>
            </div>
            <div class="hero-card">
                <h3>Base URL</h3>
                <code>https://api-finary.my.id/api</code>
                <p class="subtitle" style="margin-top: 12px;">
                    All endpoints below are prefixed with <span class="pill">/api</span>.
                </p>
            </div>
        </div>

        <div class="grid">
            <div class="card">
                <h3>Quick Start</h3>
                <ul class="list">
                    <li>Get token via <span class="pill">POST /auth/login</span>.</li>
                    <li>Use header: <span class="pill">Authorization: Bearer &lt;token&gt;</span>.</li>
                    <li>Health check: <span class="pill">GET /health</span>.</li>
                </ul>
            </div>
            <div class="card">
                <h3>Auth</h3>
                <ul class="list">
                    <li>POST /auth/register</li>
                    <li>POST /auth/login</li>
                    <li>GET /auth/me</li>
                    <li>POST /auth/logout</li>
                </ul>
            </div>
            <div class="card">
                <h3>Core Endpoints</h3>
                <ul class="list">
                    <li>GET /dashboard</li>
                    <li>GET /assessment/latest</li>
                    <li>GET /transactions</li>
                    <li>GET /budgets</li>
                </ul>
            </div>
            <div class="card">
                <h3>Insights & Community</h3>
                <ul class="list">
                    <li>GET /insights/profile</li>
                    <li>GET /insights/badges</li>
                    <li>GET /insights/leaderboard</li>
                    <li>GET /forum/posts</li>
                </ul>
            </div>
            <div class="card">
                <h3>Recommendations</h3>
                <ul class="list">
                    <li>POST /recommendations/side-hustles</li>
                </ul>
            </div>
            <div class="card">
                <h3>Reports</h3>
                <ul class="list">
                    <li>GET /reports/transactions/export</li>
                </ul>
            </div>
        </div>

        <div class="footer">
            Full details live in the downloadable Markdown file. Keep this page lightweight and focused.
        </div>
    </div>
</body>

</html>