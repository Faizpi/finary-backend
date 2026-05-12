<!doctype html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Finary API - Interactive Docs</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui.css" />
    <style>
        body {
            margin: 0;
            background: #f7fbff;
            font-family: "Segoe UI", sans-serif;
        }

        .topbar {
            display: none;
        }

        .swagger-ui .info {
            margin: 20px 0 0;
        }

        .swagger-ui .info .title {
            font-size: 28px;
        }

        .swagger-ui .scheme-container {
            background: #ffffff;
            box-shadow: none;
            border-bottom: 1px solid #e6eef5;
        }

        .swagger-ui .btn.authorize {
            background: #58cc02;
            border-color: #58cc02;
        }

        .swagger-ui .btn.authorize:hover {
            background: #46a302;
            border-color: #46a302;
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
            });
        };
    </script>
</body>

</html>