# Finary API Documentation

Base URL:

- Production: https://api-finary.my.id/api
- Same-origin dari halaman docs: /api

Authentication:

- Gunakan Bearer token dari endpoint login/register.
- Header: Authorization: Bearer <token>

## Health

### GET /health

Mengecek status API.

## Authentication

### POST /auth/register

Body:

- name (string, required)
- email (string, required)
- password (string, min 8, required)
- password_confirmation (string, required)

### POST /auth/login

Body:

- email (string, required)
- password (string, required)

### GET /auth/me

Butuh token.

### POST /auth/logout

Butuh token.

## Assessment

### GET /assessment/latest

Butuh token.

### POST /assessment

Butuh token.

Body:

- monthly_income (number)
- monthly_expense (number)
- actual_savings (number)
- budget_goal (number)
- emergency_fund (number)
- loan_payment (number, optional)

Response penting:

- data.classification: survival | stable | growth
- data.metadata.classification_result.source: ml | rule-based
- data.metadata.classification_result.risk_flags
- data.metadata.classification_result.recommendation_focus

## Dashboard & Insight

### GET /dashboard

Butuh token.

### GET /insights/profile

Butuh token.

Response mencakup `prediction`, dihitung otomatis maksimal sekali per hari per user dari summary dashboard dan assessment terbaru:

- prediction.next_month_balance
- prediction.warning_probability
- prediction.warning_flag
- prediction.recommendations
- prediction.source: ml | rule-based
- prediction.generated_for: YYYY-MM-DD

### GET /insights/badges

Butuh token.

### GET /insights/leaderboard

Butuh token.

## Transactions

### GET /transactions

Butuh token.

Query optional:

- type: income | expense
- month: YYYY-MM

### POST /transactions

Body:

- type (income|expense)
- category (string)
- amount (number)
- transaction_date (date, format YYYY-MM-DD)
- note (string, optional)

### PUT /transactions/{id}

Body partial diperbolehkan.

### DELETE /transactions/{id}

## Budgets

### GET /budgets

Butuh token.

### POST /budgets

Body:

- category (string)
- period (YYYY-MM, optional, default bulan ini)
- monthly_limit (number)

### PUT /budgets/{id}

Body partial diperbolehkan.

### DELETE /budgets/{id}

## Side Hustle Recommendation

### POST /recommendations/side-hustles

Butuh token.

Body (semua optional, otomatis fallback ke assessment terbaru):

- experience_level (Beginner|Intermediate|Expert)
- interest_category (string)
- skills (array string)
- available_hours_per_week (integer)
- classification (survival|stable|growth)

Response:

- data.source: ml | rule-based
- data.recommendations: list rekomendasi dengan shape:
  - job_category
  - platform
  - project_type
  - predicted_monthly_earnings_idr

## Forum

### GET /forum/posts

Butuh token.

### POST /forum/posts

Butuh token.

Body:

- title (string)
- body (string)
- tags (array string, optional)

## Report Export

### GET /reports/transactions/export

Butuh token.

Query optional:

- month: YYYY-MM (default bulan ini)

Response:

- File CSV attachment

## Error Format

Validasi error memakai format standar Laravel:

- message
- errors (object per field)

## Contoh cURL Cepat

Login:

curl -X POST https://api-finary.my.id/api/auth/login \
 -H "Content-Type: application/json" \
 -H "Accept: application/json" \
 -d '{"email":"demo@finary.app","password":"password123"}'

Tambah transaksi:

curl -X POST https://api-finary.my.id/api/transactions \
 -H "Authorization: Bearer YOUR_TOKEN" \
 -H "Content-Type: application/json" \
 -H "Accept: application/json" \
 -d '{"type":"expense","category":"Makanan","amount":45000,"transaction_date":"2026-04-21"}'
