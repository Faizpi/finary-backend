# Finary Backend API

RESTful API untuk aplikasi keuangan personal Finary, dibangun dengan Laravel 10 + Laravel Sanctum.

## Tech Stack

- **Framework**: Laravel 10
- **Auth**: Laravel Sanctum (Bearer Token)
- **Database**: SQLite (dev) / MySQL (prod)
- **ML Integration**: External Hugging Face API dengan fallback rule-based

## Setup

```bash
# 1. Clone dan install dependencies
composer install

# 2. Salin environment file
cp .env.example .env

# 3. Generate application key
php artisan key:generate

# 4. Jalankan migrasi database
php artisan migrate

# 5. (Opsional) Seed demo data
php artisan db:seed

# 6. Jalankan server
php artisan serve
```

## Environment Variables

| Variable                   | Default                                      | Keterangan                       |
| -------------------------- | -------------------------------------------- | -------------------------------- |
| `APP_URL`                  | `https://api-finary.my.id`                   | URL backend                      |
| `DB_CONNECTION`            | `sqlite`                                     | Driver database                  |
| `CORS_ALLOWED_ORIGINS`     | `http://localhost:5173,https://finary.my.id` | Domain frontend yang diizinkan   |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost:5173,localhost:3000,finary.my.id` | Domain SPA untuk Sanctum cookies |
| `ML_ENABLED`               | `true`                                       | Aktifkan ML service              |
| `ML_BASE_URL`              | Hugging Face URL                             | Base URL ML service              |
| `ML_TIMEOUT`               | `4`                                          | Timeout request ke ML (detik)    |

## API Endpoints

### Auth (Public)

| Method | Endpoint             | Keterangan       |
| ------ | -------------------- | ---------------- |
| POST   | `/api/auth/register` | Daftar akun baru |
| POST   | `/api/auth/login`    | Login            |

### Auth (Bearer Token Required)

| Method | Endpoint           | Keterangan      |
| ------ | ------------------ | --------------- |
| GET    | `/api/auth/me`     | Data user aktif |
| POST   | `/api/auth/logout` | Logout          |

### Dashboard & Insight

| Method | Endpoint                    | Keterangan           |
| ------ | --------------------------- | -------------------- |
| GET    | `/api/dashboard`            | Ringkasan dashboard  |
| GET    | `/api/insights/profile`     | Profil finansial     |
| GET    | `/api/insights/badges`      | Badge & achievement  |
| GET    | `/api/insights/leaderboard` | Leaderboard pengguna |

### Assessment

| Method | Endpoint                 | Keterangan             |
| ------ | ------------------------ | ---------------------- |
| GET    | `/api/assessment/latest` | Assessment terakhir    |
| POST   | `/api/assessment`        | Submit assessment baru |

### Transactions

| Method | Endpoint                 | Keterangan       |
| ------ | ------------------------ | ---------------- |
| GET    | `/api/transactions`      | Daftar transaksi |
| POST   | `/api/transactions`      | Tambah transaksi |
| PUT    | `/api/transactions/{id}` | Update transaksi |
| DELETE | `/api/transactions/{id}` | Hapus transaksi  |

### Budgets

| Method | Endpoint            | Keterangan           |
| ------ | ------------------- | -------------------- |
| GET    | `/api/budgets`      | Daftar budget        |
| POST   | `/api/budgets`      | Tambah/update budget |
| PUT    | `/api/budgets/{id}` | Update budget        |
| DELETE | `/api/budgets/{id}` | Hapus budget         |

### Recommendations

| Method | Endpoint                            | Keterangan              |
| ------ | ----------------------------------- | ----------------------- |
| POST   | `/api/recommendations/side-hustles` | Rekomendasi side hustle |

### Forum

| Method | Endpoint                        | Keterangan          |
| ------ | ------------------------------- | ------------------- |
| GET    | `/api/forum/posts`              | Daftar postingan    |
| POST   | `/api/forum/posts`              | Buat postingan baru |
| POST   | `/api/forum/posts/{id}/replies` | Balas postingan     |

### Reports

| Method | Endpoint                           | Keterangan           |
| ------ | ---------------------------------- | -------------------- |
| GET    | `/api/reports/transactions/export` | Export CSV transaksi |

## Clean Architecture

```
app/
├── Http/
│   ├── Controllers/Api/    # Request handling, response formatting
│   ├── Requests/           # Form validation (clean input validation layer)
│   │   ├── Auth/
│   │   ├── Assessment/
│   │   ├── Budget/
│   │   ├── Forum/
│   │   ├── Recommendation/
│   │   └── Transaction/
│   ├── Resources/          # API response transformation
│   └── Traits/
│       └── ApiResponse.php # Standardized response helpers
├── Models/                 # Eloquent models
└── Services/               # Business logic layer
    ├── FinancialClassifierService.php
    ├── FinancialInsightService.php
    ├── MlGatewayService.php
    └── SideHustleRecommendationService.php
```

## CORS

Untuk production, set environment variable:

```
APP_URL=https://api-finary.my.id
CORS_ALLOWED_ORIGINS=https://finary.my.id
SANCTUM_STATEFUL_DOMAINS=finary.my.id
```

## Health Check

```
GET /api/health
```

Returns: `{"status":"ok","app":"FinaryAPI"}`
