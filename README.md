# CarbonCalc — Carbon Emission Calculator (DBMS Mini Project)

PHP 8 + MySQL web app (runs on XAMPP). Logs daily activities, converts them to kg CO₂ using a shared emission-factor table, and shows dashboards and reports.

## Setup (XAMPP)
1. Copy the `carbon_calc` folder into `C:\xampp\htdocs\`
2. Start **Apache** and **MySQL** in the XAMPP Control Panel
3. Open http://localhost/phpmyadmin → **Import** → choose `database/carbon_calc.sql` → Go
4. Open http://localhost/carbon_calc

Demo logins: `yash@demo.com / demo123` (user), `admin@demo.com / admin123` (admin)

If your MySQL root user has a password, change it in `config/db.php`.

## Database (what to show in phpMyAdmin)
| Object | Purpose |
|---|---|
| `users`, `category`, `emission_factor`, `activity_log`, `report` | 5 tables in 3NF, PK/FK, UNIQUE, CHECK, ON DELETE CASCADE |
| `trg_activity_compute` (trigger) | Computes `co2_kg` and stores `factor_value_used` on every insert |
| `v_monthly_summary` (view) | Monthly totals per user per category; feeds the dashboard charts |
| `sp_generate_report` (procedure) | Creates a report row for a date range; used by Reports page |
| `idx_log_date`, `idx_user_date` (indexes) | Fast date-range queries |

Queries to show:
```sql
SELECT * FROM v_monthly_summary WHERE user_id = 2;
CALL sp_generate_report(2, '2026-08-01', '2026-08-31');
SHOW TRIGGERS;
```

## Pages
- `index.php` / `register.php` — login and sign-up (bcrypt hashes, sessions, CSRF tokens); one-click demo accounts
- `dashboard.php` — month switcher, monthly CO₂ budget strip, pace projection, equivalents, 6-month stacked chart, biggest-saving suggestion
- `add_activity.php` — pick category → activity → amount; live result with formula, source and budget impact; "log again" shortcuts
- `history.php` — date presets, category filter, delete, CSV export; flags entries whose factor has since changed
- `reports.php` — created via stored procedure, reopen saved reports, print/PDF
- `methodology.php` — **How it works** (public): entry-to-chart flow with live data, every source with links, assumptions, live schema from `information_schema`, expo FAQ
- `admin/factors.php` — edit/disable factors, add factors and categories (admin only)
- `mongo_log.php` — MongoDB event log

## Demo data
The seed history is dated relative to `CURDATE()`, so re-importing `database/carbon_calc.sql` always gives ~6 months of data ending today.
`users.monthly_budget_kg` (default 208 kg = 2.5 t/yr ÷ 12, Hot or Cool Institute 2021) drives the budget strip.

Note: emission factors are approximate reference values; the source is stored per row. Food values for beef, chicken, rice and milk match the EWG Meat Eater's Guide (2011); vegetables match Poore & Nemecek (2018).

## MongoDB (event log)
MySQL = structured data. MongoDB = schema-less event log (`carbon_calc.activity_events`): logins, activity adds/deletes, CSV exports, reports, admin changes.
1. Install MongoDB Community Server (tick "Install MongoDB Compass"): https://www.mongodb.com/try/download/community — it runs as a Windows service on port 27017.
2. Use the app, then open **MongoDB Log** in the navbar, or Compass → `mongodb://localhost:27017` → `carbon_calc` → `activity_events`.
No PHP extension needed (`includes/mongo.php` talks to MongoDB directly). If MongoDB is off, the app still works on MySQL.
