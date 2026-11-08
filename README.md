# InvenIQ: Inventory and Sales Intelligence Platform

InvenIQ is a web platform that helps a pharmaceutical distributor run its inventory and decide where to grow. It combines day-to-day stock control with a sales intelligence layer built on six years of real sales history (132,983 transaction lines).

## Features

**Inventory operations**
- Dashboard with headline KPIs
- Stock health, reorder points, replenishment plan, excess stock, slow movers and expiry alerts
- ABC/XYZ product classification and location performance
- Purchase-order vs goods-received reconciliation, mismatch analysis, supplier performance and payment ageing
- Product, supplier and data management, with role-based access for five user roles

**Sales intelligence** (`analytics/`)
- **Regional performance:** scores each territory on revenue, margin, growth, customer breadth and product breadth, and flags untapped potential with a peer model
- **Product categories:** predicts each product's next-year ABC-XYZ class with interpretable models, compared against simple rules of thumb with McNemar tests
- **Expansion opportunities:** scores every territory and category pair and shortlists the best, each with a plain-language reason
- **Sales action plan:** big accounts whose orders have dropped or gone quiet
- **Back-test:** checks past recommendations against what actually happened

**Machine learning and AI**
- Demand forecast from a gradient-boosting model, served by a small Flask service (`ml/`)
- Anomaly detection
- AI assistant that answers questions about the data through the Anthropic API

## Tech stack

PHP and MySQL (PDO) with vanilla JavaScript for the web app; Python (pandas, scikit-learn, Flask) for the forecasting and analytics; the Anthropic API for the assistant.

## Project structure

```
inveniq-platform/
├── pages/          # one PHP page per screen, including admin/user management
├── api/            # JSON API the pages call (index.php)
├── includes/       # config, database, auth, inventory engine, ML and AI clients, sales analytics
├── components/     # shared layout: sidebar and top bar
├── assets/         # CSS and JavaScript
├── ml/             # demand forecasting: training, prediction and Flask service
├── analytics/      # sales intelligence pipeline, tests and SQL schema
├── database.sql    # schema and demo data
└── .env.example    # settings read from environment variables
```

## Getting started

1. Install [XAMPP](https://www.apachefriends.org/) and start **Apache** and **MySQL**.
2. Copy this folder to `C:\xampp\htdocs\inveniq\`.
3. Open http://localhost/phpmyadmin, choose **Import** and import `database.sql`.
4. Optional: set the environment variables in `.env.example`. Without them, the XAMPP defaults are used.
5. Open http://localhost/inveniq/pages/login.php.

The demo accounts in `database.sql` are for local testing only: change their passwords or delete them before loading real data.

- **Demand forecasting:** see [`ml/README_ML.md`](ml/README_ML.md).
- **Sales intelligence pipeline:** see [`analytics/README_ANALYTICS.md`](analytics/README_ANALYTICS.md). It needs the distributor's sales workbook, which is not included.

## Configuration

Database, ML service and AI settings come from environment variables (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`, `ML_FLASK_URL`, `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL`). Client-specific analytics settings go in `analytics/reference/client_private.json`, which is not tracked; `client_private.example.json` shows the format.

## Data

The distributor's sales workbook, trained models and analytics outputs are company data and are not in this repository. `database.sql` contains demo data only.

## Credits

Built by Sameena Siddiqua. The demand forecasting module (`ml/`) was built by Loula Sahal Ali.
