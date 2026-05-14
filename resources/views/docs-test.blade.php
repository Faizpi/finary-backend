<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Finary API - Interactive Docs</title>
    <link
      rel="stylesheet"
      href="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui.css"
    />
    <style>
      @import url("https://fonts.googleapis.com/css2?family=Nunito:wght@500;700;800;900&display=swap");

      :root {
        --green: #58cc02;
        --green-dark: #46a302;
        --blue: #1cb0f6;
        --yellow: #ffc800;
        --red: #ff4b4b;
        --ink: #3c3c3c;
        --muted: #777777;
        --line: #e5e5e5;
        --shadow: #d7d7d7;
        --paper: #ffffff;
        --wash: #f7f7f7;
      }

      * {
        box-sizing: border-box;
      }

      body {
        margin: 0;
        background:
          linear-gradient(180deg, #f1ffe8 0, rgba(241, 255, 232, 0) 260px),
          var(--wash);
        color: var(--ink);
        font-family: "Nunito", "Segoe UI", sans-serif;
      }

      .topbar {
        display: none;
      }

      .swagger-ui {
        max-width: 1180px;
        margin: 0 auto;
        padding: 28px 18px 56px;
        color: var(--ink);
        font-family: "Nunito", "Segoe UI", sans-serif;
      }

      .swagger-ui .wrapper {
        max-width: 1180px;
        padding: 0;
      }

      .swagger-ui .info {
        margin: 0 0 20px;
        padding: 24px;
        background: var(--paper);
        border: 2px solid var(--line);
        border-bottom: 5px solid var(--shadow);
        border-radius: 8px;
      }

      .swagger-ui .info .title {
        color: var(--ink);
        font-family: "Nunito", "Segoe UI", sans-serif;
        font-size: 34px;
        font-weight: 900;
        letter-spacing: 0;
      }

      .swagger-ui .info p,
      .swagger-ui .info li,
      .swagger-ui .opblock-description-wrapper p {
        color: var(--muted);
        font-family: "Nunito", "Segoe UI", sans-serif;
      }

      .swagger-ui .scheme-container,
      .swagger-ui .models {
        margin: 0 0 20px;
        padding: 18px 20px;
        background: var(--paper);
        border: 2px solid var(--line);
        border-bottom: 5px solid var(--shadow);
        border-radius: 8px;
        box-shadow: none;
      }

      .swagger-ui .scheme-container {
        border-bottom-color: #d7f3ff;
      }

      .swagger-ui .opblock-tag {
        margin: 24px 0 12px;
        padding: 0 0 12px;
        color: var(--ink);
        border-bottom: 2px solid var(--line);
        font-family: "Nunito", "Segoe UI", sans-serif;
        font-size: 20px;
        font-weight: 900;
        letter-spacing: 0;
      }

      .swagger-ui .opblock {
        overflow: hidden;
        border: 2px solid var(--line);
        border-bottom-width: 5px;
        border-radius: 8px;
        box-shadow: none;
      }

      .swagger-ui .opblock .opblock-summary-method {
        min-width: 76px;
        border-radius: 8px;
        font-family: "Nunito", "Segoe UI", sans-serif;
        font-weight: 900;
        text-shadow: none;
      }

      .swagger-ui .opblock .opblock-summary-path,
      .swagger-ui .opblock .opblock-summary-description {
        color: var(--ink);
        font-family: "Nunito", "Segoe UI", sans-serif;
        font-weight: 800;
      }

      .swagger-ui .opblock.opblock-get {
        background: #f1fbff;
        border-color: #84d8ff;
      }

      .swagger-ui .opblock.opblock-get .opblock-summary-method {
        background: var(--blue);
      }

      .swagger-ui .opblock.opblock-post {
        background: #f5ffef;
        border-color: #a7e66b;
      }

      .swagger-ui .opblock.opblock-post .opblock-summary-method {
        background: var(--green);
      }

      .swagger-ui .opblock.opblock-put {
        background: #fff9e8;
        border-color: #ffe082;
      }

      .swagger-ui .opblock.opblock-put .opblock-summary-method {
        background: var(--yellow);
        color: #5f4300;
      }

      .swagger-ui .opblock.opblock-delete {
        background: #fff4f4;
        border-color: #ffb0b0;
      }

      .swagger-ui .opblock.opblock-delete .opblock-summary-method {
        background: var(--red);
      }

      .swagger-ui table,
      .swagger-ui .responses-inner,
      .swagger-ui .opblock-body {
        font-family: "Nunito", "Segoe UI", sans-serif;
      }

      .swagger-ui input[type="text"],
      .swagger-ui textarea,
      .swagger-ui select {
        border: 2px solid var(--line);
        border-bottom: 4px solid var(--shadow);
        border-radius: 8px;
        color: var(--ink);
        font-family: "Nunito", "Segoe UI", sans-serif;
        font-weight: 700;
        box-shadow: none;
      }

      .swagger-ui .btn,
      .swagger-ui .btn.authorize,
      .swagger-ui .btn.execute,
      .swagger-ui .btn.try-out__btn {
        border: 0;
        border-bottom: 4px solid var(--green-dark);
        border-radius: 12px;
        background: #58cc02;
        color: #ffffff;
        font-family: "Nunito", "Segoe UI", sans-serif;
        font-weight: 900;
        letter-spacing: 0;
        text-transform: uppercase;
        box-shadow: none;
      }

      .swagger-ui .btn:hover,
      .swagger-ui .btn.authorize:hover,
      .swagger-ui .btn.execute:hover,
      .swagger-ui .btn.try-out__btn:hover {
        background: #46a302;
        color: #ffffff;
        transform: translateY(1px);
      }

      .swagger-ui .btn.cancel {
        background: var(--red);
        border-bottom-color: #d63b3b;
      }

      .swagger-ui .parameter__name,
      .swagger-ui .response-col_status,
      .swagger-ui .tab li {
        color: var(--ink);
        font-family: "Nunito", "Segoe UI", sans-serif;
        font-weight: 800;
      }

      .swagger-ui .model-box,
      .swagger-ui section.models {
        border-radius: 8px;
      }

      @media (max-width: 760px) {
        .swagger-ui {
          padding: 18px 12px 40px;
        }

        .swagger-ui .info {
          padding: 18px;
        }

        .swagger-ui .info .title {
          font-size: 28px;
        }

        .swagger-ui .opblock .opblock-summary {
          align-items: flex-start;
          gap: 8px;
        }
      }
    </style>
  </head>
  <body>
    <div id="swagger-ui"></div>
    <script src="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui-bundle.js"></script>
    <script src="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui-standalone-preset.js"></script>
    <script>
      window.onload = () => {
        window.ui = SwaggerUIBundle({
          url: "/docs/openapi.json",
          dom_id: "#swagger-ui",
          deepLinking: true,
          presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
          layout: "StandaloneLayout",
          tryItOutEnabled: true,
          requestInterceptor: (request) => {
            request.headers.Accept = "application/json";
            return request;
          },
        });
      };
    </script>
  </body>
</html>
